<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Vlec\Verifactu\Client;
use Vlec\Verifactu\Events\InvoiceBatchCompleted;
use Vlec\Verifactu\Events\InvoiceIssued;
use Vlec\Verifactu\Events\InvoiceNotIssued;
use Vlec\Verifactu\InvoiceBatchStatus;
use Vlec\Verifactu\Jobs\ApplyInvoiceBatch;
use Vlec\Verifactu\Jobs\IssueInvoice;
use Vlec\Verifactu\Models\InvoiceBatch;
use Workbench\App\Models\Invoice;

uses(RefreshDatabase::class);

const BatchUuid = '5f0c6a2e-8a5b-4c1e-9d7a-2b8e4f6a1c3d';

function applyBatchJob(InvoiceBatch $batch): ApplyInvoiceBatch
{
    $job = new ApplyInvoiceBatch($batch->fresh())->withFakeQueueInteractions();
    $job->handle(resolve(Client::class));

    return $job;
}

/**
 * The batch's items as the service lists them: each invoice issued, except those failed.
 *
 * @param  Collection<int, Invoice>  $invoices
 * @param  list<int>  $failed  The indexes of the failed items.
 * @return list<array<string, mixed>>
 */
function batchItems(Collection $invoices, array $failed = []): array
{
    return $invoices->values()->map(function (Invoice $invoice, int $index) use ($failed): array {
        $reference = "invoice:{$invoice->id}";

        if (Collection::make($failed)->containsStrict($index)) {
            return ['index' => $index, 'external_reference' => $reference, 'result' => 'failed', 'invoice' => null, 'error' => ['code' => 'recipient_not_identified', 'message' => 'Unknown customer.']];
        }

        $number = (string) ($index + 1);

        return ['index' => $index, 'external_reference' => $reference, 'result' => 'issued', 'invoice' => serviceInvoice([
            'uuid' => "invoice-uuid-{$number}",
            'number' => $number,
            'invoice_number' => sprintf('FACT-2026-%05d', $number),
            'external_reference' => $reference,
        ]), 'error' => null];
    })->all();
}

/**
 * Fakes the batch and its items, 100 per page.
 *
 * @param  list<array<string, mixed>>  $items
 * @param  array<string, mixed>  $batch
 */
function fakeBatch(array $items, array $batch): void
{
    $pages = Collection::make($items)->chunk(100)->map(fn (Collection $page): array => $page->values()->all());

    Http::fake([
        'verifactu.test/api/v1/invoice-batches/'.BatchUuid => Http::response(['data' => serviceBatch($batch)]),
        'verifactu.test/api/v1/invoice-batches/'.BatchUuid.'/items*' => function (Request $request) use ($pages) {
            $page = (int) Arr::get($request->data(), 'page', 1);

            return Http::response(['data' => $pages->get($page - 1), 'meta' => ['current_page' => $page, 'last_page' => $pages->count()]]);
        },
    ]);
}

function queuedBatch(): InvoiceBatch
{
    return InvoiceBatch::create(['uuid' => BatchUuid, 'status' => InvoiceBatchStatus::Queued]);
}

it('applies a batch of 150 from two pages: 149 issued and stored, 1 failed, each event once', function (): void {
    $invoices = Invoice::factory()->count(150)->create();
    $batch = queuedBatch();
    Event::fake([InvoiceIssued::class, InvoiceNotIssued::class, InvoiceBatchCompleted::class]);
    fakeBatch(batchItems($invoices, failed: [120]), ['status' => 'completed_with_errors', 'total' => 150, 'processed' => 150, 'succeeded' => 149, 'failed' => 1]);

    applyBatchJob($batch)->assertNotReleased();

    Http::assertSentCount(3);
    expect(Invoice::query()->whereNotNull('verifactu_uuid')->count())->toBe(149)
        ->and($invoices[0]->fresh())->number->toBe('FACT-2026-00001')->short_number->toBe('1')->qr->toBe('iVBORw0KGgo=')
        ->and($invoices[120]->fresh()->verifactu_uuid)->toBeNull()
        ->and($batch->fresh()->status)->toBe(InvoiceBatchStatus::CompletedWithErrors);
    Event::assertDispatchedTimes(InvoiceIssued::class, 149);
    Event::assertDispatchedTimes(InvoiceNotIssued::class, 1);
    Event::assertDispatched(fn (InvoiceNotIssued $event): bool => $event->invoice->is($invoices[120])
        && $event->errorCode === 'recipient_not_identified'
        && $event->message === 'Unknown customer.');
    Event::assertDispatched(fn (InvoiceBatchCompleted $event): bool => $event->batch->is($batch)
        && [$event->total, $event->succeeded, $event->failed] === [150, 149, 1]);
});

it('applies a batch only once', function (): void {
    $invoices = Invoice::factory()->count(2)->create();
    $batch = queuedBatch();
    Event::fake([InvoiceIssued::class, InvoiceBatchCompleted::class]);
    fakeBatch(batchItems($invoices), ['status' => 'completed', 'processed' => 2, 'succeeded' => 2]);

    applyBatchJob($batch);
    applyBatchJob($batch);

    Http::assertSentCount(2);
    Event::assertDispatchedTimes(InvoiceIssued::class, 2);
    Event::assertDispatchedTimes(InvoiceBatchCompleted::class, 1);
});

it('leaves a batch still running for later', function (): void {
    $invoices = Invoice::factory()->count(2)->create();
    $batch = queuedBatch();
    Event::fake([InvoiceBatchCompleted::class]);
    fakeBatch(batchItems($invoices), ['status' => 'processing', 'processed' => 1]);

    applyBatchJob($batch)->assertNotReleased();

    Http::assertSentCount(1);
    expect($batch->fresh()->status)->toBe(InvoiceBatchStatus::Queued);
    Event::assertNotDispatched(InvoiceBatchCompleted::class);
});

it('skips items of invoices it does not know or has already stored', function (): void {
    $invoices = Invoice::factory()->count(2)->create();
    $alreadyStored = Invoice::factory()->issued()->create();
    $items = batchItems($invoices->push($alreadyStored));
    $items[0]['external_reference'] = 'order-1043';
    $batch = queuedBatch();
    Event::fake([InvoiceIssued::class]);
    fakeBatch($items, ['status' => 'completed', 'total' => 3, 'processed' => 3, 'succeeded' => 3]);

    applyBatchJob($batch);

    Event::assertDispatchedTimes(InvoiceIssued::class, 1);
    Event::assertDispatched(fn (InvoiceIssued $event): bool => $event->invoice->is($invoices[1]));
    expect($invoices[0]->fresh()->verifactu_uuid)->toBeNull()
        ->and($alreadyStored->fresh()->number)->toBe('FACT-2026-00042');
});

it('sends an item already issued by another send on its own, to store that invoice, instead of reporting it', function (): void {
    $invoices = Invoice::factory()->count(2)->create();
    $items = batchItems($invoices, failed: [1]);
    $items[1]['error'] = ['code' => 'external_reference_already_used', 'message' => 'The external reference is already used.'];
    $batch = queuedBatch();
    Queue::fake([IssueInvoice::class]);
    Event::fake([InvoiceNotIssued::class]);
    fakeBatch($items, ['status' => 'completed_with_errors', 'processed' => 2, 'succeeded' => 1, 'failed' => 1]);

    applyBatchJob($batch);

    Queue::assertPushed(IssueInvoice::class, fn (IssueInvoice $job): bool => $job->invoice->is($invoices[1]));
    Event::assertNotDispatched(InvoiceNotIssued::class);
});

it('applies nothing when a page cannot be read, and is released to read them all again', function (): void {
    $invoices = Invoice::factory()->count(150)->create();
    $batch = queuedBatch();
    Event::fake([InvoiceIssued::class, InvoiceBatchCompleted::class]);
    Http::fake([
        'verifactu.test/api/v1/invoice-batches/'.BatchUuid => Http::response(['data' => serviceBatch(['status' => 'completed', 'total' => 150])]),
        'verifactu.test/api/v1/invoice-batches/'.BatchUuid.'/items*' => Http::sequence()
            ->push(['data' => Arr::take(batchItems($invoices), 100), 'meta' => ['current_page' => 1, 'last_page' => 2]])
            ->push(['message' => 'Busy.', 'code' => 'service_busy'], 503, ['Retry-After' => '7']),
    ]);

    applyBatchJob($batch)->assertReleased(7);

    expect(Invoice::query()->whereNotNull('verifactu_uuid')->count())->toBe(0)
        ->and($batch->fresh()->status)->toBe(InvoiceBatchStatus::Queued);
    Event::assertNotDispatched(InvoiceIssued::class);
    Event::assertNotDispatched(InvoiceBatchCompleted::class);
});

it('lets the batch be applied again once the lock of a lost job expires, after the 24 hours of retries', function (): void {
    $this->freezeSecond();
    Queue::fake([ApplyInvoiceBatch::class]);
    $batch = queuedBatch();
    dispatch(new ApplyInvoiceBatch($batch));

    $this->travel(89999)->seconds();
    dispatch(new ApplyInvoiceBatch($batch));

    Queue::assertPushedTimes(ApplyInvoiceBatch::class, 1);

    $this->travel(1)->second();
    dispatch(new ApplyInvoiceBatch($batch));

    Queue::assertPushedTimes(ApplyInvoiceBatch::class, 2);
});
