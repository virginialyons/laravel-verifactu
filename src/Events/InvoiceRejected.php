<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Vlec\Verifactu\Contracts\VerifactuInvoice;

/**
 * AEAT rejected the invoice. Fix it with correctOnVerifactu(), or issue it again.
 */
class InvoiceRejected
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
