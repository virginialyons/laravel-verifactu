<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Vlec\Verifactu\Client;
use Vlec\Verifactu\Contracts\VerifactuInvoice;
use Vlec\Verifactu\Exceptions\VerifactuException;
use Vlec\Verifactu\Jobs\Concerns\RetriesThroughTheQueue;

/**
 * Reads an invoice from the service and stores it. Unique only while
 * queued: a webhook that arrives while it runs queues a fresh read, and
 * that read waits for this one to finish, so the last read always wins.
 */
class RefreshInvoice implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;
    use RetriesThroughTheQueue;

    /**
     * A lock left by a job that was lost while queued expires within the hour.
     */
    public int $uniqueFor = 3600;

    public function __construct(public Model&VerifactuInvoice $invoice) {}

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

    public function handle(Client $client): void
    {
        // Loaded before the overlap lock was taken: the read that ran meanwhile may have stored a newer status.
        $this->invoice->refresh();

        try {
            $invoice = $client->withoutRetries()->getInvoice($this->invoice->verifactuUuid());
        } catch (VerifactuException $exception) {
            $this->releaseOrFail($exception);

            return;
        }

        $this->invoice->storeVerifactuInvoice($invoice);
    }
}
