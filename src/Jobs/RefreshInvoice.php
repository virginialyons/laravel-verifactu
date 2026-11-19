<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Jobs;

use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Vlec\Verifactu\Client;
use Vlec\Verifactu\Contracts\VerifactuInvoice;
use Vlec\Verifactu\Exceptions\VerifactuException;

/**
 * Reads an invoice from the service and stores it. Unique only while
 * queued: a webhook that arrives while it runs queues a fresh read, and
 * that read waits for this one to finish, so the last read always wins.
 */
class RefreshInvoice implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /**
     * Any exception the job does not release fails it at once.
     */
    public int $maxExceptions = 1;

    /**
     * A lock left by a job that was lost while queued expires within the hour.
     */
    public int $uniqueFor = 3600;

    /**
     * Seconds to wait before the next attempt, when the service gives no Retry-After.
     *
     * @var list<int>
     */
    public array $releaseDelays = [10, 30, 60, 300];

    public function __construct(public Model&VerifactuInvoice $invoice)
    {
        $queue = config('verifactu.queue');

        if (is_string($queue)) {
            $this->onQueue($queue);
        }
    }

    public function uniqueId(): string
    {
        return $this->invoice->verifactuReference();
    }

    /**
     * One read at a time per invoice, even on several workers, so an older
     * read never saves over a newer one. The lock outlasts a read's timeouts.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->invoice->verifactuReference()))->releaseAfter(5)->expireAfter(60)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addDay();
    }

    public function handle(Client $client): void
    {
        try {
            $invoice = $client->withoutRetries()->getInvoice($this->invoice->verifactuUuid());
        } catch (VerifactuException $exception) {
            if (! $exception->isRetryable()) {
                throw $exception;
            }

            $this->release($exception->retryAfter ?? $this->releaseDelays[min($this->attempts(), count($this->releaseDelays)) - 1]);

            return;
        }

        $this->invoice->storeVerifactuInvoice($invoice);
    }
}
