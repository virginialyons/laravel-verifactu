<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Testing;

use BcMath\Number;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What the service checks in the body of an invoice before it issues or
 * corrects it, with the error code it answers for each rule.
 *
 * @phpstan-type Error array{code: string, field: string, message: string}
 */
class InvoiceRules
{
    public const string InvalidTaxId = 'The tax ID is not a valid Spanish tax ID (NIF, DNI or NIE): its format or check letter is wrong.';

    protected const array AssignedByService = [
        'issue_date' => 'issue_date_is_assigned_by_service',
        'series' => 'series_is_assigned_by_service',
        'number' => 'number_is_assigned_by_service',
    ];

    protected const array InvoiceTypes = ['F1', 'F2', 'F3', 'R1', 'R2', 'R3', 'R4', 'R5'];

    protected const array TypesWithoutRecipient = ['F2', 'R5'];

    /**
     * An amount as the service takes it: a decimal string with a dot.
     */
    protected const string Amount = '/^-?\d{1,12}(\.\d{1,2})?$/';

    protected const array LineAmounts = ['tax_base', 'tax_amount', 'equivalence_surcharge_amount'];

    protected const string SimplifiedInvoiceLimit = '3000.00';

    /**
     * The first rule the body breaks, or null when it keeps them all.
     *
     * @param  array<mixed>  $body
     * @return Error|null
     */
    public static function firstError(array $body): ?array
    {
        foreach (self::AssignedByService as $field => $code) {
            if (array_key_exists($field, $body)) {
                return self::error($code, $field, "The service sets {$field}: remove it.");
            }
        }

        $description = $body['description'] ?? null;

        if (! is_string($description) || blank($description) || Str::length($description) > 500) {
            return self::error('invalid_description', 'description', 'The description is missing, too long (over 500 characters) or not text.');
        }

        $withControlCharacter = Collection::make(['description' => $description, 'recipient.name' => Arr::get($body, 'recipient.name')])
            ->search(fn (mixed $text): bool => is_string($text) && Str::isMatch('/\p{Cc}/u', $text));

        if ($withControlCharacter !== false) {
            return self::error('control_character_not_allowed', $withControlCharacter, 'The text contains a control character, such as a tab or a new line, that AEAT refuses.');
        }

        if (! array_key_exists('total_amount', $body)) {
            return self::error('field_required', 'total_amount', 'The total_amount field is required.');
        }

        $lines = Collection::make(Arr::wrap($body['lines'] ?? []))->filter(fn (mixed $line): bool => is_array($line));

        $lineWithoutBase = $lines->search(fn (array $line): bool => ! array_key_exists('tax_base', $line));

        if ($lineWithoutBase !== false) {
            return self::error('field_required', "lines.{$lineWithoutBase}.tax_base", "The lines.{$lineWithoutBase}.tax_base field is required.");
        }

        $wrongAmount = $lines
            ->flatMap(fn (array $line, int $index): array => Collection::make(Arr::only($line, self::LineAmounts))
                ->mapWithKeys(fn (mixed $amount, string $field): array => ["lines.{$index}.{$field}" => $amount])
                ->all())
            ->filter(fn (mixed $amount): bool => $amount !== null)
            ->prepend($body['total_amount'], 'total_amount')
            ->search(fn (mixed $amount): bool => ! is_string($amount) || ! Str::isMatch(self::Amount, $amount));

        if ($wrongAmount !== false) {
            return self::error('invalid_amount', $wrongAmount, 'An amount is not a decimal string with a dot and at most two decimals, such as "121.00".');
        }

        $reference = $body['external_reference'] ?? null;

        if ($reference !== null && (! is_string($reference) || Str::length($reference) > 100)) {
            return self::error('invalid_external_reference', 'external_reference', 'The external_reference is not text or is longer than 100 characters.');
        }

        $type = $body['invoice_type'] ?? 'F1';

        if (! is_string($type) || ! in_array($type, self::InvoiceTypes, true)) {
            return self::error('invalid_invoice_type', 'invoice_type', 'The invoice_type is not one of the supported types (F1, F2, F3, R1, R2, R3, R4 or R5).');
        }

        $recipient = $body['recipient'] ?? null;

        if ($recipient === null && ! in_array($type, self::TypesWithoutRecipient, true)) {
            return self::error('recipient_required', 'recipient', "Invoices of type {$type} need a recipient.");
        }

        if ($recipient !== null && in_array($type, self::TypesWithoutRecipient, true)) {
            return self::error('recipient_not_allowed', 'recipient', "Invoices of type {$type} have no recipient.");
        }

        if ($recipient !== null && self::isInvalidName(Arr::get($body, 'recipient.name'))) {
            return self::error('invalid_recipient_name', 'recipient.name', 'The recipient\'s name is missing, not text or longer than 120 characters.');
        }

        $total = Arr::string($body, 'total_amount');

        if ($type === 'F2' && is_numeric($total) && (new Number($total))->compare(self::SimplifiedInvoiceLimit) > 0) {
            return self::error('simplified_invoice_over_limit', 'total_amount', 'A simplified invoice (F2) cannot exceed 3,000.00.');
        }

        $taxId = Arr::get($body, 'recipient.tax_id');

        if (is_string($taxId) && SpanishTaxId::isInvalid($taxId)) {
            return self::error('invalid_tax_id', 'recipient.tax_id', self::InvalidTaxId);
        }

        return null;
    }

    /**
     * A recipient's name, or a tax-ID check's: text of 1 to 120 characters.
     */
    public static function isInvalidName(mixed $name): bool
    {
        return ! is_string($name) || blank($name) || Str::length($name) > 120;
    }

    /**
     * @return Error
     */
    protected static function error(string $code, string $field, string $message): array
    {
        return ['code' => $code, 'field' => $field, 'message' => $message];
    }
}
