<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Vlec\Verifactu\Contracts\VerifactuInvoice;
use Vlec\Verifactu\InvoiceBatchStatus;
use Vlec\Verifactu\InvoiceStatus;
use Vlec\Verifactu\Jobs\ApplyInvoiceBatch;
use Vlec\Verifactu\Jobs\RefreshInvoice;
use Vlec\Verifactu\Models\InvoiceBatch;

/**
 * Catches up on missed webhooks: scheduled hourly by the app.
 */
#[Signature('verifactu:refresh-pending')]
#[Description('Refresh the invoices pending at AEAT for over an hour, and apply the batches not applied yet')]
class RefreshPendingCommand extends Command
{
    public function handle(): int
    {
        $invoices = $this->refreshPendingInvoices();

        $batches = 0;

        // A batch stays queued until applied, even when its completion webhook came before it was stored.
        foreach (InvoiceBatch::query()->whereIn('status', [InvoiceBatchStatus::Queued, InvoiceBatchStatus::Processing])->lazyById() as $batch) {
            ApplyInvoiceBatch::dispatch($batch);

            $batches++;
        }

        $this->components->info("Found {$invoices} pending ".Str::plural('invoice', $invoices)." to refresh and {$batches} ".Str::plural('batch', $batches).' to apply.');

        return self::SUCCESS;
    }

    protected function refreshPendingInvoices(): int
    {
        /** @var class-string<Model&VerifactuInvoice> $model */
        $model = config()->string('verifactu.model');

        $query = $model::query()->where(config()->string('verifactu.columns.status'), InvoiceStatus::Pending);

        $updatedAt = $query->getModel()->getUpdatedAtColumn();

        // Without timestamps there is no telling how long an invoice has been pending.
        if ($query->getModel()->usesTimestamps() && $updatedAt !== null) {
            $query->where($updatedAt, '<', now()->subHour());
        }

        $count = 0;

        foreach ($query->lazyById() as $invoice) {
            RefreshInvoice::dispatch($invoice);

            $count++;
        }

        return $count;
    }
}
