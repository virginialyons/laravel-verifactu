<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Vlec\Verifactu\Contracts\VerifactuInvoice;
use Vlec\Verifactu\Jobs\RefreshInvoice;

/**
 * Receives the service's signed deliveries. It reads nothing from an
 * invoice in the payload but its uuid: each known invoice is read again
 * from the service, so deliveries arriving late or out of order do no harm.
 */
class WebhookController
{
    public function __invoke(Request $request): Response
    {
        // json() decodes the body whatever its Content-Type says.
        if ($request->json('event') === 'invoices.updated') {
            $this->refreshInvoices(collect($request->json()->all('data'))->pluck('uuid')->all());
        }

        return new Response;
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
