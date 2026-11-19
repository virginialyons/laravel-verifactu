<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Vlec\Verifactu\Models\InvoiceBatch;

/**
 * Every invoice of the batch has a result, and each was applied: an issued
 * one fired InvoiceIssued, a failed one InvoiceNotIssued.
 */
class InvoiceBatchCompleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public InvoiceBatch $batch,
        public int $total,
        public int $succeeded,
        public int $failed,
    ) {}
}
