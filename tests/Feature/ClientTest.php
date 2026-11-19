<?php

declare(strict_types=1);

use Carbon\CarbonInterval;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Vlec\Verifactu\Exceptions\VerifactuException;
use Vlec\Verifactu\Facades\Verifactu;

$invoice = ['description' => 'Order 1', 'total_amount' => '12.10'];

it('sends each operation to its endpoint with the token', function (Closure $call, string $method, string $url, ?array $body): void {
    Http::fake(['verifactu.test/api/v1/*' => Http::response(['data' => ['uuid' => 'answer']])]);

    $call();

    Http::assertSent(function (Request $request) use ($method, $url, $body): bool {
        expect($request->method())->toBe($method)
            ->and(urldecode($request->url()))->toBe("https://verifactu.test/api/v1/{$url}")
            ->and($request->header('Authorization'))->toBe(['Bearer vf_test_token-for-tests'])
            ->and($request->header('Accept'))->toBe(['application/json']);

        if ($method === 'GET') {
            return true;
        }

        expect($request->hasHeader('Idempotency-Key'))->toBeTrue();

        if ($body === null) {
            expect($request->body())->toBe('');

            return true;
        }

        expect($request->data())->toBe($body);

        return true;
    });
})->with([
    'listInvoices' => [fn () => Verifactu::listInvoices(['filter' => ['external_reference' => 'invoice:1,invoice:2']]), 'GET', 'invoices?filter[external_reference]=invoice:1,invoice:2', null],
    'createInvoice' => [fn () => Verifactu::createInvoice($invoice), 'POST', 'invoices', $invoice],
    'getInvoice' => [fn () => Verifactu::getInvoice('uuid-1'), 'GET', 'invoices/uuid-1', null],
    'correctInvoice' => [fn () => Verifactu::correctInvoice('uuid-1', $invoice), 'POST', 'invoices/uuid-1/corrections', $invoice],
    'cancelInvoice' => [fn () => Verifactu::cancelInvoice('uuid-1'), 'POST', 'invoices/uuid-1/cancellation', null],
    'createInvoiceBatch' => [fn () => Verifactu::createInvoiceBatch(['invoices' => [$invoice]]), 'POST', 'invoice-batches', ['invoices' => [$invoice]]],
    'getInvoiceBatch' => [fn () => Verifactu::getInvoiceBatch('uuid-1'), 'GET', 'invoice-batches/uuid-1', null],
    'listInvoiceBatchItems' => [fn () => Verifactu::listInvoiceBatchItems('uuid-1', page: 2), 'GET', 'invoice-batches/uuid-1/items?page=2', null],
]);

it('returns the resource of a single answer and the whole page of a list', function (): void {
    Http::fake([
        'verifactu.test/api/v1/invoices/uuid-1' => Http::response(['data' => ['uuid' => 'uuid-1']]),
        'verifactu.test/api/v1/invoices' => Http::response(['data' => [['uuid' => 'uuid-1']], 'meta' => ['total' => 1]]),
    ]);

    expect(Verifactu::getInvoice('uuid-1'))->toBe(['uuid' => 'uuid-1'])
        ->and(Verifactu::listInvoices())->toBe(['data' => [['uuid' => 'uuid-1']], 'meta' => ['total' => 1]]);
});

it('sends the Idempotency-Key it is given', function (): void {
    Http::fake(['verifactu.test/api/v1/invoices/uuid-1/cancellation' => Http::response(['data' => []], 201)]);

    Verifactu::cancelInvoice('uuid-1', idempotencyKey: 'cancel-uuid-1');

    Http::assertSent(fn (Request $request): bool => $request->header('Idempotency-Key') === ['cancel-uuid-1']);
});

it('retries a :dataset with the same Idempotency-Key', function (Closure $failure, int $waitSeconds): void {
    Sleep::fake();
    Http::fake([
        'verifactu.test/api/v1/invoices' => $failure(Http::sequence())->push(['data' => ['uuid' => 'uuid-1']], 201),
    ]);

    $answer = Verifactu::createInvoice(['description' => 'Order 1']);

    $keys = Http::recorded()->map(fn (array $pair): array => $pair[0]->header('Idempotency-Key'))->unique();

    expect($answer)->toBe(['uuid' => 'uuid-1'])
        ->and($keys)->toHaveCount(1)
        ->and(Str::isUuid($keys->first()[0]))->toBeTrue();
    Http::assertSentCount(2);
    Sleep::assertSlept(fn (CarbonInterval $duration): bool => $duration->totalSeconds === (float) $waitSeconds);
})->with([
    'connection error' => [fn (ResponseSequence $sequence) => $sequence->pushFailedConnection(), 1],
    '500' => [fn (ResponseSequence $sequence) => $sequence->push(['message' => 'Server error.', 'code' => 'record_generation_failed'], 500), 1],
    '503 with Retry-After' => [fn (ResponseSequence $sequence) => $sequence->push(['message' => 'Busy.', 'code' => 'service_busy'], 503, ['Retry-After' => '3']), 3],
    '429 with Retry-After' => [fn (ResponseSequence $sequence) => $sequence->push(['message' => 'Too many.', 'code' => 'too_many_requests'], 429, ['Retry-After' => '2']), 2],
    '409 idempotency_key_in_progress' => [fn (ResponseSequence $sequence) => $sequence->push(['message' => 'In progress.', 'code' => 'idempotency_key_in_progress'], 409), 1],
]);

it('retries a read after a connection error and a 503', function (): void {
    Sleep::fake();
    Http::fake([
        'verifactu.test/api/v1/invoices/uuid-1' => Http::sequence()
            ->pushFailedConnection()
            ->push(['message' => 'Busy.', 'code' => 'service_busy'], 503, ['Retry-After' => '1'])
            ->push(['data' => ['uuid' => 'uuid-1']]),
    ]);

    expect(Verifactu::getInvoice('uuid-1'))->toBe(['uuid' => 'uuid-1']);
    Http::assertSentCount(3);
});

it('throws a 422 at once with its code and errors', function (): void {
    Sleep::fake();
    Http::fake(['verifactu.test/api/v1/invoices' => Http::response([
        'message' => 'Invoices of type F1 need a recipient.',
        'code' => 'recipient_required',
        'errors' => ['recipient' => ['Invoices of type F1 need a recipient.']],
    ], 422)]);

    expect(fn () => Verifactu::createInvoice(['description' => 'Order 1']))
        ->toThrow(function (VerifactuException $exception): void {
            expect($exception->getMessage())->toBe('Invoices of type F1 need a recipient.')
                ->and($exception->status)->toBe(422)
                ->and($exception->errorCode)->toBe('recipient_required')
                ->and($exception->errors)->toBe(['recipient' => ['Invoices of type F1 need a recipient.']])
                ->and($exception->isRetryable())->toBeFalse();
        });
    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

it('throws a 409 that needs a person at once', function (): void {
    Http::fake(['verifactu.test/api/v1/invoices/uuid-1/cancellation' => Http::response([
        'message' => 'AEAT has not answered yet.',
        'code' => 'invoice_awaiting_aeat',
    ], 409)]);

    expect(fn () => Verifactu::cancelInvoice('uuid-1'))
        ->toThrow(fn (VerifactuException $exception) => expect($exception->errorCode)->toBe('invoice_awaiting_aeat'));
    Http::assertSentCount(1);
});

it('throws the last answer with its Retry-After once the retries run out', function (): void {
    Sleep::fake();
    Http::fake(['verifactu.test/api/v1/invoices' => Http::response(['message' => 'Busy.', 'code' => 'service_busy'], 503, ['Retry-After' => '1'])]);

    expect(fn () => Verifactu::createInvoice(['description' => 'Order 1']))
        ->toThrow(function (VerifactuException $exception): void {
            expect($exception->status)->toBe(503)
                ->and($exception->errorCode)->toBe('service_busy')
                ->and($exception->retryAfter)->toBe(1)
                ->and($exception->isRetryable())->toBeTrue();
        });
    Http::assertSentCount(3);
});

it('throws a retryable exception when the service never answers', function (): void {
    Sleep::fake();
    Http::fake(['verifactu.test/api/v1/invoices/uuid-1' => Http::failedConnection()]);

    expect(fn () => Verifactu::getInvoice('uuid-1'))
        ->toThrow(function (VerifactuException $exception): void {
            expect($exception->status)->toBeNull()
                ->and($exception->isRetryable())->toBeTrue();
        });
    Http::assertSentCount(3);
});

it('falls back to a plain message for an answer with only a status', function (): void {
    Http::fake(['verifactu.test/api/v1/invoices/uuid-1' => Http::response(null, 404)]);

    expect(fn () => Verifactu::getInvoice('uuid-1'))
        ->toThrow(VerifactuException::class, 'The Verifactu service answered 404.');
});

it('makes no retry when retries are set to zero', function (): void {
    config(['verifactu.retries' => 0]);
    Http::fake(['verifactu.test/api/v1/invoices/uuid-1' => Http::response(['message' => 'Busy.', 'code' => 'service_busy'], 503)]);

    expect(fn () => Verifactu::getInvoice('uuid-1'))->toThrow(VerifactuException::class);
    Http::assertSentCount(1);
});

it('gives other endpoints the configured request', function (): void {
    Http::fake(['verifactu.test/api/v1/exports/xml*' => Http::response('<xml/>')]);

    $request = Verifactu::http();
    $request->get('exports/xml', ['month' => '2026-11']);

    expect($request->getOptions())
        ->toMatchArray(['connect_timeout' => 5, 'timeout' => 30]);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://verifactu.test/api/v1/exports/xml?month=2026-11'
        && $request->header('Authorization') === ['Bearer vf_test_token-for-tests']);
});

it('refuses to call the service without a usable URL or token', function (string $key, ?string $value, string $message): void {
    config([$key => $value]);

    expect(fn () => Verifactu::getInvoice('uuid-1'))->toThrow(InvalidArgumentException::class, $message);
    Http::assertNothingSent();
})->with([
    'a missing URL' => ['verifactu.url', null, 'Set VERIFACTU_URL'],
    'an empty URL' => ['verifactu.url', '', 'Set VERIFACTU_URL'],
    'a URL without a scheme' => ['verifactu.url', 'verifactu.example.com', 'Set VERIFACTU_URL'],
    'a missing token' => ['verifactu.token', null, 'Set VERIFACTU_TOKEN'],
    'an empty token' => ['verifactu.token', '', 'Set VERIFACTU_TOKEN'],
]);
