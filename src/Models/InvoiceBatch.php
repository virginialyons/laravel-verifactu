<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Vlec\Verifactu\InvoiceBatchStatus;

/**
 * A batch sent to the service. It stays queued until its items are applied
 * to the app's invoices, so a batch whose completion webhook was missed, or
 * came before the batch was stored, can be found and applied later.
 *
 * @property string $uuid
 * @property InvoiceBatchStatus $status
 */
#[Fillable([
    'uuid',
    'status',
])]
#[Table(name: 'verifactu_invoice_batches')]
class InvoiceBatch extends Model
{
    public function isApplied(): bool
    {
        return $this->status->isComplete();
    }

    protected function casts(): array
    {
        return [
            'status' => InvoiceBatchStatus::class,
        ];
    }
}
