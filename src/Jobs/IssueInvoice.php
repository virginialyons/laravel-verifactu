<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;
use Vlec\Verifactu\Client;
use Vlec\Verifactu\Contracts\VerifactuInvoice;
use Vlec\Verifactu\Events\InvoiceIssued;
use Vlec\Verifactu\Events\InvoiceNotIssued;
use Vlec\Verifactu\Exceptions\VerifactuException;
use Vlec\Verifactu\Jobs\Concerns\RetriesThroughTheQueue;

/**
 * Issues one invoice. Each attempt sends one request with the same
 * Idempotency-Key, so a retry never issues the invoice twice.
 */
class IssueInvoice implements ShouldBeUnique, ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable;
    use RetriesThroughTheQueue;

    /**
     * The lock against a second job for the invoice outlasts the 24 hours
     * of retries; a job lost while holding it never blocks the invoice.
     */
    public int $uniqueFor = 90000;

    /**
     * One key per send, kept across this job's retries. A later send gets a
     * new key, so a refusal fixed since is really sent again; the service's
     * unique external_reference still stops a second invoice for the sale.
     */
    public string $idempotencyKey;

    public function __construct(public Model&VerifactuInvoice $invoice)
    {
        $this->idempotencyKey = $invoice->verifactuReference().':'.Str::uuid();

        $queue = config('verifactu.queue');

        if (is_string($queue)) {
            $this->onQueue($queue);
        }
    }

    public function uniqueId(): string
    {
        return $this->invoice->verifactuReference();
    }

    public function handle(Client $client): void
    {
        if ($this->invoice->isOnVerifactu()) {
            return;
        }

        try {
            $invoice = $this->issue($client->withoutRetries());
        } catch (VerifactuException $exception) {
            $this->releaseOrFail($exception);

            return;
        }

        $this->invoice->storeVerifactuInvoice($invoice);

        InvoiceIssued::dispatch($this->invoice);
    }

    public function failed(?Throwable $exception): void
    {
        // Something after the answer failed, such as an InvoiceIssued listener: the invoice was issued.
        if ($this->invoice->isOnVerifactu()) {
            return;
        }

        $errorCode = null;

        if ($exception instanceof VerifactuException) {
            $errorCode = $exception->errorCode;
        }

        InvoiceNotIssued::dispatch($this->invoice, $errorCode, $exception?->getMessage() ?? 'The job failed.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function issue(Client $client): array
    {
        try {
            return $client->createInvoice($this->invoice->toVerifactuRequest(), $this->idempotencyKey);
        } catch (VerifactuException $exception) {
            if ($exception->errorCode !== 'external_reference_already_used') {
                throw $exception;
            }
        }

        // Issued before, by an attempt whose answer was lost: read it back.
        /** @var array{data: list<array{uuid: string}>} $found */
        $found = $client->listInvoices(['filter' => ['external_reference' => $this->invoice->verifactuReference()]]);

        return $client->getInvoice($found['data'][0]['uuid']);
    }
}
