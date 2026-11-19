<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\ExpectationFailedException;
use Vlec\Verifactu\Events\InvoiceAccepted;
use Vlec\Verifactu\Events\InvoiceBatchCompleted;
use Vlec\Verifactu\Events\InvoiceCancelled;
use Vlec\Verifactu\Events\InvoiceIssued;
use Vlec\Verifactu\Events\InvoiceNotIssued;
use Vlec\Verifactu\Events\InvoiceRejected;
use Vlec\Verifactu\Exceptions\VerifactuException;
use Vlec\Verifactu\Facades\Verifactu;
use Vlec\Verifactu\InvoiceBatchStatus;
use Vlec\Verifactu\InvoiceStatus;
use Vlec\Verifactu\Models\InvoiceBatch;
use Vlec\Verifactu\Rules\KnownToAeat;
use Vlec\Verifactu\TaxIdCheckResult;
use Vlec\Verifactu\Testing\VerifactuFake;
use Workbench\App\Models\Invoice;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-19 10:00:00');
});

it('issues an invoice and plays AEAT\'s acceptance through the real jobs', function (): void {
    Event::fake([InvoiceAccepted::class]);
    Verifactu::fake();
    $invoice = Invoice::factory()->create();

    $invoice->sendToVerifactu();
    Verifactu::decide($invoice, InvoiceStatus::Accepted);

    Verifactu::assertIssued($invoice);
    Event::assertDispatched(InvoiceAccepted::class, fn (InvoiceAccepted $event): bool => $event->invoice->is($invoice));
    expect($invoice->fresh())
        ->verifactu_uuid->toBeString()
        ->aeat_status->toBe(InvoiceStatus::Accepted)
        ->number->toBe('FACT1')
        ->short_number->toBe('1')
        ->issued_on->toDateString()->toBe('2026-11-19')
        ->verification_url->toStartWith('https://prewww2.aeat.es/')
        ->qr->not->toBeEmpty();
});

it('numbers invoices in order', function (): void {
    Verifactu::fake();
    [$first, $second] = Invoice::factory()->count(2)->create()->all();

    $first->sendToVerifactu();
    $second->sendToVerifactu();

    expect($first->fresh()->number)->toBe('FACT1')
        ->and($second->fresh()->number)->toBe('FACT2');
});

it('hands the callback the body the invoice was issued from', function (): void {
    Verifactu::fake();
    $invoice = Invoice::factory()->create(['exempt_cents' => 30000, 'taxable_cents' => 0]);

    $invoice->sendToVerifactu();

    Verifactu::assertIssued($invoice, fn (array $body): bool => $body['total_amount'] === '300.00'
        && $body['external_reference'] === "invoice:{$invoice->id}");
});

it('plays a rejection with AEAT\'s error code', function (): void {
    Event::fake([InvoiceRejected::class]);
    Verifactu::fake();
    $invoice = Invoice::factory()->create();

    $invoice->sendToVerifactu();
    Verifactu::decide($invoice, InvoiceStatus::Rejected, errorCode: '1105', errorMessage: 'El NIF no está identificado.');

    Event::assertDispatched(InvoiceRejected::class, fn (InvoiceRejected $event): bool => $event->errorCode === '1105'
        && $event->errorMessage === 'El NIF no está identificado.');
    expect($invoice->fresh()->aeat_status)->toBe(InvoiceStatus::Rejected);
});

it('completes a batch at once and applies it', function (): void {
    Event::fake([InvoiceIssued::class, InvoiceBatchCompleted::class]);
    Verifactu::fake();
    $invoices = Invoice::factory()->count(3)->create();

    Verifactu::sendBatch($invoices);

    Verifactu::assertBatchSent();
    $invoices->each(fn (Invoice $invoice) => Verifactu::assertIssued($invoice));
    expect($invoices->map(fn (Invoice $invoice): ?string => $invoice->fresh()->number)->all())->toBe(['FACT1', 'FACT2', 'FACT3'])
        ->and(InvoiceBatch::sole()->status)->toBe(InvoiceBatchStatus::Completed);
    Event::assertDispatchedTimes(InvoiceIssued::class, 3);
    Event::assertDispatched(InvoiceBatchCompleted::class, fn (InvoiceBatchCompleted $event): bool => $event->succeeded === 3);
});

it('stores the invoice already issued when it is sent again', function (): void {
    Verifactu::fake();
    $invoice = Invoice::factory()->create();
    $invoice->sendToVerifactu();
    $uuid = $invoice->fresh()->verifactu_uuid;
    $invoice->forceFill(['verifactu_uuid' => null])->save();

    $invoice->sendToVerifactu();

    expect($invoice->fresh())
        ->verifactu_uuid->toBe($uuid)
        ->number->toBe('FACT1');
});

it('fixes and cancels an invoice once AEAT has answered', function (): void {
    Event::fake([InvoiceCancelled::class]);
    Verifactu::fake();
    $invoice = Invoice::factory()->create();
    $invoice->sendToVerifactu();
    Verifactu::decide($invoice, InvoiceStatus::Rejected, errorCode: '1105');

    $invoice->fresh()->correctOnVerifactu('Spanish course, B2');
    Verifactu::decide($invoice, InvoiceStatus::Accepted);
    $invoice->fresh()->cancelOnVerifactu();
    Verifactu::decide($invoice, InvoiceStatus::Cancelled);

    Verifactu::assertCorrected($invoice);
    Verifactu::assertCancelled($invoice);
    Event::assertDispatched(InvoiceCancelled::class);
    expect($invoice->fresh()->aeat_status)->toBe(InvoiceStatus::Cancelled);
});

it('refuses to :dataset an invoice AEAT has not answered for, as the service does', function (Closure $change): void {
    Verifactu::fake();
    $invoice = Invoice::factory()->create();
    $invoice->sendToVerifactu();

    expect(fn () => $change($invoice->fresh()))
        ->toThrow(fn (VerifactuException $exception) => expect($exception->errorCode)->toBe('invoice_awaiting_aeat'));
})->with([
    'fix' => [fn (Invoice $invoice) => $invoice->correctOnVerifactu('New description')],
    'cancel' => [fn (Invoice $invoice) => $invoice->cancelOnVerifactu()],
]);

function checkCustomer(): array
{
    return Validator::make(['tax_id' => 'A15022510', 'legal_name' => 'Acme S.L.'], [
        'tax_id' => [new KnownToAeat(nameField: 'legal_name')],
    ])->errors()->toArray();
}

it('answers tax-ID checks as the test sets: :dataset', function (Closure $setting, array $errors): void {
    $setting(Verifactu::fake());

    expect(checkCustomer())->toBe($errors);
})->with([
    'identified, the default' => [fn (VerifactuFake $fake) => $fake, []],
    'not identified' => [fn (VerifactuFake $fake) => $fake->taxIdCheck(TaxIdCheckResult::NotIdentified), ['tax_id' => ['AEAT does not accept this tax ID with this name.']]],
    'similar' => [fn (VerifactuFake $fake) => $fake->taxIdCheck(TaxIdCheckResult::Similar, 'ACME SOCIEDAD LIMITADA'), ['legal_name' => ['AEAT holds this tax ID as: ACME SOCIEDAD LIMITADA']]],
]);

it('keeps the tax-ID answer for every later check', function (): void {
    Verifactu::fake()->taxIdCheck(TaxIdCheckResult::Revoked);

    expect(checkCustomer())->toHaveKey('tax_id')
        ->and(checkCustomer())->toHaveKey('tax_id');
});

it('refuses a similar answer without the official name', function (): void {
    expect(fn () => Verifactu::fake()->taxIdCheck(TaxIdCheckResult::Similar))
        ->toThrow(LogicException::class, 'Give the official name AEAT holds with a Similar answer.');
});

it('keeps one fake when it is called twice', function (): void {
    Verifactu::fake();
    $invoice = Invoice::factory()->create();
    $invoice->sendToVerifactu();

    Verifactu::fake()->taxIdCheck(TaxIdCheckResult::NotIdentified);
    Verifactu::decide($invoice, InvoiceStatus::Accepted);

    expect($invoice->fresh()->aeat_status)->toBe(InvoiceStatus::Accepted)
        ->and(checkCustomer())->toHaveKey('tax_id')
        ->and(Verifactu::isFake())->toBeTrue();
});

it('completes a batch when the test fakes every event', function (): void {
    Event::fake();
    Verifactu::fake();
    $invoices = Invoice::factory()->count(2)->create();

    Verifactu::sendBatch($invoices);

    expect($invoices->map(fn (Invoice $invoice): ?string => $invoice->fresh()->number)->all())->toBe(['FACT1', 'FACT2']);
    Event::assertDispatchedTimes(InvoiceIssued::class, 2);
});

it('reads a batch of more than one page of items', function (): void {
    Verifactu::fake();
    $invoices = Invoice::factory()->count(101)->create();

    Verifactu::sendBatch($invoices);

    expect($invoices->last()->fresh()->number)->toBe('FACT101');
});

it('stores an invoice of a batch that was issued before on its own', function (): void {
    Verifactu::fake();
    [$issued, $new] = Invoice::factory()->count(2)->create()->all();
    $issued->sendToVerifactu();
    $uuid = $issued->fresh()->verifactu_uuid;
    $issued->forceFill(['verifactu_uuid' => null])->save();

    Verifactu::sendBatch([$issued->fresh(), $new]);

    expect($issued->fresh())->verifactu_uuid->toBe($uuid)->number->toBe('FACT1')
        ->and($new->fresh()->number)->toBe('FACT2');
});

it('refuses a batch that repeats an invoice, as the service does', function (): void {
    Event::fake([InvoiceNotIssued::class]);
    Verifactu::fake();
    $invoice = Invoice::factory()->create();

    // On the sync queue, the job's failure reaches the caller once failed() has run.
    expect(fn () => Verifactu::sendBatch([$invoice, $invoice]))
        ->toThrow(VerifactuException::class, 'Items that have an external_reference need distinct ones.');

    Verifactu::assertNothingIssued();
    Event::assertDispatched(InvoiceNotIssued::class, fn (InvoiceNotIssued $event): bool => $event->errorCode === 'validation_failed');
});

it('refuses to fix a cancelled invoice, as the service does', function (): void {
    Verifactu::fake();
    $invoice = Invoice::factory()->create();
    $invoice->sendToVerifactu();
    Verifactu::decide($invoice, InvoiceStatus::Accepted);
    $invoice->fresh()->cancelOnVerifactu();
    Verifactu::decide($invoice, InvoiceStatus::Cancelled);

    expect(fn () => $invoice->fresh()->correctOnVerifactu('New description'))
        ->toThrow(fn (VerifactuException $exception) => expect($exception->errorCode)->toBe('invoice_cancelled'));
});

it('refuses to decide on an invoice that awaits nothing from AEAT', function (): void {
    Verifactu::fake();
    $invoice = Invoice::factory()->create();
    $invoice->sendToVerifactu();
    Verifactu::decide($invoice, InvoiceStatus::Accepted);

    expect(fn () => Verifactu::decide($invoice, InvoiceStatus::Rejected))
        ->toThrow(LogicException::class, "Nothing of the invoice [invoice:{$invoice->id}] awaits AEAT's answer: correct or cancel it first.");
});

it('works without the service\'s URL and token in the app\'s test environment', function (): void {
    config(['verifactu.url' => null, 'verifactu.token' => null]);
    Verifactu::fake();
    $invoice = Invoice::factory()->create();

    $invoice->sendToVerifactu();

    Verifactu::assertIssued($invoice);
});

it('refuses to decide on an invoice it never issued', function (): void {
    Verifactu::fake();
    $invoice = Invoice::factory()->create();

    expect(fn () => Verifactu::decide($invoice, InvoiceStatus::Accepted))
        ->toThrow(LogicException::class, "The invoice [invoice:{$invoice->id}] is not on the fake Verifactu: issue it first.");
});

it('fails :dataset with a clear message', function (Closure $assertion, Closure $message): void {
    Verifactu::fake();
    $invoice = Invoice::factory()->create();
    $issued = Invoice::factory()->create();
    $issued->sendToVerifactu();
    Verifactu::decide($issued, InvoiceStatus::Accepted);

    expect(fn () => $assertion($invoice, $issued))
        ->toThrow(ExpectationFailedException::class, $message($invoice, $issued));
})->with([
    'assertIssued' => [
        fn (Invoice $invoice) => Verifactu::assertIssued($invoice),
        fn (Invoice $invoice) => "Verifactu did not issue the invoice [invoice:{$invoice->id}].",
    ],
    'assertIssued with a callback' => [
        fn (Invoice $invoice, Invoice $issued) => Verifactu::assertIssued($issued, fn (array $body): bool => false),
        fn (Invoice $invoice, Invoice $issued) => "Verifactu issued the invoice [invoice:{$issued->id}], but not as the callback expects.",
    ],
    'assertNothingIssued' => [
        fn () => Verifactu::assertNothingIssued(),
        fn (Invoice $invoice, Invoice $issued) => "Verifactu issued invoices unexpectedly: [invoice:{$issued->id}].",
    ],
    'assertBatchSent' => [
        fn () => Verifactu::assertBatchSent(),
        fn () => 'No batch was sent to Verifactu.',
    ],
    'assertCorrected' => [
        fn (Invoice $invoice, Invoice $issued) => Verifactu::assertCorrected($issued),
        fn (Invoice $invoice, Invoice $issued) => "The invoice [invoice:{$issued->id}] was not corrected on Verifactu.",
    ],
    'assertCancelled' => [
        fn (Invoice $invoice, Invoice $issued) => Verifactu::assertCancelled($issued),
        fn (Invoice $invoice, Invoice $issued) => "The invoice [invoice:{$issued->id}] was not cancelled on Verifactu.",
    ],
]);

it('passes assertNothingIssued when nothing was sent', function (): void {
    Verifactu::fake();
    Invoice::factory()->create();

    Verifactu::assertNothingIssued();
});

it('refuses to issue to a customer AEAT does not know, as the service does', function (): void {
    Event::fake([InvoiceNotIssued::class]);
    Verifactu::fake()->taxIdCheck(TaxIdCheckResult::NotIdentified);
    $invoice = Invoice::factory()->create();

    // On the sync queue, the job's failure reaches the caller once failed() has run.
    expect(fn () => $invoice->sendToVerifactu())
        ->toThrow(fn (VerifactuException $exception) => expect($exception->errorCode)->toBe('recipient_not_identified'));

    Verifactu::assertNothingIssued();
    Event::assertDispatched(InvoiceNotIssued::class, fn (InvoiceNotIssued $event): bool => $event->errorCode === 'recipient_not_identified');
});

it('fails the batch item to a customer AEAT does not know and issues the others', function (): void {
    Event::fake([InvoiceNotIssued::class]);
    Verifactu::fake()->taxIdCheck(TaxIdCheckResult::NotIdentified);
    $toCustomer = Invoice::factory()->create();
    $simplified = Invoice::factory()->simplified()->create();

    Verifactu::sendBatch([$toCustomer, $simplified]);

    Verifactu::assertIssued($simplified);
    expect($toCustomer->fresh()->verifactu_uuid)->toBeNull()
        ->and(InvoiceBatch::sole()->status)->toBe(InvoiceBatchStatus::CompletedWithErrors);
    Event::assertDispatched(InvoiceNotIssued::class, fn (InvoiceNotIssued $event): bool => $event->invoice->is($toCustomer)
        && $event->errorCode === 'recipient_not_identified');
});

it('refuses to fix an invoice to a customer AEAT does not know', function (): void {
    Verifactu::fake();
    $invoice = Invoice::factory()->create();
    $invoice->sendToVerifactu();
    Verifactu::decide($invoice, InvoiceStatus::Rejected, errorCode: '1105');
    Verifactu::taxIdCheck(TaxIdCheckResult::NotIdentified);

    expect(fn () => $invoice->fresh()->correctOnVerifactu('New description'))
        ->toThrow(fn (VerifactuException $exception) => expect($exception->errorCode)->toBe('recipient_not_identified'));
});

it('issues to a customer AEAT does not know when the check is skipped: :dataset', function (array $body): void {
    Verifactu::fake()->taxIdCheck(TaxIdCheckResult::NotIdentified);

    expect(Verifactu::createInvoice([
        'description' => 'Order 1',
        'total_amount' => '121.00',
        'external_reference' => 'order-1',
        ...$body,
    ]))->toHaveKey('uuid');
})->with([
    'validate_recipient false' => [['recipient' => ['tax_id' => 'A15022510', 'name' => 'Acme S.L.'], 'validate_recipient' => false]],
    'no Spanish tax ID' => [['recipient' => ['name' => 'Acme GmbH', 'country_code' => 'DE', 'id_type' => '02', 'id' => 'DE123456789']]],
]);
