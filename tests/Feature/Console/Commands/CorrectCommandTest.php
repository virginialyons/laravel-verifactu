<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Vlec\Verifactu\InvoiceStatus;
use Workbench\App\Models\Invoice;

uses(RefreshDatabase::class);

it('sends a new record with the new description', function (): void {
    $invoice = Invoice::factory()->issued()->create(['verifactu_uuid' => 'uuid-1', 'aeat_status' => 'accepted_with_errors']);
    Http::fake(['verifactu.test/api/v1/invoices/uuid-1/corrections' => Http::response(['data' => serviceInvoice(['status' => 'pending'])], 201)]);

    $this->artisan('verifactu:correct', ['invoice' => $invoice->id, '--description' => 'Spanish course, B2'])
        ->expectsOutputToContain('The invoice has a new record, pending at AEAT.')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->data() === [...$invoice->toVerifactuRequest(), 'description' => 'Spanish course, B2']);
    expect($invoice->fresh()->aeat_status)->toBe(InvoiceStatus::Pending);
});

it('resends the record unchanged without a description: :dataset', function (array $options): void {
    $invoice = Invoice::factory()->issued()->create(['verifactu_uuid' => 'uuid-1', 'aeat_status' => 'rejected']);
    Http::fake(['verifactu.test/api/v1/invoices/uuid-1/corrections' => Http::response(['data' => serviceInvoice()], 201)]);

    $this->artisan('verifactu:correct', ['invoice' => $invoice->id, ...$options])->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->data() === $invoice->toVerifactuRequest());
})->with([
    'no option' => [[]],
    'an empty option' => [['--description' => '']],
]);

it('fails with the service\'s message when it refuses the correction', function (): void {
    $invoice = Invoice::factory()->issued()->create(['verifactu_uuid' => 'uuid-1']);
    Http::fake(['verifactu.test/api/v1/invoices/uuid-1/corrections' => Http::response(['message' => 'AEAT has not answered yet.', 'code' => 'invoice_awaiting_aeat'], 409)]);

    $this->artisan('verifactu:correct', ['invoice' => $invoice->id])
        ->expectsOutputToContain('AEAT has not answered yet.')
        ->assertFailed();
});

it('fails for an invoice not on Verifactu', function (): void {
    $invoice = Invoice::factory()->create();

    $this->artisan('verifactu:correct', ['invoice' => $invoice->id])
        ->expectsOutputToContain('The invoice is not on Verifactu yet')
        ->assertFailed();
});

it('fails for a key no invoice has', function (): void {
    $this->artisan('verifactu:correct', ['invoice' => 999])
        ->expectsOutputToContain('There is no invoice with that key.')
        ->assertFailed();
});
