<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Vlec\Verifactu\Contracts\VerifactuInvoice;
use Vlec\Verifactu\Jobs\ApplyInvoiceBatch;
use Vlec\Verifactu\Jobs\RefreshInvoice;
use Vlec\Verifactu\Models\InvoiceBatch;

/**
 * Receives the service's signed deliveries. It reads nothing from the
 * payload but uuids: each known invoice or batch is read again from the
 * service, so deliveries arriving late or out of order do no harm.
 */
class WebhookController
{
    public function __invoke(Request $request): Response
    {
        // json() decodes the body whatever its Content-Type says.
        if ($request->json('event') === 'invoices.updated') {
            $this->refreshInvoices(collect($request->json()->all('data'))->pluck('uuid')->all());
        }

        if ($request->json('event') === 'invoice_batch.completed') {
            $this->applyBatch($request->json('data.uuid'));
        }

        return new Response;
    }

    /**
     * Queues the batch's application, once; a batch of another app or
     * environment is not in the table and is skipped.
     */
    protected function applyBatch(mixed $uuid): void
    {
        $batch = InvoiceBatch::firstWhere('uuid', $uuid);

        if ($batch === null || $batch->isApplied()) {
            return;
        }

        ApplyInvoiceBatch::dispatch($batch);
    }

    /**
     * Queues a refresh for each of the app's invoices among them; invoices of
     * another app or environment are not in its table and are skipped.
     *
     * @param  array<mixed>  $uuids
     */
    protected function refreshInvoices(array $uuids): void
    {
        /** @var class-string<Model&VerifactuInvoice> $model */
        $model = config()->string('verifactu.model');

        $model::query()
            ->whereIn(config()->string('verifactu.columns.uuid'), $uuids)
            ->get()
            ->each(fn (Model&VerifactuInvoice $invoice) => RefreshInvoice::dispatch($invoice));
    }
}
