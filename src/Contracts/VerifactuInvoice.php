<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Contracts;

use Vlec\Verifactu\InvoiceData;

interface VerifactuInvoice
{
    /**
     * Describes the sale: what was sold, to whom, and its tax lines.
     */
    public function toVerifactu(): InvoiceData;
}
