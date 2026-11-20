<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
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
use Vlec\Verifactu\Testing\InvoiceRules;
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
    expect($invoices->fresh()->pluck('number')->all())->toBe(['FACT1', 'FACT2', 'FACT3'])
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

    expect(refusalOf(fn () => $change($invoice->fresh())))
        ->status->toBe(409)
        ->errorCode->toBe('invoice_awaiting_aeat');
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

    expect($invoices->fresh()->pluck('number')->all())->toBe(['FACT1', 'FACT2']);
    Event::assertDispatchedTimes(InvoiceIssued::class, 2);
});

it('reads a batch of more than one page of items', function (): void {
    Verifactu::fake();
    $invoices = Invoice::factory()->count(101)->create();

    Verifactu::sendBatch($invoices);

    expect($invoices->last()->fresh()->number)->toBe('FACT101');
    Http::assertSent(fn (Request $request): bool => Str::endsWith($request->url(), '/items?page=2'));
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

it('refuses a batch that repeats a reference, naming the items, as the service does', function (): void {
    Verifactu::fake();
    $message = 'Items that have an external_reference need distinct ones.';

    expect(fn () => Verifactu::createInvoiceBatch(['invoices' => [sale(), sale(['external_reference' => 'order-2']), sale()]]))
        ->toThrow(fn (VerifactuException $exception) => expect($exception)
            ->status->toBe(422)
            ->errorCode->toBe('validation_failed')
            ->getMessage()->toBe($message)
            ->errors->toBe(['invoices.0.external_reference' => [$message], 'invoices.2.external_reference' => [$message]]));

    Verifactu::assertNothingIssued();
});

it('refuses to fix a cancelled invoice, as the service does', function (): void {
    Verifactu::fake();
    $invoice = Invoice::factory()->create();
    $invoice->sendToVerifactu();
    Verifactu::decide($invoice, InvoiceStatus::Accepted);
    $invoice->fresh()->cancelOnVerifactu();
    Verifactu::decide($invoice, InvoiceStatus::Cancelled);

    expect(refusalOf(fn () => $invoice->fresh()->correctOnVerifactu('New description')))
        ->status->toBe(409)
        ->errorCode->toBe('invoice_cancelled');
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

    expect(refusalOf(fn () => $invoice->fresh()->correctOnVerifactu('New description')))
        ->status->toBe(422)
        ->errorCode->toBe('recipient_not_identified')
        ->errors->toBe(['recipient.tax_id' => ['AEAT does not know this tax ID under this name.']]);
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
    'no Spanish tax ID' => [['recipient' => foreignCustomer()]],
]);

/**
 * A POST /invoices body to a Spanish customer.
 */
function sale(array $attributes = []): array
{
    return [
        'description' => 'Web design',
        'total_amount' => '121.00',
        'external_reference' => 'order-1',
        'recipient' => ['tax_id' => 'A15022510', 'name' => 'Acme S.L.'],
        'lines' => [['tax_base' => '100.00', 'operation_qualification' => 'S1', 'tax_rate' => '21', 'tax_amount' => '21.00']],
        ...$attributes,
    ];
}

/**
 * A customer identified by a foreign ID, merged with the given fields.
 */
function foreignCustomer(array $attributes = []): array
{
    return ['name' => 'Acme GmbH', 'country_code' => 'DE', 'id_type' => '02', 'id' => 'DE123456789', ...$attributes];
}

/**
 * The service's refusal of a request the client sent, as an exception.
 */
function refusalOf(Closure $request): VerifactuException
{
    try {
        $request();
    } catch (VerifactuException $exception) {
        return $exception;
    }

    throw new LogicException('The fake Verifactu did not refuse the request.');
}

/**
 * An invoice issued on the fake Verifactu that AEAT has answered for.
 */
function answeredInvoice(InvoiceStatus $status = InvoiceStatus::Accepted): Invoice
{
    $invoice = Invoice::factory()->create();
    $invoice->sendToVerifactu();
    Verifactu::decide($invoice, $status);

    return $invoice->fresh();
}

it('lets a correction change the description and each line\'s codes not printed', function (): void {
    Verifactu::fake();
    $invoice = answeredInvoice(InvoiceStatus::Rejected);
    $body = $invoice->toVerifactuRequest();
    $body['description'] = 'Spanish course, B2';
    $body['lines'][0] = [...$body['lines'][0], 'regime_key' => '01', 'exemption_reason' => 'E6'];

    expect(Verifactu::correctInvoice($invoice->verifactu_uuid, $body))->status->toBe('pending');
    Verifactu::assertCorrected($invoice);
    expect(Verifactu::listInvoices(['include' => 'tax_lines'])['data'][0]['tax_lines'][0]['exemption_reason'])->toBe('E6');
});

it('lets a correction send the fields in another order', function (): void {
    Verifactu::fake();
    $invoice = answeredInvoice(InvoiceStatus::Rejected);
    $body = $invoice->toVerifactuRequest();
    $body['lines'] = Collection::make($body['lines'])->map(fn (array $line): array => Collection::make($line)->reverse()->all())->all();

    expect(Verifactu::correctInvoice($invoice->verifactu_uuid, Collection::make($body)->reverse()->all()))->status->toBe('pending');
});

it('answers 201 to a correction and a cancellation', function (): void {
    Verifactu::fake();
    $invoice = answeredInvoice(InvoiceStatus::Rejected);

    $correction = Verifactu::http()->post("invoices/{$invoice->verifactu_uuid}/corrections", $invoice->toVerifactuRequest());
    Verifactu::decide($invoice, InvoiceStatus::Accepted);
    $cancellation = Verifactu::http()->post("invoices/{$invoice->verifactu_uuid}/cancellation");

    expect($correction->status())->toBe(201)
        ->and($cancellation->status())->toBe(201);
});

it('refuses a correction whose body the service would not issue', function (): void {
    Verifactu::fake();
    $invoice = answeredInvoice(InvoiceStatus::Rejected);

    expect(refusalOf(fn () => Verifactu::correctInvoice($invoice->verifactu_uuid, [...$invoice->toVerifactuRequest(), 'description' => ' '])))
        ->status->toBe(422)
        ->errorCode->toBe('invalid_description');
});

it('refuses a correction that changes :dataset, as the service does', function (Closure $change, string $field): void {
    Verifactu::fake();
    $invoice = answeredInvoice(InvoiceStatus::Rejected);

    $refusal = refusalOf(fn () => Verifactu::correctInvoice($invoice->verifactu_uuid, $change($invoice->toVerifactuRequest())));

    expect($refusal)
        ->status->toBe(422)
        ->errorCode->toBe('correction_changes_fiscal_data')
        ->errors->toBe([$field => [$refusal->getMessage()]]);
})->with([
    'the total' => [fn (array $body): array => [...$body, 'total_amount' => '999.00'], 'total_amount'],
    'the customer' => [fn (array $body): array => [...$body, 'recipient' => ['tax_id' => 'B12345674', 'name' => 'Other S.L.']], 'recipient'],
    'a line\'s base' => [fn (array $body): array => [...$body, 'lines' => [[...$body['lines'][0], 'tax_base' => '1.00'], ...Arr::except($body['lines'], 0)]], 'lines'],
    'the order of its lines' => [fn (array $body): array => [...$body, 'lines' => Collection::make($body['lines'])->reverse()->values()->all()], 'lines'],
    'its lines, left out' => [fn (array $body): array => Arr::except($body, 'lines'), 'lines'],
    'a field the invoice did not have' => [fn (array $body): array => [...$body, 'operation_date' => '2026-11-18'], 'operation_date'],
]);

it('gives the verification code and registration time only when AEAT registers the record: :dataset', function (InvoiceStatus $status, bool $registered): void {
    Verifactu::fake();
    $invoice = answeredInvoice($status);

    $aeat = Verifactu::getInvoice($invoice->verifactu_uuid)['aeat'];

    expect(filled($aeat['verification_code']))->toBe($registered)
        ->and(filled($aeat['registered_at']))->toBe($registered);

    if ($registered) {
        expect($aeat['verification_code'])->toMatch('/^A-[A-Z0-9]{14}$/');
    }
})->with([
    'accepted' => [InvoiceStatus::Accepted, true],
    'accepted with errors' => [InvoiceStatus::AcceptedWithErrors, true],
    'rejected' => [InvoiceStatus::Rejected, false],
]);

it('starts a new record when an invoice is :dataset: no answer yet and a new hash', function (Closure $change): void {
    Verifactu::fake();
    $invoice = answeredInvoice();
    $before = Verifactu::getInvoice($invoice->verifactu_uuid);

    $change($invoice);

    expect(Verifactu::getInvoice($invoice->verifactu_uuid))
        ->status->toBe('pending')
        ->hash->not->toBe($before['hash'])
        ->aeat->toBe(['verification_code' => null, 'error_code' => null, 'error_message' => null, 'registered_at' => null]);
})->with([
    'corrected' => [fn (Invoice $invoice) => $invoice->correctOnVerifactu()],
    'cancelled' => [fn (Invoice $invoice) => $invoice->cancelOnVerifactu()],
]);

it('ends a cancellation AEAT accepts cancelled', function (): void {
    Event::fake([InvoiceCancelled::class]);
    Verifactu::fake();
    $invoice = answeredInvoice();
    $invoice->cancelOnVerifactu();

    Verifactu::decide($invoice, InvoiceStatus::Accepted);

    Event::assertDispatched(InvoiceCancelled::class);
    expect($invoice->fresh()->aeat_status)->toBe(InvoiceStatus::Cancelled)
        ->and(Verifactu::getInvoice($invoice->verifactu_uuid)['aeat']['registered_at'])->not->toBeNull();
});

it('refuses to decide :dataset', function (Closure $setUp, InvoiceStatus $status, ?string $errorCode, string $message): void {
    Verifactu::fake();
    $invoice = answeredInvoice();
    $setUp($invoice);

    expect(fn () => Verifactu::decide($invoice, $status, $errorCode))->toThrow(LogicException::class, $message);
})->with([
    'pending' => [fn (Invoice $invoice) => $invoice->correctOnVerifactu(), InvoiceStatus::Pending, null, 'AEAT answers accepted, accepted with errors or rejected, or registers a cancellation: pending is no answer.'],
    'cancelled when no cancellation awaits' => [fn (Invoice $invoice) => $invoice->correctOnVerifactu(), InvoiceStatus::Cancelled, null, "No cancellation of the invoice [invoice:1] awaits AEAT's answer: cancel it first."],
    'a rejection of a cancellation' => [fn (Invoice $invoice) => $invoice->cancelOnVerifactu(), InvoiceStatus::Rejected, null, "The cancellation of the invoice [invoice:1] awaits AEAT's answer: decide Cancelled."],
    'an error code with an acceptance' => [fn (Invoice $invoice) => $invoice->correctOnVerifactu(), InvoiceStatus::Accepted, '1100', 'only when it accepts a record with errors or rejects it'],
]);

it('lists 25 invoices a page, with the links and meta the service gives', function (): void {
    Verifactu::fake();
    Collection::range(1, 30)->each(fn (int $order) => Verifactu::createInvoice(sale(['external_reference' => "order-{$order}"])));
    $url = 'https://verifactu.test/api/v1/invoices';

    $page = Verifactu::listInvoices(['page' => ['number' => 2]]);

    expect($page['data'])->toHaveCount(5)
        ->and($page['data'][0])->external_reference->toBe('order-26')->not->toHaveKey('qr_code')
        ->and($page['links'])->toBe([
            'first' => "{$url}?page%5Bnumber%5D=1",
            'last' => "{$url}?page%5Bnumber%5D=2",
            'prev' => "{$url}?page%5Bnumber%5D=1",
            'next' => null,
        ])
        ->and($page['meta'])->toBe([
            'current_page' => 2,
            'from' => 26,
            'last_page' => 2,
            'links' => [
                ['url' => "{$url}?page%5Bnumber%5D=1", 'label' => '&laquo; Previous', 'page' => 1, 'active' => false],
                ['url' => "{$url}?page%5Bnumber%5D=1", 'label' => '1', 'page' => 1, 'active' => false],
                ['url' => "{$url}?page%5Bnumber%5D=2", 'label' => '2', 'page' => 2, 'active' => true],
                ['url' => null, 'label' => 'Next &raquo;', 'page' => null, 'active' => false],
            ],
            'path' => $url,
            'per_page' => 25,
            'to' => 30,
            'total' => 30,
        ]);
});

it('keeps the query in the page links and lists at most 100 a page', function (): void {
    Verifactu::fake();
    Collection::range(1, 101)->each(fn (int $order) => Verifactu::createInvoice(sale(['external_reference' => "order-{$order}"])));

    $page = Verifactu::listInvoices(['filter' => ['status' => 'pending'], 'page' => ['size' => 500]]);

    expect($page['data'])->toHaveCount(100)
        ->and($page['meta'])->per_page->toBe(100)->last_page->toBe(2)
        ->and($page['links']['next'])->toBe('https://verifactu.test/api/v1/invoices?filter%5Bstatus%5D=pending&page%5Bsize%5D=500&page%5Bnumber%5D=2');
});

it('filters the list of invoices by :dataset', function (array $filter, array $references): void {
    Verifactu::fake();
    Verifactu::createInvoice(sale(['external_reference' => 'order-1']));
    $this->travelTo('2026-11-20 10:00:00');
    Verifactu::createInvoice(sale(['external_reference' => 'order-2', 'invoice_type' => 'F2', 'recipient' => null]));
    $this->travelTo('2026-11-21 10:00:00');
    Verifactu::createInvoice(sale(['external_reference' => 'ORDER-3', 'recipient' => ['tax_id' => '12345678Z', 'name' => 'Ana Pérez']]));

    $list = Verifactu::listInvoices(['filter' => $filter, 'sort' => 'created_at']);

    expect(Collection::make($list['data'])->pluck('external_reference')->all())->toBe($references);
})->with([
    'number' => [['number' => '2'], ['order-2']],
    'invoice number' => [['invoice_number' => 'FACT3'], ['ORDER-3']],
    'external reference' => [['external_reference' => 'order-1'], ['order-1']],
    // Read as one status, "rejected,pending" would match none.
    'several statuses' => [['status' => 'rejected,pending'], ['order-1', 'order-2', 'ORDER-3']],
    'a status none has' => [['status' => 'rejected'], []],
    'several types' => [['invoice_type' => 'F2,R1'], ['order-2']],
    'the customer\'s tax ID' => [['recipient_tax_id' => '12345678Z'], ['ORDER-3']],
    'issue dates' => [['issue_date_from' => '2026-11-20', 'issue_date_to' => '2026-11-20'], ['order-2']],
    'a search in the reference, whatever its case' => [['search' => 'der-3'], ['ORDER-3']],
    'a search in the customer\'s name' => [['search' => 'pérez'], ['ORDER-3']],
    'a search in the full number' => [['search' => 'FACT2'], ['order-2']],
    'a search in the customer\'s tax ID' => [['search' => '345678Z'], ['ORDER-3']],
]);

it('sorts the list of invoices by :dataset', function (?string $sort, array $references): void {
    Verifactu::fake();
    // Numbered FACT1 to FACT3 in this order, on dates that are not.
    $this->travelTo('2026-11-21 10:00:00');
    Verifactu::createInvoice(sale(['external_reference' => 'order-1', 'total_amount' => '50.00']));
    $this->travelTo('2026-11-19 10:00:00');
    Verifactu::createInvoice(sale(['external_reference' => 'order-2', 'total_amount' => '9.00']));
    $this->travelTo('2026-11-20 10:00:00');
    Verifactu::createInvoice(sale(['external_reference' => 'order-3', 'total_amount' => '100.00']));

    $list = Verifactu::listInvoices(Arr::whereNotNull(['sort' => $sort]));

    expect(Collection::make($list['data'])->pluck('external_reference')->all())->toBe($references);
})->with([
    'the newest issue date first, the default' => [null, ['order-1', 'order-3', 'order-2']],
    'issue date' => ['issue_date', ['order-2', 'order-3', 'order-1']],
    'invoice number, descending' => ['-invoice_number', ['order-3', 'order-2', 'order-1']],
    'total amount' => ['total_amount', ['order-2', 'order-1', 'order-3']],
    'creation, newest first' => ['-created_at', ['order-3', 'order-2', 'order-1']],
]);

it('refuses a list query the service does not allow: :dataset', function (array $query, string $code, string $message): void {
    Verifactu::fake();

    expect(refusalOf(fn () => Verifactu::listInvoices($query)))
        ->status->toBe(400)
        ->errorCode->toBe($code)
        ->getMessage()->toBe($message);
})->with([
    'a filter' => [['filter' => ['customer' => 'Acme']], 'invalid_filter', 'The list has no such filter. Use one of the filters the endpoint lists.'],
    'a sort' => [['sort' => '-number'], 'invalid_sort', 'The list cannot be sorted by this field. Use one of the sorts the endpoint lists.'],
    'a sort sent as a list' => [['sort' => ['issue_date']], 'invalid_sort', 'The list cannot be sorted by this field. Use one of the sorts the endpoint lists.'],
    'an include' => [['include' => 'tax_lines,recipient'], 'invalid_include', 'The list cannot include this relation. Use one of the includes the endpoint lists.'],
]);

it('sorts the list by the next field when the first one ties', function (): void {
    Verifactu::fake();
    Verifactu::createInvoice(sale(['external_reference' => 'order-1']));
    Verifactu::createInvoice(sale(['external_reference' => 'order-2']));

    $list = Verifactu::listInvoices(['sort' => 'issue_date,-invoice_number']);

    expect(Collection::make($list['data'])->pluck('external_reference')->all())->toBe(['order-2', 'order-1']);
});

it('reads the page and its size as the service does: :dataset', function (array $page, int $number, int $size): void {
    Verifactu::fake();
    Collection::range(1, 3)->each(fn (int $order) => Verifactu::createInvoice(sale(['external_reference' => "order-{$order}"])));

    expect(Verifactu::listInvoices(['page' => $page])['meta'])
        ->current_page->toBe($number)
        ->per_page->toBe($size);
})->with([
    'the first page of 25, the default' => [[], 1, 25],
    'a page number below one' => [['number' => 0], 1, 25],
    'a page number that is not a number' => [['number' => 'last'], 1, 25],
    'a page of one' => [['size' => 1, 'number' => 3], 3, 1],
    'a page of ten' => [['size' => 10], 1, 10],
    'a page size below one' => [['size' => 0], 1, 25],
    'a page size that is not a number' => [['size' => 'all'], 1, 25],
]);

it('includes the tax lines, references and AEAT records of each invoice in the list', function (): void {
    Verifactu::fake();
    $invoice = answeredInvoice();
    $invoice->cancelOnVerifactu();
    Verifactu::createInvoice(sale([
        'external_reference' => 'refund-1',
        'invoice_type' => 'R1',
        'total_amount' => '-24.20',
        'lines' => [['tax_base' => '-20.00', 'operation_qualification' => 'S1', 'tax_rate' => '21', 'tax_amount' => '-4.20']],
        'correction' => ['method' => 'difference', 'corrected_invoices' => [['number' => '1', 'issue_date' => '2026-11-19']]],
    ]));

    [$cancelled, $refund] = Verifactu::listInvoices(['include' => 'tax_lines,references,billing_records', 'sort' => 'created_at'])['data'];

    expect($cancelled['tax_lines'])->toBe([[
        'tax_type' => '01',
        'regime_key' => '01',
        'operation_qualification' => null,
        'exemption_reason' => 'E1',
        'tax_base' => '300.00',
        'tax_rate' => null,
        'tax_amount' => null,
        'equivalence_surcharge_rate' => null,
        'equivalence_surcharge_amount' => null,
    ], [
        'tax_type' => '01',
        'regime_key' => '01',
        'operation_qualification' => 'S1',
        'exemption_reason' => null,
        'tax_base' => '20.00',
        'tax_rate' => '21',
        'tax_amount' => '4.20',
        'equivalence_surcharge_rate' => null,
        'equivalence_surcharge_amount' => null,
    ]])
        ->and(Collection::make($cancelled['billing_records'])->map(fn (array $record): array => [$record['type'], $record['status']])->all())
        ->toBe([['registration', 'accepted'], ['cancellation', 'pending']])
        ->and(Arr::last($cancelled['billing_records'])['hash'])->toBe($cancelled['hash'])
        ->and(Arr::first($cancelled['billing_records']))->toHaveKey('uuid')->generated_at->toBe('2026-11-19T11:00:00+01:00')
        ->and($refund['references'])->toBe([['kind' => 'corrected', 'invoice_number' => 'FACT1', 'issue_date' => '2026-11-19']]);
});

it('replays the first answer to a repeated Idempotency-Key, refusals included', function (): void {
    Verifactu::fake();
    $send = fn (array $body) => Verifactu::http()->withHeaders(['Idempotency-Key' => 'order-1'])->post('invoices', $body);

    $first = $send(sale());
    $replay = $send(sale());

    expect($first)->status()->toBe(201)->header('Idempotent-Replayed')->toBe('')
        ->and($replay)->status()->toBe(201)->header('Idempotent-Replayed')->toBe('true')
        ->and($replay->json('data.uuid'))->toBe($first->json('data.uuid'))
        ->and(Verifactu::listInvoices()['meta']['total'])->toBe(1);

    $refusal = fn () => Verifactu::http()->withHeaders(['Idempotency-Key' => 'order-2'])->post('invoices', sale(['external_reference' => 'order-2', 'recipient' => null]));

    expect($refusal())->status()->toBe(422)
        ->and($refusal())->status()->toBe(422)->header('Idempotent-Replayed')->toBe('true');
});

it('refuses an Idempotency-Key used with another request', function (): void {
    Verifactu::fake();
    Verifactu::createInvoice(sale(), 'key-1');

    expect(refusalOf(fn () => Verifactu::createInvoice(sale(['external_reference' => 'order-2']), 'key-1')))
        ->status->toBe(422)->errorCode->toBe('idempotency_key_reused')
        ->and(refusalOf(fn () => Verifactu::cancelInvoice(Verifactu::listInvoices()['data'][0]['uuid'], 'key-1')))
        ->errorCode->toBe('idempotency_key_reused');
});

it('takes a request without an Idempotency-Key as a new one', function (): void {
    Verifactu::fake();

    $first = Verifactu::http()->post('invoices', sale());
    $second = Verifactu::http()->post('invoices', sale(['external_reference' => 'order-2']));

    expect($first->status())->toBe(201)
        ->and($second->status())->toBe(201);
});

it('ignores an Idempotency-Key on the requests that take none', function (): void {
    Verifactu::fake();
    $key = ['Idempotency-Key' => 'key-1'];
    Verifactu::http()->withHeaders($key)->post('tax-id-checks', ['tax_id' => 'A15022510', 'name' => 'Acme S.L.']);

    $check = Verifactu::http()->withHeaders($key)->post('tax-id-checks', ['tax_id' => '12345678Z', 'name' => 'Ana Pérez']);
    $list = Verifactu::http()->withHeaders($key)->get('invoices');

    expect($check->json('data'))->toBe(['tax_id' => '12345678Z', 'result' => 'identified', 'official_name' => null])
        ->and($list->status())->toBe(200);
});

it('refuses a batch without an Idempotency-Key', function (): void {
    Verifactu::fake();

    $response = Verifactu::http()->post('invoice-batches', ['invoices' => [sale()]]);

    expect($response->status())->toBe(422)
        ->and($response->json('code'))->toBe('idempotency_key_required');
});

it('leaves the requests it does not play to the app\'s own fake', function (): void {
    Verifactu::fake();
    Http::fake(['verifactu.test/api/v1/invoices/*/xml' => Http::response(['data' => ['records' => []]])]);
    $uuid = Verifactu::createInvoice(sale())['uuid'];

    expect(Verifactu::http()->get("invoices/{$uuid}/xml")->json())->toBe(['data' => ['records' => []]])
        ->and(fn () => Verifactu::http()->get("invoices/{$uuid}/aeat-status"))
        ->toThrow(StrayRequestException::class, "invoices/{$uuid}/aeat-status");
});

it('answers 404 for an invoice or batch it does not hold', function (Closure $request): void {
    Verifactu::fake();

    expect(refusalOf($request))->status->toBe(404)->getMessage()->toBe('Not found.');
})->with([
    'read' => [fn () => Verifactu::getInvoice('unknown')],
    'correct' => [fn () => Verifactu::correctInvoice('unknown', sale())],
    'cancel' => [fn () => Verifactu::cancelInvoice('unknown')],
    'read a batch' => [fn () => Verifactu::getInvoiceBatch('unknown')],
    'read a batch\'s items' => [fn () => Verifactu::listInvoiceBatchItems('unknown')],
]);

it('refuses an invoice body as the service does: :dataset', function (array $body, string $code, string $field): void {
    Verifactu::fake();

    expect(refusalOf(fn () => Verifactu::createInvoice($body)))
        ->status->toBe(422)
        ->errorCode->toBe($code)
        ->errors->toHaveKey($field);

    Verifactu::assertNothingIssued();
})->with([
    'an issue date' => [sale(['issue_date' => '2026-11-19']), 'issue_date_is_assigned_by_service', 'issue_date'],
    'a series' => [sale(['series' => 'A']), 'series_is_assigned_by_service', 'series'],
    'a number' => [sale(['number' => '7']), 'number_is_assigned_by_service', 'number'],
    'no description' => [Arr::except(sale(), 'description'), 'invalid_description', 'description'],
    'a blank description' => [sale(['description' => ' ']), 'invalid_description', 'description'],
    'a new line in the description' => [sale(['description' => "Web design\nNovember"]), 'control_character_not_allowed', 'description'],
    'a tab in the customer\'s name' => [sale(['recipient' => ['tax_id' => 'A15022510', 'name' => "Acme\tS.L."]]), 'control_character_not_allowed', 'recipient.name'],
    'a customer without a name' => [sale(['recipient' => ['tax_id' => 'A15022510']]), 'invalid_recipient_name', 'recipient.name'],
    'a customer\'s name over 120 characters' => [sale(['recipient' => ['tax_id' => 'A15022510', 'name' => Str::repeat('a', 121)]]), 'invalid_recipient_name', 'recipient.name'],
    'a simplified invoice over 3,000.00' => [sale(['invoice_type' => 'F2', 'recipient' => null, 'total_amount' => '3000.01', 'lines' => [['tax_base' => '3000.01', 'exemption_reason' => 'E1']]]), 'simplified_invoice_over_limit', 'total_amount'],
    'no total' => [Arr::except(sale(), 'total_amount'), 'field_required', 'total_amount'],
    'a total as a number' => [sale(['total_amount' => 121]), 'invalid_amount', 'total_amount'],
    'a line amount with a comma' => [sale(['lines' => [['tax_base' => '100,00', 'exemption_reason' => 'E1']]]), 'invalid_amount', 'lines.0.tax_base'],
    'a reference over 100 characters' => [sale(['external_reference' => Str::repeat('a', 101)]), 'invalid_external_reference', 'external_reference'],
    'a line without a base' => [sale(['lines' => [['exemption_reason' => 'E1']]]), 'field_required', 'lines.0.tax_base'],
    'an unknown type' => [sale(['invoice_type' => 'F9']), 'invalid_invoice_type', 'invoice_type'],
    'a full invoice without a recipient' => [Arr::except(sale(), 'recipient'), 'recipient_required', 'recipient'],
    'a simplified invoice with a recipient' => [sale(['invoice_type' => 'F2']), 'recipient_not_allowed', 'recipient'],
    'a wrong check letter' => [sale(['recipient' => ['tax_id' => '12345678A', 'name' => 'Ana Pérez']]), 'invalid_tax_id', 'recipient.tax_id'],
    'an empty list of lines' => [sale(['lines' => [], 'total_amount' => '0.00']), 'invalid_lines', 'lines'],
    'more than 12 lines' => [sale(['lines' => Collection::times(13, fn (): array => ['tax_base' => '1.00', 'exemption_reason' => 'E1'])->all(), 'total_amount' => '13.00']), 'invalid_lines', 'lines'],
    'a rate with three decimals' => [sale(['lines' => [['tax_base' => '100.00', 'operation_qualification' => 'S1', 'tax_rate' => '21.001', 'tax_amount' => '21.00']]]), 'invalid_tax_rate', 'lines.0.tax_rate'],
    'a corrected invoice without its date' => [sale(['invoice_type' => 'R1', 'correction' => ['method' => 'difference', 'corrected_invoices' => [['number' => '7']]]]), 'field_required', 'correction.corrected_invoices.0.issue_date'],
    'a corrected invoice without its number' => [sale(['invoice_type' => 'R1', 'correction' => ['method' => 'difference', 'corrected_invoices' => [['issue_date' => '2026-11-02']]]]), 'invalid_referenced_invoice', 'correction.corrected_invoices.0'],
    'a replaced invoice by both numbers' => [sale(['invoice_type' => 'F3', 'replaced_invoices' => [['number' => '7', 'invoice_number' => 'FACT7', 'issue_date' => '2026-11-02']]]), 'invalid_referenced_invoice', 'replaced_invoices.0'],
    'a description over 500 characters' => [sale(['description' => Str::repeat('a', 501)]), 'invalid_description', 'description'],
    'a blank customer\'s name' => [sale(['recipient' => ['tax_id' => 'A15022510', 'name' => ' ']]), 'invalid_recipient_name', 'recipient.name'],
    'lines that are not a list' => [sale(['lines' => ['first' => ['tax_base' => '121.00', 'exemption_reason' => 'E1']]]), 'invalid_lines', 'lines'],
    'lines that are text' => [sale(['lines' => 'one exempt line']), 'invalid_lines', 'lines'],
    'a country code that is not ISO 3166-1' => [sale(['recipient' => foreignCustomer(['country_code' => 'XX'])]), 'invalid_country_code', 'recipient.country_code'],
    'an intra-EU supply to a Spanish customer' => [sale(['lines' => [['tax_base' => '121.00', 'exemption_reason' => 'E5']]]), 'exempt_intra_eu_supply_requires_foreign_recipient', 'recipient'],
    'an intra-EU supply to a foreign customer without an ID' => [sale(['recipient' => foreignCustomer(['id' => null]), 'lines' => [['tax_base' => '100.00', 'operation_qualification' => 'S1', 'tax_rate' => '21', 'tax_amount' => '21.00'], ['tax_base' => '0.00', 'exemption_reason' => 'E5']]]), 'exempt_intra_eu_supply_requires_foreign_recipient', 'recipient'],
    'an intra-EU supply on a simplified invoice' => [sale(['invoice_type' => 'F2', 'recipient' => null, 'lines' => [['tax_base' => '121.00', 'exemption_reason' => 'E5']]]), 'exempt_intra_eu_supply_requires_foreign_recipient', 'recipient'],
]);

it('issues an invoice at the edge of the service\'s rules: :dataset', function (array $body): void {
    Verifactu::fake();

    expect(Verifactu::createInvoice($body))->status->toBe('pending');
})->with([
    'a description of 500 characters' => [sale(['description' => Str::repeat('a', 500)])],
    'a reference of 100 characters' => [sale(['external_reference' => Str::repeat('a', 100)])],
    'a customer\'s name of 120 characters' => [sale(['recipient' => ['tax_id' => 'A15022510', 'name' => Str::repeat('a', 120)]])],
    '12 lines' => [sale(['lines' => Collection::times(12, fn (): array => ['tax_base' => '1.00', 'exemption_reason' => 'E1'])->all(), 'total_amount' => '12.00'])],
    'a simplified invoice of 3,000.00' => [sale(['invoice_type' => 'F2', 'recipient' => null, 'total_amount' => '3000.00', 'lines' => [['tax_base' => '3000.00', 'exemption_reason' => 'E1']]])],
    'a full invoice over 3,000.00' => [sale(['total_amount' => '3630.00', 'lines' => [['tax_base' => '3000.00', 'operation_qualification' => 'S1', 'tax_rate' => '21', 'tax_amount' => '630.00']]])],
    'an intra-EU supply to a customer with a foreign ID' => [sale(['recipient' => foreignCustomer(), 'lines' => [['tax_base' => '121.00', 'exemption_reason' => 'E5']]])],
]);

it('refuses a second invoice with the same external reference, as the service does', function (): void {
    Verifactu::fake();
    Verifactu::createInvoice(sale());

    expect(refusalOf(fn () => Verifactu::createInvoice(sale())))
        ->status->toBe(409)
        ->errorCode->toBe('external_reference_already_used');
});

it('issues an invoice pending AEAT\'s answer, with the verification URL AEAT prints', function (): void {
    Verifactu::fake();

    $invoice = Verifactu::createInvoice(sale());

    expect($invoice)
        ->environment->toBe('test')
        ->series->toBe('FACT')
        ->status->toBe('pending')
        ->hash->toMatch('/^[0-9A-F]{64}$/')
        ->aeat->toBe(['verification_code' => null, 'error_code' => null, 'error_message' => null, 'registered_at' => null])
        ->verification_url->toBe('https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR?nif=B00000000&numserie=FACT1&fecha=19-11-2026&importe=121.00');
});

it('lists an invoice sent without lines with one exempt line for its total', function (): void {
    Verifactu::fake();
    Verifactu::createInvoice(Arr::except(sale(['total_amount' => '50.00']), 'lines'));

    expect(Verifactu::listInvoices(['include' => 'tax_lines'])['data'][0]['tax_lines'])->toBe([[
        'tax_type' => '01',
        'regime_key' => '01',
        'operation_qualification' => null,
        'exemption_reason' => 'E1',
        'tax_base' => '50.00',
        'tax_rate' => null,
        'tax_amount' => null,
        'equivalence_surcharge_rate' => null,
        'equivalence_surcharge_amount' => null,
    ]]);
});

it('lists the simplified invoices an F3 replaces among its references', function (): void {
    Verifactu::fake();
    Verifactu::createInvoice(sale([
        'invoice_type' => 'F3',
        'replaced_invoices' => [['invoice_number' => 'T-7', 'issue_date' => '2026-11-02'], ['number' => '8', 'issue_date' => '2026-11-03']],
    ]));

    expect(Verifactu::listInvoices(['include' => 'references'])['data'][0]['references'])->toBe([
        ['kind' => 'replaced', 'invoice_number' => 'T-7', 'issue_date' => '2026-11-02'],
        ['kind' => 'replaced', 'invoice_number' => 'FACT8', 'issue_date' => '2026-11-03'],
    ]);
});

it('hands the service\'s refusal of an invoice the app described wrongly to InvoiceNotIssued', function (): void {
    Event::fake([InvoiceNotIssued::class]);
    Verifactu::fake();
    $invoice = Invoice::factory()->create(['exempt_cents' => 0, 'taxable_cents' => 0]);

    rescue(fn () => $invoice->sendToVerifactu(), report: false);

    Event::assertDispatched(InvoiceNotIssued::class, fn (InvoiceNotIssued $event): bool => $event->invoice->is($invoice) && $event->errorCode === 'invalid_lines');
    Verifactu::assertNothingIssued();
});

it('fails only the batch item the service refuses, and issues the others', function (): void {
    Event::fake([InvoiceIssued::class, InvoiceNotIssued::class]);
    Verifactu::fake();
    [$first, $empty, $last] = [Invoice::factory()->create(), Invoice::factory()->create(['exempt_cents' => 0, 'taxable_cents' => 0]), Invoice::factory()->create()];

    Verifactu::sendBatch([$first, $empty, $last]);

    Verifactu::assertIssued($first);
    Verifactu::assertIssued($last);
    Event::assertDispatchedTimes(InvoiceIssued::class, 2);
    Event::assertDispatched(InvoiceNotIssued::class, fn (InvoiceNotIssued $event): bool => $event->invoice->is($empty) && $event->errorCode === 'invalid_lines');
});

it('fails the batch item the service refuses, with the fields', function (): void {
    Verifactu::fake();

    $batch = Verifactu::createInvoiceBatch(['invoices' => [
        sale(),
        sale(['external_reference' => 'order-2', 'recipient' => ['tax_id' => 'A15022511', 'name' => 'Acme S.L.']]),
    ]], 'batch-1');

    [$issued, $failed] = Verifactu::listInvoiceBatchItems($batch['uuid'])['data'];

    expect($issued)
        ->toMatchArray(['index' => 0, 'external_reference' => 'order-1', 'result' => 'issued', 'error' => null])
        ->invoice->invoice_number->toBe('FACT1')
        ->and($failed)->toBe([
            'index' => 1,
            'external_reference' => 'order-2',
            'result' => 'failed',
            'invoice' => null,
            'error' => [
                'code' => 'invalid_tax_id',
                'message' => InvoiceRules::InvalidTaxId,
                'errors' => ['recipient.tax_id' => [InvoiceRules::InvalidTaxId]],
            ],
        ]);
});

it('answers a batch queued, and completed when it is read', function (): void {
    Verifactu::fake();

    $response = Verifactu::http()->withHeaders(['Idempotency-Key' => 'batch-1'])->post('invoice-batches', ['invoices' => [
        sale(),
        sale(['external_reference' => 'order-2', 'recipient' => null]),
    ]]);
    $uuid = $response->json('data.uuid');

    expect($response->status())->toBe(202)
        ->and(Arr::except($response->json('data'), 'uuid'))->toBe([
            'environment' => 'test',
            'status' => 'queued',
            'total' => 2,
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'created_at' => '2026-11-19T11:00:00+01:00',
            'completed_at' => null,
        ])
        ->and(Arr::except(Verifactu::getInvoiceBatch($uuid), 'uuid'))->toBe([
            'environment' => 'test',
            'status' => 'completed_with_errors',
            'total' => 2,
            'processed' => 2,
            'succeeded' => 1,
            'failed' => 1,
            'created_at' => '2026-11-19T11:00:00+01:00',
            'completed_at' => '2026-11-19T11:00:00+01:00',
        ]);
});

it('refuses a batch without its invoices, as the service does', function (): void {
    Verifactu::fake();

    expect(refusalOf(fn () => Verifactu::createInvoiceBatch(['invoices' => 'none'])))
        ->status->toBe(422)
        ->errorCode->toBe('validation_failed')
        ->errors->toBe(['invoices' => ['The invoices field is required.']]);
});

it('reads a batch\'s items 100 a page, from the first: :dataset', function (string $query, int $page): void {
    Verifactu::fake();
    $uuid = Verifactu::createInvoiceBatch(['invoices' => [sale()]])['uuid'];

    expect(Verifactu::http()->get("invoice-batches/{$uuid}/items{$query}")->json('meta'))
        ->current_page->toBe($page)
        ->per_page->toBe(100);
})->with([
    'no page' => ['', 1],
    'a page below one' => ['?page=0', 1],
    'a page that is not a number' => ['?page=last', 1],
    'the second page' => ['?page=2', 2],
]);

it('accepts a valid Spanish tax ID: :dataset', function (string $taxId): void {
    Verifactu::fake();

    expect(Verifactu::checkTaxId($taxId, 'Acme S.L.')->result)->toBe(TaxIdCheckResult::Identified);
})->with([
    'a company, digit control' => ['A15022510'],
    'a company with a nine where digits count twice' => ['A91234567'],
    'a company, lower case' => ['a15022510'],
    'an entity of either control, digit' => ['G12345674'],
    'an entity of either control, letter' => ['G1234567D'],
    'a public body, letter control' => ['Q2826000H'],
    'a DNI' => ['12345678Z'],
    'an NIE starting X' => ['X1234567L'],
    'an NIE starting Y' => ['Y1234567X'],
    'an NIE starting Z' => ['Z1234567R'],
    'a K NIF' => ['K1234567L'],
]);

it('refuses a tax-ID check as the service does: :dataset', function (array $body, string $code, string $field): void {
    Verifactu::fake();

    $response = Verifactu::http()->post('tax-id-checks', $body);

    expect($response->status())->toBe(422)
        ->and($response->json('code'))->toBe($code)
        ->and($response->json('errors'))->toHaveKey($field);
})->with([
    'no tax ID' => [['name' => 'Acme S.L.'], 'field_required', 'tax_id'],
    'no name' => [['tax_id' => 'A15022510'], 'field_required', 'name'],
    'a name over 120 characters' => [['tax_id' => 'A15022510', 'name' => Str::repeat('a', 121)], 'invalid_recipient_name', 'name'],
    'a wrong DNI letter' => [['tax_id' => '12345678A', 'name' => 'Ana Pérez'], 'invalid_tax_id', 'tax_id'],
    'a wrong NIE letter' => [['tax_id' => 'X1234567A', 'name' => 'Ana Pérez'], 'invalid_tax_id', 'tax_id'],
    'a wrong company digit' => [['tax_id' => 'A15022511', 'name' => 'Acme S.L.'], 'invalid_tax_id', 'tax_id'],
    'a letter where a company has a digit' => [['tax_id' => 'B1234567D', 'name' => 'Acme S.L.'], 'invalid_tax_id', 'tax_id'],
    'a digit where a public body has a letter' => [['tax_id' => 'Q28260008', 'name' => 'Ayuntamiento'], 'invalid_tax_id', 'tax_id'],
    'a wrong format' => [['tax_id' => '1234', 'name' => 'Acme S.L.'], 'invalid_tax_id', 'tax_id'],
]);

it('answers in the token\'s environment: :dataset', function (string $token, string $environment): void {
    config(['verifactu.token' => $token]);
    Verifactu::fake();

    expect(Verifactu::createInvoice(sale())['environment'])->toBe($environment)
        ->and(Verifactu::createInvoiceBatch(['invoices' => [sale(['external_reference' => 'order-2'])]], 'batch-1')['environment'])->toBe($environment);
})->with([
    'test' => ['vf_test_token-for-tests', 'test'],
    'live' => ['vf_prod_token-for-tests', 'production'],
]);

it('refuses a token that is not the service\'s', function (): void {
    config(['verifactu.token' => 'not-a-verifactu-token']);
    Verifactu::fake();

    expect(refusalOf(fn () => Verifactu::createInvoice(sale())))->status->toBe(401)->getMessage()->toBe('Unauthenticated.');
});
