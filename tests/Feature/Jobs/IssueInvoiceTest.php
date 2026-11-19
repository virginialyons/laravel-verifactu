<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Vlec\Verifactu\Client;
use Vlec\Verifactu\Events\InvoiceIssued;
use Vlec\Verifactu\Events\InvoiceNotIssued;
use Vlec\Verifactu\InvoiceStatus;
use Vlec\Verifactu\Jobs\IssueInvoice;
use Workbench\App\Models\Invoice;

uses(RefreshDatabase::class);

function issueJob(Invoice $invoice): IssueInvoice
{
    return runJob((new IssueInvoice($invoice))->withFakeQueueInteractions());
}

function runJob(IssueInvoice $job): IssueInvoice
{
    $job->handle(app(Client::class));

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
        ->number->toBe('FACT42');
    Event::assertDispatchedTimes(InvoiceIssued::class);
    Http::assertSentCount(1);
});

it('stores a 201 and fires InvoiceIssued, sending the reference and its key', function (): void {
    $invoice = Invoice::factory()->create();
    Event::fake([InvoiceIssued::class]);
    Http::fake(['verifactu.test/api/v1/invoices' => Http::response(['data' => serviceInvoice()], 201)]);

    $job = issueJob($invoice)->assertNotReleased();

    expect($job->idempotencyKey)->toStartWith("invoice:{$invoice->id}:");
    Http::assertSent(fn (Request $request): bool => $request->header('Idempotency-Key') === [$job->idempotencyKey]
        && $request['external_reference'] === "invoice:{$invoice->id}");
    expect($invoice->fresh()->verifactu_uuid)->toBe('9d2c4b1e-0f6a-4c1b-9a59-6f1f2b3c4d5e');
    Event::assertDispatched(InvoiceIssued::class, fn (InvoiceIssued $event): bool => $event->invoice->is($invoice));
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

    Http::assertSent(fn (Request $request): bool => urldecode($request->url()) === "https://verifactu.test/api/v1/invoices?filter[external_reference]=invoice:{$invoice->id}");
    expect($invoice->fresh())
        ->verifactu_uuid->toBe('uuid-1')
        ->qr->toBe('iVBORw0KGgo=');
    Event::assertDispatchedTimes(InvoiceIssued::class);
});

it('releases the job after a :dataset, with one request per attempt', function (Closure $failure, int $delay): void {
    $invoice = Invoice::factory()->create();
    Http::fake(['verifactu.test/api/v1/invoices' => $failure(Http::sequence())]);

    issueJob($invoice)->assertReleased($delay);

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

it('gives a later send a new Idempotency-Key, so a fixed refusal is sent again', function (): void {
    $invoice = Invoice::factory()->create();

    expect((new IssueInvoice($invoice))->idempotencyKey)->not->toBe((new IssueInvoice($invoice))->idempotencyKey);
});

it('waits longer before each later attempt', function (): void {
    $invoice = Invoice::factory()->create();
    Http::fake(['verifactu.test/api/v1/invoices' => Http::response(['message' => 'Error.'], 500)]);
    $job = (new IssueInvoice($invoice))->withFakeQueueInteractions();
    $job->job->attempts = 3;

    $job->handle(app(Client::class));

    $job->assertReleased(60);
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
    Event::assertDispatched(InvoiceNotIssued::class, fn (InvoiceNotIssued $event): bool => $event->invoice->is($invoice)
        && $event->errorCode === 'recipient_not_identified'
        && $event->message === 'Unknown customer.');
});

it('does not report an issued invoice as not issued when a listener fails', function (): void {
    config(['queue.default' => 'database']);
    $invoice = Invoice::factory()->create();
    Event::listen(InvoiceIssued::class, fn () => throw new RuntimeException('The mail server is down.'));
    Event::fake([InvoiceNotIssued::class]);
    Http::fake(['verifactu.test/api/v1/invoices' => Http::response(['data' => serviceInvoice()], 201)]);

    $invoice->sendToVerifactu();
    $this->artisan('queue:work', ['--once' => true]);

    expect($invoice->fresh()->verifactu_uuid)->not->toBeNull();
    Event::assertNotDispatched(InvoiceNotIssued::class);
});

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
