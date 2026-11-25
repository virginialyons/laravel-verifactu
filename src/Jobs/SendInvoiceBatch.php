<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;
use Vlec\Verifactu\Client;
use Vlec\Verifactu\Contracts\VerifactuInvoice;
use Vlec\Verifactu\Events\InvoiceNotIssued;
use Vlec\Verifactu\Exceptions\VerifactuException;
use Vlec\Verifactu\InvoiceBatchStatus;
use Vlec\Verifactu\Jobs\Concerns\RetriesThroughTheQueue;
use Vlec\Verifactu\Models\InvoiceBatch;

/**
 * Sends invoices to the service as one batch and keeps the batch, to be
 * applied when it completes.
 */
class SendInvoiceBatch implements ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable;
    use RetriesThroughTheQueue;

    /**
     * One key per send, kept across this job's retries, as for a single
     * invoice: a later send of the same invoices is really sent again, and
     * the service's unique external_reference stops a second invoice.
     */
    public string $idempotencyKey;

    /**
     * @param  Collection<int, Model&VerifactuInvoice>  $invoices
     */
    public function __construct(public Collection $invoices)
    {
        $this->idempotencyKey = 'batch:'.Str::uuid();
    }

    public function handle(Client $client): void
    {
        $batch = ['invoices' => $this->invoices->map(fn (Model&VerifactuInvoice $invoice): array => $invoice->toVerifactuRequest())->values()->all()];

        try {
            $answer = $client->withoutRetries()->createInvoiceBatch($batch, $this->idempotencyKey);
        } catch (VerifactuException $exception) {
            $this->releaseOrFail($exception);

            return;
        }

        try {
            // Queued until applied, whatever the service first answered: applying it sets the final status.
            InvoiceBatch::firstOrCreate(['uuid' => $answer['uuid']], ['status' => InvoiceBatchStatus::Queued]);
        } catch (QueryException $exception) {
            // The service took the batch: the retry's same key replays its answer, to store it then.
            report($exception);

            $this->release($this->releaseDelay());
        }
    }

    /**
     * The service refused the batch, or never answered within the retries:
     * none of its invoices was issued by it. One issued on its own since is
     * not reported.
     */
    public function failed(?Throwable $exception): void
    {
        $errorCode = null;

        if ($exception instanceof VerifactuException) {
            $errorCode = $exception->errorCode;
        }

        foreach ($this->invoices as $invoice) {
            if ($invoice->isOnVerifactu()) {
                continue;
            }

            event(new InvoiceNotIssued($invoice, $errorCode, $exception?->getMessage() ?? 'The job failed.'));
        }
    }
}
