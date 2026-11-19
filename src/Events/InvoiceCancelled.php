<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Vlec\Verifactu\Contracts\VerifactuInvoice;

/**
 * AEAT registered the invoice's cancellation.
 */
class InvoiceCancelled
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Model&VerifactuInvoice $invoice) {}
}
