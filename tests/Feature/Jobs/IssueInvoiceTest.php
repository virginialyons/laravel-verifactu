<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Vlec\Verifactu\Client;
use Vlec\Verifactu\Events\InvoiceAccepted;
use Vlec\Verifactu\Events\InvoiceIssued;
use Vlec\Verifactu\Events\InvoiceNotIssued;
use Vlec\Verifactu\Exceptions\VerifactuException;
use Vlec\Verifactu\InvoiceStatus;
use Vlec\Verifactu\Jobs\IssueInvoice;
use Workbench\App\Models\Invoice;

uses(RefreshDatabase::class);

function issueJob(Invoice $invoice): IssueInvoice
{
    return runJob(new IssueInvoice($invoice)->withFakeQueueInteractions());
}

function runJob(IssueInvoice $job): IssueInvoice
{
    $job->handle(resolve(Client::class));

    return $job;
}

it('issues the invoice through the database queue once it is committed', function (): void {
    config(['queue.default' => 'database']);
    $invoice = Invoice::factory()->create();
    Event::fake([InvoiceIssued::class]);
    Http::fake(['verifactu.test/api/v1/invoices' => Http::response(['data' => serviceInvoice()], 201)]);

    DB::transaction(function () use ($invoice): void {
        $invoice->sendToVerifactu();

        expect(DB::table('jobs')->count())->toBe(0);
    });
    $invoice->sendToVerifactu();

    expect(DB::table('jobs')->count())->toBe(1);

    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();

    expect($invoice->fresh())
        ->aeat_status->toBe(InvoiceStatus::Pending)
        ->number->toBe('FACT-2026-00042');
    Event::assertDispatchedTimes(InvoiceIssued::class);
    Http::assertSentCount(1);
});

it('stores a 201 and fires InvoiceIssued, sending the reference and its key', function (): void {
    $invoice = Invoice::factory()->create();
    Event::fake([InvoiceIssued::class]);
    Http::fake(['verifactu.test/api/v1/invoices' => Http::response(['data' => serviceInvoice()], 201)]);

    $job = issueJob($invoice)->assertNotReleased();

    expect($job->idempotencyKey)->toStartWith("invoice:$invoice->id:");
    Http::assertSent(fn (Request $request): bool => $request->header('Idempotency-Key') === [$job->idempotencyKey]
        && $request['external_reference'] === "invoice:$invoice->id");
    expect($invoice->fresh()->verifactu_uuid)->toBe('9d2c4b1e-0f6a-4c1b-9a59-6f1f2b3c4d5e');
    Event::assertDispatched(fn (InvoiceIssued $event): bool => $event->invoice->is($invoice));
});

it('stores the invoice already issued for the reference on a 409 external_reference_already_used', function (): void {
    $invoice = Invoice::factory()->create();
    Event::fake([InvoiceIssued::class]);
    Http::fake([
        'verifactu.test/api/v1/invoices' => Http::response(['message' => 'Used.', 'code' => 'external_reference_already_used'], 409),
        'verifactu.test/api/v1/invoices?*' => Http::response(['data' => [serviceInvoice(['uuid' => 'uuid-1'])]]),
        'verifactu.test/api/v1/invoices/uuid-1' => Http::response(['data' => serviceInvoice(['uuid' => 'uuid-1'])]),
    ]);

    issueJob($invoice);

    Http::assertSent(fn (Request $request): bool => urldecode($request->url()) === "https://verifactu.test/api/v1/invoices?filter[external_reference]=invoice:$invoice->id");
    expect($invoice->fresh())
        ->verifactu_uuid->toBe('uuid-1')
        ->qr->toBe('iVBORw0KGgo=');
    Event::assertDispatchedTimes(InvoiceIssued::class);
});

it('releases the job after a :dataset, with one request per attempt', function (Closure $failure, int $delay): void {
    $invoice = Invoice::factory()->create();
    Http::fake(['verifactu.test/api/v1/invoices' => $failure(Http::sequence())]);

    expect($this->secondsUntilRetry(new IssueInvoice($invoice)))->toBe($delay);

    Http::assertSentCount(1);
    expect($invoice->fresh()->verifactu_uuid)->toBeNull();
})->with([
    'connection error' => [fn (ResponseSequence $sequence) => $sequence->pushFailedConnection(), 10],
    '500' => [fn (ResponseSequence $sequence) => $sequence->push(['message' => 'Error.', 'code' => 'record_generation_failed'], 500), 10],
    '503 with Retry-After' => [fn (ResponseSequence $sequence) => $sequence->push(['message' => 'Busy.', 'code' => 'service_busy'], 503, ['Retry-After' => '7']), 7],
    '429 with Retry-After' => [fn (ResponseSequence $sequence) => $sequence->push(['message' => 'Slow down.', 'code' => 'too_many_requests'], 429, ['Retry-After' => '20']), 20],
    '409 idempotency_key_in_progress' => [fn (ResponseSequence $sequence) => $sequence->push(['message' => 'In progress.', 'code' => 'idempotency_key_in_progress'], 409), 10],
]);

it('sends the same Idempotency-Key on the retry', function (): void {
    $invoice = Invoice::factory()->create();
    Http::fake(['verifactu.test/api/v1/invoices' => Http::sequence()
        ->push(['message' => 'Busy.', 'code' => 'service_busy'], 503)
        ->push(['data' => serviceInvoice()], 201)]);

    $job = issueJob($invoice)->assertReleased();
    runJob($job->withFakeQueueInteractions());

    $keys = Http::recorded()->map(fn (array $pair): array => $pair[0]->header('Idempotency-Key'))->unique();
    expect($keys->all())->toBe([[$job->idempotencyKey]])
        ->and($invoice->fresh()->verifactu_uuid)->not->toBeNull();
});

it('reads back the invoice a lost answer issued when the invoice changed since', function (): void {
    $invoice = Invoice::factory()->create();
    Event::fake([InvoiceIssued::class, InvoiceNotIssued::class]);
    Http::fake([
        'verifactu.test/api/v1/invoices' => Http::response(['message' => 'The key was used with another request.', 'code' => 'idempotency_key_reused'], 422),
        'verifactu.test/api/v1/invoices?*' => Http::response(['data' => [serviceInvoice(['uuid' => 'uuid-1'])], 'links' => [], 'meta' => []]),
        'verifactu.test/api/v1/invoices/uuid-1' => Http::response(['data' => serviceInvoice(['uuid' => 'uuid-1'])]),
    ]);

    issueJob($invoice)->assertNotFailed();

    expect($invoice->fresh()->verifactu_uuid)->toBe('uuid-1');
    Event::assertDispatched(InvoiceIssued::class);
    Event::assertNotDispatched(InvoiceNotIssued::class);
});

it('keeps the refusal when the service holds no invoice for the sale', function (): void {
    $invoice = Invoice::factory()->create();
    Http::fake([
        'verifactu.test/api/v1/invoices' => Http::response(['message' => 'The key was used with another request.', 'code' => 'idempotency_key_reused'], 422),
        'verifactu.test/api/v1/invoices?*' => Http::response(['data' => [], 'links' => [], 'meta' => []]),
    ]);

    expect(fn (): IssueInvoice => issueJob($invoice))
        ->toThrow(function (VerifactuException $exception): void {
            expect($exception->errorCode)->toBe('idempotency_key_reused');
        });
    expect($invoice->fresh()->verifactu_uuid)->toBeNull();
});

it('fails, not retries, when a status listener breaks after the invoice was stored', function (): void {
    $invoice = Invoice::factory()->create();
    Event::listen(InvoiceAccepted::class, fn (): never => throw new QueryException('testing', 'insert into emails', [], new Exception('Lost connection.')));
    Http::fake([
        'verifactu.test/api/v1/invoices' => Http::response(['message' => 'Used.', 'code' => 'external_reference_already_used'], 409),
        'verifactu.test/api/v1/invoices?*' => Http::response(['data' => [serviceInvoice(['uuid' => 'uuid-1'])], 'links' => [], 'meta' => []]),
        'verifactu.test/api/v1/invoices/uuid-1' => Http::response(['data' => serviceInvoice(['uuid' => 'uuid-1', 'status' => 'accepted'])]),
    ]);

    expect(fn (): IssueInvoice => issueJob($invoice))->toThrow(QueryException::class);
    expect($invoice->fresh()->verifactu_uuid)->toBe('uuid-1');
});

it('is released to store the invoice on the retry when the database fails after the service issued it', function (): void {
    $invoice = Invoice::factory()->create();
    Event::fake([InvoiceIssued::class, InvoiceNotIssued::class]);
    Exceptions::fake();
    Http::fake(['verifactu.test/api/v1/invoices' => Http::response(['data' => serviceInvoice()], 201)]);
    Schema::table('invoices', fn ($table) => $table->renameColumn('number', 'printed_number'));

    expect($this->secondsUntilRetry(new IssueInvoice($invoice)))->toBe(10);

    Exceptions::assertReported(QueryException::class);
    Event::assertNotDispatched(InvoiceIssued::class);
    Event::assertNotDispatched(InvoiceNotIssued::class);
});

it('runs on the configured queue, unless the app routes it elsewhere', function (): void {
    config(['verifactu.queue' => 'invoices']);
    Queue::fake([IssueInvoice::class]);
    $invoice = Invoice::factory()->create();

    $invoice->sendToVerifactu();

    Queue::assertPushedOn('invoices', IssueInvoice::class);
});

it('runs where the app routes it, over the configured queue', function (): void {
    config(['verifactu.queue' => 'invoices']);
    Queue::fake([IssueInvoice::class]);
    Queue::route(IssueInvoice::class, 'billing');

    Invoice::factory()->create()->sendToVerifactu();

    Queue::assertPushedOn('billing', IssueInvoice::class);
});

it('gives a later send a new Idempotency-Key, so a fixed refusal is sent again', function (): void {
    $invoice = Invoice::factory()->create();

    expect(new IssueInvoice($invoice)->idempotencyKey)->not->toBe(new IssueInvoice($invoice)->idempotencyKey);
});

it('waits longer before each later attempt, up to five minutes: attempt :dataset', function (int $attempt, int $delay): void {
    $invoice = Invoice::factory()->create();
    Http::fake(['verifactu.test/api/v1/invoices' => Http::response(['message' => 'Busy.', 'code' => 'service_busy'], 503)]);

    expect($this->secondsUntilRetry(new IssueInvoice($invoice), $attempt))->toBe($delay);
})->with([
    [1, 10],
    [2, 30],
    [3, 60],
    [4, 300],
    [5, 300],
    [9, 300],
]);

it('queues the job to retry for the 24 hours the service replays its key, failing at the first exception it does not release', function (): void {
    config(['queue.default' => 'database']);
    $this->freezeSecond();

    Invoice::factory()->create()->sendToVerifactu();

    expect(DB::table('jobs')->value('payload'))
        ->json()
        ->retryUntil->toBe(now()->addDay()->getTimestamp())
        ->maxExceptions->toBe(1);
});

it('fails on any other refusal and fires InvoiceNotIssued', function (): void {
    config(['queue.default' => 'database']);
    $invoice = Invoice::factory()->create();
    Event::fake([InvoiceNotIssued::class]);
    Http::fake(['verifactu.test/api/v1/invoices' => Http::response(['message' => 'Unknown customer.', 'code' => 'recipient_not_identified'], 422)]);

    $invoice->sendToVerifactu();
    $this->artisan('queue:work', ['--once' => true]);

    expect(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($invoice->fresh()->verifactu_uuid)->toBeNull();
    Event::assertDispatched(fn (InvoiceNotIssued $event): bool => $event->invoice->is($invoice)
        && $event->errorCode === 'recipient_not_identified'
        && $event->message === 'Unknown customer.');
});

it('does not report an issued invoice as not issued when a listener fails', function (): void {
    config(['queue.default' => 'database']);
    $invoice = Invoice::factory()->create();
    Event::listen(InvoiceIssued::class, fn (): never => throw new RuntimeException('The mail server is down.'));
    Event::fake([InvoiceNotIssued::class]);
    Http::fake(['verifactu.test/api/v1/invoices' => Http::response(['data' => serviceInvoice()], 201)]);

    $invoice->sendToVerifactu();
    $this->artisan('queue:work', ['--once' => true]);

    expect($invoice->fresh()->verifactu_uuid)->not->toBeNull()
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0);
    Event::assertNotDispatched(InvoiceNotIssued::class);
});

it('fires InvoiceNotIssued without an error code when the job fails :dataset', function (?Throwable $exception, string $message): void {
    $invoice = Invoice::factory()->create();
    Event::fake([InvoiceNotIssued::class]);

    new IssueInvoice($invoice)->failed($exception);

    Event::assertDispatched(fn (InvoiceNotIssued $event): bool => $event->invoice->is($invoice)
        && $event->errorCode === null
        && $event->message === $message);
})->with([
    'on another exception' => [new RuntimeException('The worker timed out.'), 'The worker timed out.'],
    'without an exception' => [null, 'The job failed.'],
]);

it('queues one job for an invoice sent twice', function (): void {
    config(['queue.default' => 'database']);
    $invoice = Invoice::factory()->create();

    $invoice->sendToVerifactu();
    $invoice->sendToVerifactu();

    expect(DB::table('jobs')->count())->toBe(1);
});

it('sends nothing for an invoice already on Verifactu', function (): void {
    $invoice = Invoice::factory()->issued()->create();

    issueJob($invoice);

    Http::assertNothingSent();
});

it('lets the invoice be sent again once the lock of a lost job expires, after the 24 hours of retries', function (): void {
    $this->freezeSecond();
    Queue::fake([IssueInvoice::class]);
    $invoice = Invoice::factory()->create();
    $invoice->sendToVerifactu();

    $this->travel(89999)->seconds();
    $invoice->sendToVerifactu();

    Queue::assertPushedTimes(IssueInvoice::class, 1);

    $this->travel(1)->second();
    $invoice->sendToVerifactu();

    Queue::assertPushedTimes(IssueInvoice::class, 2);
});
