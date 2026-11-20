<?php

declare(strict_types=1);

namespace Vlec\Verifactu;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;
use Vlec\Verifactu\Contracts\VerifactuInvoice;
use Vlec\Verifactu\Exceptions\VerifactuException;
use Vlec\Verifactu\Jobs\SendInvoiceBatch;

class Client
{
    /**
     * The longest Retry-After, in seconds, a call waits out before retrying.
     * A longer wait is the caller's to make, as with Forge's SDK: the call
     * throws at once, with the seconds in the exception's retryAfter.
     */
    protected const int LongestWait = 5;

    protected bool $retries = true;

    /**
     * A copy of the client that sends each request once, for queued jobs,
     * which retry through the queue instead.
     */
    public function withoutRetries(): static
    {
        $client = clone $this;
        $client->retries = false;

        return $client;
    }

    /**
     * The environment the token works in, or null without a valid token.
     */
    public function environment(): ?Environment
    {
        $token = config('verifactu.token');

        if (! is_string($token)) {
            return null;
        }

        return Environment::fromToken($token);
    }

    /**
     * The service's API, with the token, timeouts and automatic retries,
     * for the endpoints this client has no method for.
     */
    public function http(): PendingRequest
    {
        $url = config('verifactu.url');

        // Over plain HTTP the token would travel in clear.
        if (! is_string($url) || ! Str::startsWith($url, 'https://')) {
            throw new InvalidArgumentException('Set VERIFACTU_URL to the Verifactu service\'s HTTPS host, such as https://verifactu.example.com.');
        }

        $token = config('verifactu.token');

        if (! is_string($token) || blank($token)) {
            throw new InvalidArgumentException('Set VERIFACTU_TOKEN to the app\'s Verifactu token.');
        }

        $url = Str::finish($url, '/');

        return Http::baseUrl("{$url}api/v1")
            ->withToken($token)
            ->acceptJson()
            ->withoutRedirecting()
            ->connectTimeout(config()->integer('verifactu.connect_timeout'))
            ->timeout(config()->integer('verifactu.timeout'))
            ->retry(
                times: $this->attempts(),
                sleepMilliseconds: $this->retryDelay(...),
                when: $this->shouldRetry(...),
                throw: false,
            );
    }

    /**
     * Issues the invoices as one batch from a queued job, once the database
     * transaction around this call commits. Invoices already on Verifactu
     * are left out. Each one fires InvoiceIssued or InvoiceNotIssued when
     * the batch completes.
     *
     * @param  iterable<int, Model&VerifactuInvoice>  $invoices
     */
    public function sendBatch(iterable $invoices): void
    {
        // The same invoice twice would have the whole batch refused for a repeated reference.
        $invoices = Collection::make($invoices)
            ->unique(fn (Model&VerifactuInvoice $invoice): string => $invoice->verifactuReference())
            ->reject(fn (Model&VerifactuInvoice $invoice): bool => $invoice->isOnVerifactu())
            ->values();

        if ($invoices->isEmpty()) {
            return;
        }

        SendInvoiceBatch::dispatch($invoices);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function listInvoices(array $query = []): array
    {
        return $this->send('GET', 'invoices', ['query' => $query]);
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @return array<string, mixed>
     */
    public function createInvoice(array $invoice, ?string $idempotencyKey = null): array
    {
        return $this->post('invoices', $idempotencyKey, $invoice);
    }

    /**
     * @return array<string, mixed>
     */
    public function getInvoice(string $uuid): array
    {
        return $this->data($this->send('GET', "invoices/{$uuid}"));
    }

    /**
     * @param  array<string, mixed>  $correction
     * @return array<string, mixed>
     */
    public function correctInvoice(string $uuid, array $correction, ?string $idempotencyKey = null): array
    {
        return $this->post("invoices/{$uuid}/corrections", $idempotencyKey, $correction);
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelInvoice(string $uuid, ?string $idempotencyKey = null): array
    {
        return $this->post("invoices/{$uuid}/cancellation", $idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $batch
     * @return array<string, mixed>
     */
    public function createInvoiceBatch(array $batch, ?string $idempotencyKey = null): array
    {
        return $this->post('invoice-batches', $idempotencyKey, $batch);
    }

    /**
     * @return array<string, mixed>
     */
    public function getInvoiceBatch(string $uuid): array
    {
        return $this->data($this->send('GET', "invoice-batches/{$uuid}"));
    }

    /**
     * @return array<string, mixed>
     */
    public function listInvoiceBatchItems(string $uuid, int $page = 1): array
    {
        return $this->send('GET', "invoice-batches/{$uuid}/items", ['query' => ['page' => $page]]);
    }

    /**
     * Asks AEAT whether it knows the Spanish tax ID under this name.
     */
    public function checkTaxId(string $taxId, string $name): TaxIdCheck
    {
        /** @var array{result: string, official_name: string|null} $check */
        $check = $this->data($this->send('POST', 'tax-id-checks', [
            'json' => ['tax_id' => $taxId, 'name' => $name],
        ]));

        $result = TaxIdCheckResult::tryFrom($check['result']);

        if ($result === null) {
            // An answer, not an outage: status 200 makes it non-retryable, so KnownToAeat reports it.
            throw new VerifactuException("The service answered an unknown tax-ID check result: {$check['result']}.", status: 200);
        }

        return new TaxIdCheck($result, $check['official_name']);
    }

    /**
     * Sends a POST with an Idempotency-Key, a new UUID unless one is given,
     * so the automatic retries never act twice.
     *
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    protected function post(string $path, ?string $idempotencyKey, ?array $body = null): array
    {
        $options = [
            'headers' => ['Idempotency-Key' => $idempotencyKey ?? (string) Str::uuid()],
        ];

        if ($body !== null) {
            $options['json'] = $body;
        }

        return $this->data($this->send('POST', $path, $options));
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function send(string $method, string $path, array $options = []): array
    {
        try {
            $response = $this->http()->send($method, $path, $options);
        } catch (ConnectionException $exception) {
            throw VerifactuException::fromConnection($exception);
        }

        // A redirect, or a 2xx that isn't the API's JSON (a proxy's page), is no answer to trust.
        if (! $response->successful() || ! is_array($response->json('data'))) {
            throw VerifactuException::fromResponse($response);
        }

        /** @var array<string, mixed> */
        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function data(array $body): array
    {
        /** @var array<string, mixed> */
        return $body['data'];
    }

    protected function attempts(): int
    {
        if (! $this->retries) {
            return 1;
        }

        return config()->integer('verifactu.retries') + 1;
    }

    /**
     * Null for an answer that is neither a success nor an error, such as a redirect: never retried.
     */
    protected function shouldRetry(?Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException) {
            $answer = VerifactuException::fromResponse($exception->response);

            if ($answer->isNotRetryable()) {
                return false;
            }

            return $answer->retryAfter === null || $answer->retryAfter <= self::LongestWait;
        }

        return false;
    }

    /**
     * Waits the Retry-After seconds the service asks for, at most LongestWait, or one second.
     */
    protected function retryDelay(int $attempt, mixed $exception): int
    {
        if ($exception instanceof RequestException) {
            $retryAfter = VerifactuException::fromResponse($exception->response)->retryAfter;

            if ($retryAfter !== null) {
                return $retryAfter * 1000;
            }
        }

        return 1000;
    }
}
