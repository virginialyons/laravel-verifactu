<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Jobs\Concerns;

use DateTimeInterface;
use Vlec\Verifactu\Exceptions\VerifactuException;

/**
 * A job that calls the client with its automatic retries off and retries
 * through the queue instead, so a slow service never holds a worker.
 */
trait RetriesThroughTheQueue
{
    /**
     * Any exception the job does not release fails it at once.
     */
    public int $maxExceptions = 1;

    /**
     * Seconds to wait before the next attempt, when the service gives no Retry-After.
     *
     * @var list<int>
     */
    public array $releaseDelays = [10, 30, 60, 300];

    /**
     * The service replays an Idempotency-Key for 24 hours.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addDay();
    }

    /**
     * Releases the job after an answer worth trying again, such as a 503,
     * and rethrows any other, which fails the job.
     */
    protected function releaseOrFail(VerifactuException $exception): void
    {
        if (! $exception->isRetryable()) {
            throw $exception;
        }

        $this->release($exception->retryAfter ?? $this->releaseDelay());
    }

    /**
     * The wait before the next attempt, longer after each one.
     */
    protected function releaseDelay(): int
    {
        return $this->releaseDelays[min($this->attempts(), count($this->releaseDelays)) - 1];
    }
}
