<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vlec\Verifactu\Contracts\VerifactuInvoice;
use Vlec\Verifactu\InvoiceData;
use Vlec\Verifactu\IssuesVerifactuInvoices;
use Vlec\Verifactu\Recipient;
use Vlec\Verifactu\TaxLine;
use Workbench\Database\Factories\InvoiceFactory;

/**
 * An order with an exempt course and a book at 21% VAT, either of which may be zero.
 *
 * @property int $id
 * @property ?string $customer_tax_id
 * @property ?string $customer_name
 * @property int $exempt_cents
 * @property int $taxable_cents
 * @property bool $is_simplified
 * @property ?int $corrects_id
 * @property-read ?Invoice $corrects
 */
class Invoice extends Model implements VerifactuInvoice
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    use IssuesVerifactuInvoices;

    public function toVerifactu(): InvoiceData
    {
        $data = InvoiceData::make("Order $this->id")
            ->lines(collect([
                TaxLine::exempt($this->exempt_cents),
                TaxLine::vat($this->taxable_cents, rate: 21),
            ])->reject(fn (TaxLine $line): bool => $line->baseCents === 0));

        if ($this->is_simplified) {
            $data = $data->simplified();
        } else {
            $data = $data->to(Recipient::spanish((string) $this->customer_tax_id, (string) $this->customer_name));
        }

        // Last, so it picks R1 or R5 from the original.
        if ($this->corrects !== null) {
            $data = $data->correcting($this->corrects);
        }

        return $data;
    }

    /**
     * The invoice this one corrects for the difference.
     *
     * @return BelongsTo<self, $this>
     */
    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_id');
    }

    protected function casts(): array
    {
        return [
            'exempt_cents' => 'integer',
            'taxable_cents' => 'integer',
            'is_simplified' => 'boolean',
        ];
    }
}
