<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Vlec\Verifactu\Events\InvoiceAccepted;
use Vlec\Verifactu\Events\InvoiceAcceptedWithErrors;
use Vlec\Verifactu\Events\InvoiceCancelled;
use Vlec\Verifactu\Events\InvoiceRejected;
use Vlec\Verifactu\Exceptions\VerifactuException;
use Vlec\Verifactu\InvoiceStatus;
use Workbench\App\Models\Invoice;

uses(RefreshDatabase::class);

it('stores an answer in the configured columns, with the status and date cast', function (): void {
    $invoice = Invoice::factory()->create();

    $invoice->storeVerifactuInvoice(serviceInvoice());

    expect($invoice->fresh())
        ->verifactu_uuid->toBe('9d2c4b1e-0f6a-4c1b-9a59-6f1f2b3c4d5e')
        ->aeat_status->toBe(InvoiceStatus::Pending)
        ->number->toBe('FACT42')
        ->short_number->toBe('42')
        ->issued_on->toDateString()->toBe('2026-11-19')
        ->verification_url->toStartWith('https://prewww2.aeat.es/')
        ->qr->toBe('iVBORw0KGgo=');
});

it('keeps the QR code when an answer leaves it out', function (): void {
    $invoice = Invoice::factory()->issued()->create(['qr' => 'iVBORw0KGgo=']);

    $invoice->storeVerifactuInvoice(collect(serviceInvoice(['status' => 'accepted']))->except('qr_code')->all());

    expect($invoice->fresh()->qr)->toBe('iVBORw0KGgo=');
});

it('fires the event of a new status', function (string $status, string $event): void {
    $invoice = Invoice::factory()->issued()->create();
    Event::fake([InvoiceAccepted::class, InvoiceAcceptedWithErrors::class, InvoiceRejected::class, InvoiceCancelled::class]);

    $invoice->storeVerifactuInvoice(serviceInvoice(['status' => $status]));

    Event::assertDispatchedTimes($event);
})->with([
    'accepted' => ['accepted', InvoiceAccepted::class],
    'accepted with errors' => ['accepted_with_errors', InvoiceAcceptedWithErrors::class],
    'rejected' => ['rejected', InvoiceRejected::class],
    'cancelled' => ['cancelled', InvoiceCancelled::class],
]);

it('fires no event when the status stays the same', function (): void {
    $invoice = Invoice::factory()->issued()->create(['aeat_status' => 'accepted']);
    Event::fake([InvoiceAccepted::class]);

    $invoice->storeVerifactuInvoice(serviceInvoice(['status' => 'accepted']));

    Event::assertNotDispatched(InvoiceAccepted::class);
});

it('gives a rejection AEAT\'s error', function (): void {
    $invoice = Invoice::factory()->issued()->create();
    Event::fake([InvoiceRejected::class]);

    $invoice->storeVerifactuInvoice(serviceInvoice([
        'status' => 'rejected',
        'aeat' => ['verification_code' => null, 'error_code' => '1105', 'error_message' => 'NIF no identificado.', 'registered_at' => null],
    ]));

    Event::assertDispatched(InvoiceRejected::class, fn (InvoiceRejected $event): bool => $event->invoice->is($invoice)
        && $event->errorCode === '1105'
        && $event->errorMessage === 'NIF no identificado.');
});

it('refreshes the invoice from the service', function (): void {
    $invoice = Invoice::factory()->issued()->create(['verifactu_uuid' => 'uuid-1']);
    Http::fake(['verifactu.test/api/v1/invoices/uuid-1' => Http::response(['data' => serviceInvoice(['status' => 'accepted'])])]);

    $invoice->refreshFromVerifactu();

    expect($invoice->fresh()->aeat_status)->toBe(InvoiceStatus::Accepted);
});

it('corrects the record with the sale again and a new description', function (): void {
    $invoice = Invoice::factory()->issued()->create(['verifactu_uuid' => 'uuid-1', 'aeat_status' => 'accepted_with_errors']);
    Http::fake(['verifactu.test/api/v1/invoices/uuid-1/corrections' => Http::response(['data' => serviceInvoice(['status' => 'pending'])], 201)]);

    $invoice->correctOnVerifactu('Spanish course, B2');

    Http::assertSent(fn (Request $request): bool => $request->data() === [
        ...$invoice->toVerifactuRequest(),
        'description' => 'Spanish course, B2',
    ]);
    expect($invoice->fresh()->aeat_status)->toBe(InvoiceStatus::Pending);
});

it('resends the record unchanged without a description', function (): void {
    $invoice = Invoice::factory()->issued()->create(['verifactu_uuid' => 'uuid-1', 'aeat_status' => 'rejected']);
    Http::fake(['verifactu.test/api/v1/invoices/uuid-1/corrections' => Http::response(['data' => serviceInvoice()], 201)]);

    $invoice->correctOnVerifactu();

    Http::assertSent(fn (Request $request): bool => $request->data() === $invoice->toVerifactuRequest());
});

it('cancels the invoice and stores the answer', function (): void {
    $invoice = Invoice::factory()->issued()->create(['verifactu_uuid' => 'uuid-1', 'aeat_status' => 'accepted']);
    Http::fake(['verifactu.test/api/v1/invoices/uuid-1/cancellation' => Http::response(['data' => serviceInvoice(['status' => 'pending'])], 201)]);

    $invoice->cancelOnVerifactu();

    Http::assertSentCount(1);
    expect($invoice->fresh()->aeat_status)->toBe(InvoiceStatus::Pending);
});

it('sends one Idempotency-Key across a timeout and its retry', function (Closure $call, string $path): void {
    Sleep::fake();
    $invoice = Invoice::factory()->issued()->create(['verifactu_uuid' => 'uuid-1', 'aeat_status' => 'accepted']);
    Http::fake(["verifactu.test/api/v1/invoices/uuid-1/{$path}" => Http::sequence()
        ->pushFailedConnection()
        ->push(['data' => serviceInvoice()], 201)]);

    $call($invoice);

    $keys = Http::recorded()->map(fn (array $pair): array => $pair[0]->header('Idempotency-Key'))->unique();
    expect($keys)->toHaveCount(1);
    Http::assertSentCount(2);
})->with([
    'a correction' => [fn (Invoice $invoice) => $invoice->correctOnVerifactu('New description'), 'corrections'],
    'a cancellation' => [fn (Invoice $invoice) => $invoice->cancelOnVerifactu(), 'cancellation'],
]);

it('throws the service\'s refusal of a fix or a cancellation and stores nothing', function (Closure $call, string $path, int $status, string $code): void {
    $invoice = Invoice::factory()->issued()->create(['verifactu_uuid' => 'uuid-1', 'aeat_status' => 'accepted']);
    Http::fake(["verifactu.test/api/v1/invoices/uuid-1/{$path}" => Http::response(['message' => 'Refused.', 'code' => $code], $status)]);

    expect(fn () => $call($invoice))
        ->toThrow(fn (VerifactuException $exception) => expect($exception->errorCode)->toBe($code));
    expect($invoice->fresh()->aeat_status)->toBe(InvoiceStatus::Accepted);
})->with([
    'correction awaiting AEAT' => [fn (Invoice $invoice) => $invoice->correctOnVerifactu(), 'corrections', 409, 'invoice_awaiting_aeat'],
    'correction of a cancelled invoice' => [fn (Invoice $invoice) => $invoice->correctOnVerifactu(), 'corrections', 409, 'invoice_cancelled'],
    'correction of fiscal data' => [fn (Invoice $invoice) => $invoice->correctOnVerifactu(), 'corrections', 422, 'correction_changes_fiscal_data'],
    'cancellation awaiting AEAT' => [fn (Invoice $invoice) => $invoice->cancelOnVerifactu(), 'cancellation', 409, 'invoice_awaiting_aeat'],
    'cancellation of a cancelled invoice' => [fn (Invoice $invoice) => $invoice->cancelOnVerifactu(), 'cancellation', 409, 'invoice_cancelled'],
]);

it('refuses to :dataset an invoice not on Verifactu', function (Closure $call): void {
    $invoice = Invoice::factory()->create();
    Http::fake();

    expect(fn () => $call($invoice))
        ->toThrow(LogicException::class, 'The invoice is not on Verifactu yet: send it with sendToVerifactu() first.');
    Http::assertNothingSent();
})->with([
    'fix' => [fn (Invoice $invoice) => $invoice->correctOnVerifactu('New description')],
    'cancel' => [fn (Invoice $invoice) => $invoice->cancelOnVerifactu()],
]);

it('sends an invoice again after a refusal, once the sale is fixed', function (): void {
    config(['queue.default' => 'database']);
    $invoice = Invoice::factory()->create();
    Http::fake(['verifactu.test/api/v1/invoices' => Http::sequence()
        ->push(['message' => 'Unknown customer.', 'code' => 'recipient_not_identified'], 422)
        ->push(['data' => serviceInvoice()], 201)]);

    $invoice->sendToVerifactu();
    $this->artisan('queue:work', ['--once' => true]);
    $invoice->sendToVerifactu();
    $this->artisan('queue:work', ['--once' => true]);

    expect($invoice->fresh()->verifactu_uuid)->not->toBeNull();
    Http::assertSentCount(2);
});

it('refers to the sale by the model\'s key', function (): void {
    $invoice = Invoice::factory()->create();

    expect($invoice->verifactuReference())->toBe("invoice:{$invoice->id}")
        ->and($invoice->toVerifactuRequest())->external_reference->toBe("invoice:{$invoice->id}")
        ->and(fn () => Invoice::factory()->make()->verifactuReference())
        ->toThrow(LogicException::class, 'Save the invoice before sending it to Verifactu.');
});

it('keeps the app\'s own cast of the issue-date column', function (): void {
    $invoice = new class extends Invoice
    {
        protected $table = 'invoices';

        protected function casts(): array
        {
            return [...parent::casts(), 'issued_on' => 'immutable_date'];
        }
    };

    expect($invoice->getCasts())
        ->issued_on->toBe('immutable_date')
        ->aeat_status->toBe(InvoiceStatus::class);
});
