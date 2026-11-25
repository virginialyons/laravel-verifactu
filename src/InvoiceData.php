<?php

declare(strict_types=1);

namespace Vlec\Verifactu;

use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Vlec\Verifactu\Contracts\VerifactuInvoice;

/**
 * What an invoice says, built from the app's sale. It turns into the
 * body of POST /invoices and nothing else: the service judges it.
 *
 * @implements Arrayable<string, mixed>
 */
class InvoiceData implements Arrayable
{
    protected InvoiceType $type = InvoiceType::Full;

    protected ?Recipient $recipient = null;

    /** @var array<int, TaxLine> */
    protected array $lines = [];

    /** @var array<int, array<string, string>> */
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
        return Collection::make($this->lines)
            ->groupBy(fn (TaxLine $line): string => $line->groupKey())
            ->map(fn (Collection $lines): TaxLine => $lines->firstOrFail()->withBaseCents($lines->sum(fn (TaxLine $line): int => $line->baseCents)))
            ->values()
            ->all();
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
        $body = ['description' => $this->description];

        if ($this->type !== InvoiceType::Full) {
            $body['invoice_type'] = $this->type->value;
        }

        if ($this->recipient instanceof Recipient) {
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
     * An issued invoice as the API refers to it: its bare number and issue
     * date. An invoice not issued yet has neither, and the service refuses
     * the reference.
     *
     * @return array<string, string>
     */
    protected function reference(Model&VerifactuInvoice $invoice): array
    {
        $number = $invoice->getAttribute(config()->string('verifactu.columns.number'));
        $issueDate = $invoice->getAttribute(config()->string('verifactu.columns.issue_date'));

        $reference = [];

        if (is_string($number)) {
            $reference['number'] = $number;
        }

        if (is_string($issueDate)) {
            $issueDate = Date::parse($issueDate);
        }

        if ($issueDate instanceof DateTimeInterface) {
            $reference['issue_date'] = $issueDate->format('Y-m-d');
        }

        return $reference;
    }
}
