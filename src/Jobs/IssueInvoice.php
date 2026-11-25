<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
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
     * Refusals that can mean the invoice was issued by an attempt whose
     * answer was lost: the sale's reference is taken, or the invoice changed
     * since and its key no longer fits. Either way, it is read back.
     */
    protected const array IssuedBeforeCodes = ['external_reference_already_used', 'idempotency_key_reused'];

    /**
     * One key per send, kept across this job's retries. A later send gets a
     * new key, so a refusal fixed since is really sent again; the service's
     * unique external_reference still stops a second invoice for the sale.
     */
    public string $idempotencyKey;

    public function __construct(public Model&VerifactuInvoice $invoice)
    {
        $this->idempotencyKey = $invoice->verifactuReference().':'.Str::uuid();
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

        try {
            $this->invoice->storeVerifactuInvoice($invoice);
        } catch (QueryException $exception) {
            // Saved, then a status listener failed: not the save's failure to retry.
            throw_if($this->invoice->isClean(), $exception);

            // The service issued it: the retry's same key replays the answer, to store it then.
            report($exception);

            $this->release($this->releaseDelay());

            return;
        }

        event(new InvoiceIssued($this->invoice));
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

        event(new InvoiceNotIssued($this->invoice, $errorCode, $exception?->getMessage() ?? 'The job failed.'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function issue(Client $client): array
    {
        try {
            return $client->createInvoice($this->invoice->toVerifactuRequest(), $this->idempotencyKey);
        } catch (VerifactuException $exception) {
            throw_if(Collection::make(self::IssuedBeforeCodes)->doesntContainStrict($exception->errorCode), $exception);

            $found = $client->listInvoices(['filter' => ['external_reference' => $this->invoice->verifactuReference()]]);

            // The service holds no invoice for the sale: the refusal stands.
            throw_if(blank(Arr::get($found, 'data')), $exception);

            return $client->getInvoice(Arr::string($found, 'data.0.uuid'));
        }
    }
}
