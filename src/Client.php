<?php

declare(strict_types=1);

namespace Vlec\Verifactu;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;
use Vlec\Verifactu\Exceptions\VerifactuException;

class Client
{
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

        if (! is_string($url) || ! Str::startsWith($url, ['https://', 'http://'])) {
            throw new InvalidArgumentException('Set VERIFACTU_URL to the Verifactu service\'s host, such as https://verifactu.example.com.');
        }

        $token = config('verifactu.token');

        if (! is_string($token) || blank($token)) {
            throw new InvalidArgumentException('Set VERIFACTU_TOKEN to the app\'s Verifactu token.');
        }

        $url = Str::finish($url, '/');

        return Http::baseUrl("{$url}api/v1")
            ->withToken($token)
            ->acceptJson()
            ->connectTimeout(config()->integer('verifactu.connect_timeout'))
            ->timeout(config()->integer('verifactu.timeout'))
            ->retry(
                times: config()->integer('verifactu.retries') + 1,
                sleepMilliseconds: $this->retryDelay(...),
                when: $this->shouldRetry(...),
                throw: false,
            );
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

        if ($response->failed()) {
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

    protected function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException) {
            return VerifactuException::fromResponse($exception->response)->isRetryable();
        }

        return false;
    }

    /**
     * Waits the Retry-After seconds the service asks for, or one second.
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
