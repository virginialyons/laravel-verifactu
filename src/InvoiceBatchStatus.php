<?php

declare(strict_types=1);

namespace Vlec\Verifactu;

/**
 * Where a batch is on the service.
 */
enum InvoiceBatchStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';

    /**
     * Whether every item has a result: issued, or failed with its error.
     */
    public function isComplete(): bool
    {
        return $this === self::Completed || $this === self::CompletedWithErrors;
    }

    public function isNotComplete(): bool
    {
        return ! $this->isComplete();
    }
}
