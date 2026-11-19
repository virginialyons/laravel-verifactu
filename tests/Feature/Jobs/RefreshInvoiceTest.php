<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Vlec\Verifactu\Client;
use Vlec\Verifactu\Events\InvoiceRejected;
use Vlec\Verifactu\Exceptions\VerifactuException;
use Vlec\Verifactu\InvoiceStatus;
use Vlec\Verifactu\Jobs\RefreshInvoice;
use Workbench\App\Models\Invoice;

uses(RefreshDatabase::class);

function refreshJob(Invoice $invoice): RefreshInvoice
{
    $job = (new RefreshInvoice($invoice))->withFakeQueueInteractions();
    $job->handle(app(Client::class));

    return $job;
}

it('stores the invoice as the service has it and fires the event of its new status', function (): void {
    $invoice = Invoice::factory()->issued()->create();
    Event::fake([InvoiceRejected::class]);
    Http::fake(["verifactu.test/api/v1/invoices/{$invoice->verifactu_uuid}" => Http::response(['data' => serviceInvoice([
        'uuid' => $invoice->verifactu_uuid,
        'status' => 'rejected',
        'aeat' => ['verification_code' => null, 'error_code' => '1105', 'error_message' => 'NIF no identificado.', 'registered_at' => null],
    ])])]);

    refreshJob($invoice)->assertNotReleased();

    expect($invoice->fresh()->aeat_status)->toBe(InvoiceStatus::Rejected);
    Event::assertDispatched(InvoiceRejected::class, fn (InvoiceRejected $event): bool => $event->invoice->is($invoice) && $event->errorCode === '1105');
});

it('releases the job after a retryable answer, with one request per attempt', function (): void {
    $invoice = Invoice::factory()->issued()->create();
    Http::fake(["verifactu.test/api/v1/invoices/{$invoice->verifactu_uuid}" => Http::response(['message' => 'Busy.', 'code' => 'service_busy'], 503, ['Retry-After' => '7'])]);

    refreshJob($invoice)->assertReleased(7);

    Http::assertSentCount(1);
    expect($invoice->fresh()->aeat_status)->toBe(InvoiceStatus::Pending);
});

it('fails on a refusal', function (): void {
    $invoice = Invoice::factory()->issued()->create();
    Http::fake(["verifactu.test/api/v1/invoices/{$invoice->verifactu_uuid}" => Http::response(['message' => 'Not found.'], 404)]);

    refreshJob($invoice);
})->throws(VerifactuException::class);

it('waits longer before each later attempt when the service gives no Retry-After', function (): void {
    $invoice = Invoice::factory()->issued()->create();
    Http::fake(["verifactu.test/api/v1/invoices/{$invoice->verifactu_uuid}" => Http::response(['message' => 'Error.'], 500)]);
    $job = (new RefreshInvoice($invoice))->withFakeQueueInteractions();
    $job->job->attempts = 3;

    $job->handle(app(Client::class));

    $job->assertReleased(60);
});

it('waits for a read of the same invoice running on another worker', function (): void {
    config(['queue.default' => 'database']);
    $invoice = Invoice::factory()->issued()->create();
    $job = new RefreshInvoice($invoice);
    Cache::lock((new WithoutOverlapping($invoice->verifactuReference()))->getLockKey($job), 60)->get();

    dispatch($job);
    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();

    Http::assertNothingSent();
    expect(DB::table('jobs')->count())->toBe(1);
});
