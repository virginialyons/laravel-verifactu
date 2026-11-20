<?php

declare(strict_types=1);

namespace Vlec\Verifactu;

use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use Vlec\Verifactu\Contracts\VerifactuInvoice;

/**
 * What an invoice says, built from the app's sale. It turns into the
 * body of POST /invoices and nothing else.
 *
 * @implements Arrayable<string, mixed>
 */
class InvoiceData implements Arrayable
{
    public const int MaxTaxLines = 12;

    protected InvoiceType $type = InvoiceType::Full;

    protected ?Recipient $recipient = null;

    /** @var array<int, TaxLine> */
    protected array $lines = [];

    /** @var array<int, array{number: string, issue_date: string}> */
    protected array $references = [];

    final public function __construct(protected string $description) {}

    public static function make(string $description): static
    {
        return new static($description);
    }

    public function to(Recipient $recipient): static
    {
        $data = clone $this;
        $data->recipient = $recipient;

        return $data;
    }

    /**
     * @param  iterable<array-key, TaxLine>  $lines
     */
    public function lines(iterable $lines): static
    {
        $data = clone $this;
        $data->lines = Collection::make($lines)->values()->all();

        return $data;
    }

    /**
     * A simplified invoice (F2), with no recipient.
     */
    public function simplified(): static
    {
        $data = clone $this;
        $data->type = InvoiceType::Simplified;

        return $data;
    }

    /**
     * A full invoice (F3) that replaces simplified invoices already issued.
     */
    public function replacing(Model&VerifactuInvoice ...$simplifiedInvoices): static
    {
        $data = clone $this;
        $data->type = InvoiceType::ReplacesSimplified;
        $data->references = Collection::make($simplifiedInvoices)->values()->map($this->reference(...))->all();

        return $data;
    }

    /**
     * A corrective invoice for the difference: its lines are the change,
     * negative to lower the original. R1, or R5 for a simplified original.
     */
    public function correcting(Model&VerifactuInvoice $original): static
    {
        $data = clone $this;
        $data->type = InvoiceType::Corrective;
        $data->references = [$this->reference($original)];

        if ($original->toVerifactu()->isSimplified()) {
            $data->type = InvoiceType::CorrectiveOfSimplified;
        }

        return $data;
    }

    public function isSimplified(): bool
    {
        return $this->type === InvoiceType::Simplified;
    }

    /**
     * The lines as AEAT receives them: the lines with the same VAT rate, or
     * the same exemption reason, become one line with their bases added up.
     *
     * @return array<int, TaxLine>
     */
    public function taxLines(): array
    {
        $taxLines = Collection::make($this->lines)
            ->groupBy(fn (TaxLine $line): string => $line->groupKey())
            ->map(fn (Collection $lines): TaxLine => $lines->firstOrFail()->withBaseCents($lines->sum(fn (TaxLine $line): int => $line->baseCents)))
            ->values();

        if ($taxLines->isEmpty() || $taxLines->count() > self::MaxTaxLines) {
            throw new InvalidArgumentException('An invoice has between 1 and '.self::MaxTaxLines." tax lines; this one has {$taxLines->count()}.");
        }

        return $taxLines->all();
    }

    public function totalCents(): int
    {
        return Collection::make($this->taxLines())->sum(fn (TaxLine $line): int => $line->totalCents());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->type->takesNoRecipient() && $this->recipient !== null) {
            throw new InvalidArgumentException("An {$this->type->value} invoice has no recipient: simplified invoices (F2) and their corrective invoices (R5) never name one.");
        }

        $body = ['description' => $this->description];

        if ($this->type !== InvoiceType::Full) {
            $body['invoice_type'] = $this->type->value;
        }

        if ($this->recipient !== null) {
            $body['recipient'] = $this->recipient->toArray();
        }

        $body['lines'] = Collection::make($this->taxLines())->toArray();

        if ($this->type === InvoiceType::ReplacesSimplified) {
            $body['replaced_invoices'] = $this->references;
        }

        $body['total_amount'] = TaxLine::decimal($this->totalCents());

        if ($this->type->isCorrective()) {
            $body['correction'] = ['method' => 'difference', 'corrected_invoices' => $this->references];
        }

        return $body;
    }

    /**
     * An issued invoice as the API refers to it: its bare number and issue date.
     *
     * @return array{number: string, issue_date: string}
     */
    protected function reference(Model&VerifactuInvoice $invoice): array
    {
        $number = $invoice->getAttribute(config()->string('verifactu.columns.number'));
        $issueDate = $invoice->getAttribute(config()->string('verifactu.columns.issue_date'));

        if ($issueDate instanceof DateTimeInterface) {
            $issueDate = $issueDate->format('Y-m-d');
        }

        if (! is_string($number) || ! is_string($issueDate)) {
            throw new InvalidArgumentException('An invoice must be issued on Verifactu before it is corrected or replaced.');
        }

        return ['number' => $number, 'issue_date' => Date::parse($issueDate)->toDateString()];
    }
}
