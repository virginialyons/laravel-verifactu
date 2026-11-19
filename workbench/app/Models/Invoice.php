<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
 */
class Invoice extends Model implements VerifactuInvoice
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    use IssuesVerifactuInvoices;

    public function toVerifactu(): InvoiceData
    {
        $lines = [];

        if ($this->exempt_cents !== 0) {
            $lines[] = TaxLine::exempt($this->exempt_cents);
        }

        if ($this->taxable_cents !== 0) {
            $lines[] = TaxLine::vat($this->taxable_cents, rate: 21);
        }

        $data = InvoiceData::make("Order {$this->id}")->lines($lines);

        if ($this->is_simplified) {
            return $data->simplified();
        }

        return $data->to(Recipient::spanish((string) $this->customer_tax_id, (string) $this->customer_name));
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
