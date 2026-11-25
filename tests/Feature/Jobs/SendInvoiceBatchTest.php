<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Vlec\Verifactu\Client;
use Vlec\Verifactu\Events\InvoiceNotIssued;
use Vlec\Verifactu\Exceptions\VerifactuException;
use Vlec\Verifactu\Facades\Verifactu;
use Vlec\Verifactu\InvoiceBatchStatus;
use Vlec\Verifactu\Jobs\SendInvoiceBatch;
use Vlec\Verifactu\Models\InvoiceBatch;
use Workbench\App\Models\Invoice;

uses(RefreshDatabase::class);

/**
 * @param  iterable<int, Invoice>  $invoices
 */
function sendBatchJob(iterable $invoices): SendInvoiceBatch
{
    $job = new SendInvoiceBatch(Collection::make($invoices))->withFakeQueueInteractions();
    $job->handle(resolve(Client::class));

    return $job;
}

it('sends the invoices as one batch once committed, and keeps the batch', function (): void {
    config(['queue.default' => 'database']);
    $invoices = Invoice::factory()->count(2)->create();
    Http::fake(['verifactu.test/api/v1/invoice-batches' => Http::response(['data' => serviceBatch()], 202)]);

    DB::transaction(function () use ($invoices): void {
        Verifactu::sendBatch($invoices);

        expect(DB::table('jobs')->count())->toBe(0);
    });

    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && collect($request['invoices'])->pluck('external_reference')->all() === ["invoice:{$invoices[0]->id}", "invoice:{$invoices[1]->id}"]
        && Str::startsWith($request->header('Idempotency-Key')[0], 'batch:'));
    expect(InvoiceBatch::sole())
        ->uuid->toBe('5f0c6a2e-8a5b-4c1e-9d7a-2b8e4f6a1c3d')
        ->status->toBe(InvoiceBatchStatus::Queued);
});

it('leaves out invoices already on Verifactu, and queues nothing when none is left', function (): void {
    Queue::fake();
    $issued = Invoice::factory()->issued()->create();
    $new = Invoice::factory()->create();

    Verifactu::sendBatch([$issued]);

    Queue::assertNothingPushed();

    Verifactu::sendBatch([$issued, $new]);

    Queue::assertPushed(SendInvoiceBatch::class, fn (SendInvoiceBatch $job): bool => $job->invoices->modelKeys() === [$new->id]);
});

it('keeps one Idempotency-Key across the retries of a send, and gives a later send a new one', function (): void {
    $invoices = Invoice::factory()->count(2)->create();
    Http::fake(['verifactu.test/api/v1/invoice-batches' => Http::sequence()
        ->push(['message' => 'Busy.', 'code' => 'service_busy'], 503, ['Retry-After' => '7'])
        ->push(['data' => serviceBatch()], 202)]);

    $job = sendBatchJob($invoices)->assertReleased(7);
    $job->withFakeQueueInteractions()->handle(resolve(Client::class));

    $keys = Http::recorded()->map(fn (array $pair): array => $pair[0]->header('Idempotency-Key'))->unique();
    expect($keys->all())->toBe([[$job->idempotencyKey]])
        ->and($job->idempotencyKey)->toStartWith('batch:')
        ->and(new SendInvoiceBatch($invoices)->idempotencyKey)->not->toBe($job->idempotencyKey)
        ->and(InvoiceBatch::count())->toBe(1);
});

it('is released to store the batch on the retry when the database fails after the service took it', function (): void {
    $invoices = Invoice::factory()->count(2)->create();
    Event::fake([InvoiceNotIssued::class]);
    Exceptions::fake();
    Http::fake(['verifactu.test/api/v1/invoice-batches' => Http::response(['data' => serviceBatch()], 202)]);
    Schema::rename('verifactu_invoice_batches', 'batches_away');

    $job = new SendInvoiceBatch($invoices);

    expect($this->secondsUntilRetry($job))->toBe(10);
    Exceptions::assertReported(QueryException::class);

    Schema::rename('batches_away', 'verifactu_invoice_batches');
    $this->travel(10)->seconds();
    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();

    expect(InvoiceBatch::sole())->uuid->toBe(serviceBatch()['uuid'])->status->toBe(InvoiceBatchStatus::Queued);
    expect(Http::recorded()->map(fn (array $pair): array => $pair[0]->header('Idempotency-Key'))->unique()->all())->toBe([[$job->idempotencyKey]]);
    Event::assertNotDispatched(InvoiceNotIssued::class);
});

it('keeps the batch queued until it is applied, whatever the service first answers', function (): void {
    Http::fake(['verifactu.test/api/v1/invoice-batches' => Http::response(['data' => serviceBatch(['status' => 'completed'])], 202)]);

    sendBatchJob(Invoice::factory()->count(2)->create());

    expect(InvoiceBatch::sole()->status)->toBe(InvoiceBatchStatus::Queued);
});

it('sends an invoice given twice once', function (): void {
    Queue::fake([SendInvoiceBatch::class]);
    $invoice = Invoice::factory()->create();

    // A copy loaded on its own, with a relation, is still the same invoice.
    $copy = Invoice::query()->find($invoice->id)->setRelation('notes', Collection::make());

    Verifactu::sendBatch([$invoice, $copy, Invoice::factory()->create()]);

    Queue::assertPushed(SendInvoiceBatch::class, fn (SendInvoiceBatch $job): bool => $job->invoices->count() === 2);
});

it('reports as not issued only the invoices still not on Verifactu', function (): void {
    $invoices = Invoice::factory()->count(2)->create();
    Event::fake([InvoiceNotIssued::class]);
    $job = new SendInvoiceBatch($invoices);
    $invoices[0]->storeVerifactuInvoice(serviceInvoice());

    $job->failed(new VerifactuException('Refused.', 422, 'validation_failed'));

    Event::assertDispatchedTimes(InvoiceNotIssued::class, 1);
    Event::assertDispatched(fn (InvoiceNotIssued $event): bool => $event->invoice->is($invoices[1]));
});

it('fails on a refusal and fires InvoiceNotIssued for each invoice', function (): void {
    config(['queue.default' => 'database']);
    $invoices = Invoice::factory()->count(2)->create();
    Event::fake([InvoiceNotIssued::class]);
    Http::fake(['verifactu.test/api/v1/invoice-batches' => Http::response(['message' => 'The invoices field is required.', 'code' => 'validation_failed'], 422)]);

    Verifactu::sendBatch($invoices);
    $this->artisan('queue:work', ['--once' => true]);

    expect(DB::table('failed_jobs')->count())->toBe(1)
        ->and(InvoiceBatch::count())->toBe(0);
    Event::assertDispatchedTimes(InvoiceNotIssued::class, 2);
    Event::assertDispatched(fn (InvoiceNotIssued $event): bool => $event->invoice->is($invoices[1])
        && $event->errorCode === 'validation_failed');
});

it('fires InvoiceNotIssued without an error code when the job fails :dataset', function (?Throwable $exception, string $message): void {
    $invoices = Invoice::factory()->count(2)->create();
    Event::fake([InvoiceNotIssued::class]);

    new SendInvoiceBatch($invoices)->failed($exception);

    Event::assertDispatchedTimes(InvoiceNotIssued::class, 2);
    Event::assertDispatched(fn (InvoiceNotIssued $event): bool => $event->invoice->is($invoices[1])
        && $event->errorCode === null
        && $event->message === $message);
})->with([
    'on another exception' => [new RuntimeException('The worker timed out.'), 'The worker timed out.'],
    'without an exception' => [null, 'The job failed.'],
]);
