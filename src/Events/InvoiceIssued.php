<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Vlec\Verifactu\Contracts\VerifactuInvoice;

/**
 * The service issued the invoice: its number, date and QR code are stored.
 */
class InvoiceIssued
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Model&VerifactuInvoice $invoice) {}
}
