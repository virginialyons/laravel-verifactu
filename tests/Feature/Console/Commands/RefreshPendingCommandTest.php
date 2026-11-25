<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Vlec\Verifactu\Events\InvoiceBatchCompleted;
use Vlec\Verifactu\Jobs\ApplyInvoiceBatch;
use Vlec\Verifactu\Jobs\RefreshInvoice;
use Vlec\Verifactu\Models\InvoiceBatch;
use Workbench\App\Models\Invoice;

uses(RefreshDatabase::class);

it('refreshes only the invoices pending for over an hour', function (): void {
    $this->freezeTime();
    $stale = Invoice::factory()->issued()->create(['updated_at' => now()->subMinutes(61)]);
    Invoice::factory()->issued()->create(['updated_at' => now()->subMinutes(59)]);
    Invoice::factory()->issued()->create(['aeat_status' => 'accepted', 'updated_at' => now()->subDay()]);
    Invoice::factory()->create(['updated_at' => now()->subDay()]);
    Queue::fake([RefreshInvoice::class, ApplyInvoiceBatch::class]);

    $this->artisan('verifactu:refresh-pending')
        ->expectsOutputToContain('Found 1 pending invoice to refresh and 0 batches to apply.')
        ->assertSuccessful();

    Queue::assertPushed(RefreshInvoice::class, 1);
    Queue::assertPushed(RefreshInvoice::class, fn (RefreshInvoice $job): bool => $job->invoice->is($stale));
    Queue::assertNotPushed(ApplyInvoiceBatch::class);
});

it('refreshes every pending invoice of a model without timestamps', function (): void {
    $model = new #[Table(name: 'invoices')]
    #[WithoutTimestamps]
    class extends Invoice {};
    config(['verifactu.model' => $model::class]);
    Invoice::factory()->issued()->create();
    Queue::fake([RefreshInvoice::class]);

    $this->artisan('verifactu:refresh-pending')->assertSuccessful();

    Queue::assertPushed(RefreshInvoice::class, 1);
});

it('applies the batches not applied yet', function (): void {
    foreach (['queued', 'processing', 'completed', 'completed_with_errors'] as $status) {
        InvoiceBatch::create(['uuid' => "$status-batch", 'status' => $status]);
    }
    Queue::fake([ApplyInvoiceBatch::class]);

    $this->artisan('verifactu:refresh-pending')
        ->expectsOutputToContain('Found 0 pending invoices to refresh and 2 batches to apply.')
        ->assertSuccessful();

    Queue::assertPushed(ApplyInvoiceBatch::class, 2);
    Queue::assertPushed(ApplyInvoiceBatch::class, fn (ApplyInvoiceBatch $job): bool => $job->batch->uuid === 'queued-batch');
    Queue::assertPushed(ApplyInvoiceBatch::class, fn (ApplyInvoiceBatch $job): bool => $job->batch->uuid === 'processing-batch');
});

it('applies, once, a batch whose completion webhook came before it was stored', function (): void {
    $invoice = Invoice::factory()->create();
    // The webhook found no batch, so the stored one stays as the service first answered.
    InvoiceBatch::create(['uuid' => 'batch-1', 'status' => 'queued']);
    Http::fake([
        'verifactu.test/api/v1/invoice-batches/batch-1' => Http::response(['data' => serviceBatch(['uuid' => 'batch-1', 'status' => 'completed', 'total' => 1, 'processed' => 1, 'succeeded' => 1])]),
        'verifactu.test/api/v1/invoice-batches/batch-1/items*' => Http::response([
            'data' => [['index' => 0, 'external_reference' => "invoice:$invoice->id", 'result' => 'issued', 'invoice' => serviceInvoice(['uuid' => 'invoice-uuid-1']), 'error' => null]],
            'meta' => ['current_page' => 1, 'last_page' => 1],
        ]),
    ]);
    Event::fake([InvoiceBatchCompleted::class]);

    $this->artisan('verifactu:refresh-pending')->assertSuccessful();
    $this->artisan('verifactu:refresh-pending')->assertSuccessful();

    expect($invoice->fresh()->verifactu_uuid)->toBe('invoice-uuid-1');
    Event::assertDispatchedTimes(InvoiceBatchCompleted::class, 1);
    Http::assertSentCount(2);
});
