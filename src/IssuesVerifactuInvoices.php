<?php

declare(strict_types=1);

namespace Vlec\Verifactu;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use LogicException;
use Vlec\Verifactu\Contracts\VerifactuInvoice;
use Vlec\Verifactu\Events\InvoiceAccepted;
use Vlec\Verifactu\Events\InvoiceAcceptedWithErrors;
use Vlec\Verifactu\Events\InvoiceCancelled;
use Vlec\Verifactu\Events\InvoiceRejected;
use Vlec\Verifactu\Facades\Verifactu;
use Vlec\Verifactu\Jobs\IssueInvoice;

/**
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements VerifactuInvoice
 */
trait IssuesVerifactuInvoices
{
    public function initializeIssuesVerifactuInvoices(): void
    {
        $this->mergeCasts([static::verifactuColumn('status') => InvoiceStatus::class]);

        if (! $this->hasCast(static::verifactuColumn('issue_date'))) {
            $this->mergeCasts([static::verifactuColumn('issue_date') => 'date']);
        }
    }

    /**
     * Issues the invoice on the service from a queued job, once the
     * database transaction around this call commits.
     */
    public function sendToVerifactu(): void
    {
        dispatch(new IssueInvoice($this));
    }

    /**
     * Reads the invoice from the service and stores it.
     */
    public function refreshFromVerifactu(): void
    {
        $this->storeVerifactuInvoice(Verifactu::getInvoice($this->verifactuUuid()));
    }

    /**
     * Sends AEAT a new record of the invoice, with a new description or,
     * without one, unchanged after a technical rejection. Call it after the
     * database transaction commits.
     */
    public function correctOnVerifactu(?string $description = null): void
    {
        $correction = $this->toVerifactuRequest();

        if ($description !== null) {
            $correction['description'] = $description;
        }

        $this->storeVerifactuInvoice(Verifactu::correctInvoice($this->verifactuUuid(), $correction));
    }

    /**
     * Cancels at AEAT an invoice issued by mistake. Call it after the
     * database transaction commits.
     */
    public function cancelOnVerifactu(): void
    {
        $this->storeVerifactuInvoice(Verifactu::cancelInvoice($this->verifactuUuid()));
    }

    public function isOnVerifactu(): bool
    {
        return $this->getAttribute(static::verifactuColumn('uuid')) !== null;
    }

    public function verifactuReference(): string
    {
        $key = $this->getKey();

        throw_unless(is_int($key) || is_string($key), LogicException::class, 'Save the invoice before sending it to Verifactu.');

        return "invoice:$key";
    }

    /**
     * @return array<string, mixed>
     */
    public function toVerifactuRequest(): array
    {
        return [...$this->toVerifactu()->toArray(), 'external_reference' => $this->verifactuReference()];
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    public function storeVerifactuInvoice(array $invoice): void
    {
        $previousStatus = $this->getAttribute(static::verifactuColumn('status'));

        // A field the answer leaves out, such as the QR code in a webhook's list, keeps its column's value.
        $attributes = Arr::mapWithKeys(
            Arr::only($invoice, ['uuid', 'status', 'invoice_number', 'number', 'issue_date', 'verification_url', 'qr_code']),
            fn (mixed $value, string $field): array => [static::verifactuColumn($field) => $value],
        );

        $this->forceFill($attributes)->save();

        $status = $this->getAttribute(static::verifactuColumn('status'));

        if ($status === $previousStatus) {
            return;
        }

        /** @var array{error_code?: ?string, error_message?: ?string} $aeat */
        $aeat = $invoice['aeat'] ?? [];

        match ($status) {
            InvoiceStatus::Accepted => event(new InvoiceAccepted($this)),
            InvoiceStatus::AcceptedWithErrors => event(new InvoiceAcceptedWithErrors($this, $aeat['error_code'] ?? null, $aeat['error_message'] ?? null)),
            InvoiceStatus::Rejected => event(new InvoiceRejected($this, $aeat['error_code'] ?? null, $aeat['error_message'] ?? null)),
            InvoiceStatus::Cancelled => event(new InvoiceCancelled($this)),
            default => null,
        };
    }

    public function verifactuUuid(): string
    {
        $uuid = $this->getAttribute(static::verifactuColumn('uuid'));

        throw_unless(is_string($uuid), LogicException::class, 'The invoice is not on Verifactu yet: send it with sendToVerifactu() first.');

        return $uuid;
    }

    /**
     * The model's column for one of the service's fields, from config('verifactu.columns').
     */
    protected static function verifactuColumn(string $field): string
    {
        return config()->string("verifactu.columns.$field");
    }
}
