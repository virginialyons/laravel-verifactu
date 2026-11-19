<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Vlec\Verifactu\Contracts\VerifactuInvoice;

/**
 * The service refused the invoice, or never answered before the job gave
 * up. Nothing was issued: fix the sale and send it again.
 */
class InvoiceNotIssued
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  ?string  $errorCode  The service's error code, such as recipient_not_identified; null when it never answered.
     */
    public function __construct(
        public Model&VerifactuInvoice $invoice,
        public ?string $errorCode,
        public string $message,
    ) {}
}
