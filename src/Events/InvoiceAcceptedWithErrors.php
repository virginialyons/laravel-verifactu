<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Vlec\Verifactu\Contracts\VerifactuInvoice;

/**
 * AEAT registered the invoice with errors: it counts, but needs a fix with correctOnVerifactu().
 */
class InvoiceAcceptedWithErrors
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  ?string  $errorCode  AEAT's error code, such as 1105.
     * @param  ?string  $errorMessage  AEAT's error message, in Spanish.
     */
    public function __construct(
        public Model&VerifactuInvoice $invoice,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {}
}
