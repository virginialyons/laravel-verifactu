<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Testing;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\Fake;
use LogicException;
use PHPUnit\Framework\Assert as PHPUnit;
use Vlec\Verifactu\Client;
use Vlec\Verifactu\Contracts\VerifactuInvoice;
use Vlec\Verifactu\InvoiceStatus;
use Vlec\Verifactu\Jobs\ApplyInvoiceBatch;
use Vlec\Verifactu\Jobs\RefreshInvoice;
use Vlec\Verifactu\Models\InvoiceBatch;
use Vlec\Verifactu\TaxIdCheck;
use Vlec\Verifactu\TaxIdCheckResult;

/**
 * Plays the Verifactu service in the app's tests: it answers the package's
 * requests like the service, so the real jobs, trait and events run. It
 * numbers invoices in order, keeps them in memory and completes batches at
 * once; decide() plays AEAT's answer.
 *
 * Its invoices live in the test's memory, so the jobs must run in the test:
 * on the sync queue, not with Queue::fake(). Call Verifactu::fake() before
 * any catch-all Http::fake(), whose answers would come first.
 */
class VerifactuFake extends Client implements Fake
{
    /**
     * A 1×1 PNG, so an app that prints the QR code gets a real image.
     */
    protected const string RecipientNotIdentified = 'AEAT does not know this tax ID under this name.';

    protected const string QrCode = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    /**
     * The issued invoices, by uuid.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $invoices = [];

    /**
     * The body each invoice was issued from, by uuid.
     *
     * @var array<string, array<mixed>>
     */
    protected array $requests = [];

    /**
     * The uuids of the invoices corrected.
     *
     * @var list<string>
     */
    protected array $corrections = [];

    /**
     * The uuids of the invoices cancelled.
     *
     * @var list<string>
     */
    protected array $cancellations = [];

    /**
     * The batches, completed, with their items, by uuid.
     *
     * @var array<string, array{batch: array<string, mixed>, items: array<int, array<string, mixed>>}>
     */
    protected array $batches = [];

    protected int $lastNumber = 0;

    /**
     * AEAT's answer to every tax-ID check.
     */
    protected TaxIdCheck $taxIdCheck;

    public function __construct()
    {
        $this->taxIdCheck = new TaxIdCheck(TaxIdCheckResult::Identified);

        if (blank(config('verifactu.url'))) {
            config(['verifactu.url' => 'https://verifactu.test']);
        }

        if (blank(config('verifactu.token'))) {
            config(['verifactu.token' => 'vf_test_fake']);
        }

        $url = Str::finish(config()->string('verifactu.url'), '/');

        // Clones from withoutRetries() send to this closure, so the state stays on this instance.
        Http::fake(["{$url}api/v1/*" => fn (Request $request): PromiseInterface => $this->answer($request)]);
    }

    /**
     * Sends the batch, which the fake service completes at once, then
     * applies it, as the invoice_batch.completed webhook would.
     *
     * @param  array<string, mixed>  $batch
     * @return array<string, mixed>
     */
    public function createInvoiceBatch(array $batch, ?string $idempotencyKey = null): array
    {
        /** @var array{uuid: string, status: string} $answer */
        $answer = parent::createInvoiceBatch($batch, $idempotencyKey);

        ApplyInvoiceBatch::dispatch(InvoiceBatch::firstOrCreate(['uuid' => $answer['uuid']], ['status' => $answer['status']]));

        return $answer;
    }

    /**
     * Plays AEAT's answer for the invoice, then refreshes it, as the
     * invoices.updated webhook would.
     */
    public function decide(Model&VerifactuInvoice $invoice, InvoiceStatus $status, ?string $errorCode = null, ?string $errorMessage = null): static
    {
        $uuid = $this->uuidOf($invoice);

        if ($uuid === null) {
            throw new LogicException("The invoice [{$invoice->verifactuReference()}] is not on the fake Verifactu: issue it first.");
        }

        if ($this->invoices[$uuid]['status'] !== InvoiceStatus::Pending->value) {
            throw new LogicException("Nothing of the invoice [{$invoice->verifactuReference()}] awaits AEAT's answer: correct or cancel it first.");
        }

        $this->invoices[$uuid]['status'] = $status->value;
        $this->invoices[$uuid]['aeat'] = [
            'verification_code' => null,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'registered_at' => Date::now()->toIso8601String(),
        ];

        RefreshInvoice::dispatch($invoice);

        return $this;
    }

    /**
     * Sets AEAT's answer to every tax-ID check from now on, such as
     * NotIdentified to see the app refuse a customer. Similar takes the
     * official name AEAT holds. With NotIdentified, issuing or fixing an
     * invoice to a Spanish tax ID is refused with recipient_not_identified,
     * as the service does, unless the body sets validate_recipient to false.
     */
    public function taxIdCheck(TaxIdCheckResult $result, ?string $officialName = null): static
    {
        if ($result === TaxIdCheckResult::Similar && $officialName === null) {
            throw new LogicException('Give the official name AEAT holds with a Similar answer.');
        }

        $this->taxIdCheck = new TaxIdCheck($result, $officialName);

        return $this;
    }

    /**
     * Asserts the invoice was issued, on its own or in a batch, and that the
     * callback, given the body it was issued from, returns true.
     *
     * @param  (Closure(array<mixed>): bool)|null  $callback
     */
    public function assertIssued(Model&VerifactuInvoice $invoice, ?Closure $callback = null): void
    {
        $reference = $invoice->verifactuReference();
        $uuid = $this->uuidOf($invoice);

        PHPUnit::assertNotNull($uuid, "Verifactu did not issue the invoice [{$reference}].");

        if ($callback === null) {
            return;
        }

        PHPUnit::assertTrue(
            $callback($this->requests[$uuid]),
            "Verifactu issued the invoice [{$reference}], but not as the callback expects.",
        );
    }

    public function assertNothingIssued(): void
    {
        $references = Collection::make($this->invoices)->pluck('external_reference')->implode(', ');

        PHPUnit::assertEmpty($this->invoices, "Verifactu issued invoices unexpectedly: [{$references}].");
    }

    public function assertBatchSent(): void
    {
        PHPUnit::assertNotEmpty($this->batches, 'No batch was sent to Verifactu.');
    }

    public function assertCorrected(Model&VerifactuInvoice $invoice): void
    {
        $uuid = $this->uuidOf($invoice);

        PHPUnit::assertTrue(
            $uuid !== null && in_array($uuid, $this->corrections, true),
            "The invoice [{$invoice->verifactuReference()}] was not corrected on Verifactu.",
        );
    }

    public function assertCancelled(Model&VerifactuInvoice $invoice): void
    {
        $uuid = $this->uuidOf($invoice);

        PHPUnit::assertTrue(
            $uuid !== null && in_array($uuid, $this->cancellations, true),
            "The invoice [{$invoice->verifactuReference()}] was not cancelled on Verifactu.",
        );
    }

    protected function answer(Request $request): PromiseInterface
    {
        $path = Str::of($request->url())->before('?')->after('/api/v1/')->toString();
        $method = $request->method();
        $data = $request->data();

        if ($method === 'POST' && $path === 'invoices') {
            return $this->issue($data);
        }

        if ($method === 'GET' && $path === 'invoices') {
            return $this->list($data);
        }

        if ($method === 'POST' && Str::is('invoices/*/corrections', $path)) {
            return $this->correct(Str::between($path, 'invoices/', '/corrections'), $data);
        }

        if ($method === 'POST' && Str::is('invoices/*/cancellation', $path)) {
            return $this->cancel(Str::between($path, 'invoices/', '/cancellation'));
        }

        if ($method === 'GET' && Str::is('invoices/*', $path)) {
            return $this->show(Str::after($path, 'invoices/'));
        }

        if ($method === 'POST' && $path === 'invoice-batches') {
            return $this->issueBatch($data);
        }

        if ($method === 'GET' && Str::is('invoice-batches/*/items', $path)) {
            return $this->batchItems(Str::between($path, 'invoice-batches/', '/items'), $data);
        }

        if ($method === 'GET' && Str::is('invoice-batches/*', $path)) {
            return $this->showBatch(Str::after($path, 'invoice-batches/'));
        }

        if ($method === 'POST' && $path === 'tax-id-checks') {
            return Http::response(['data' => [
                'tax_id' => $data['tax_id'] ?? null,
                'result' => $this->taxIdCheck->result->value,
                'official_name' => $this->taxIdCheck->officialName,
            ]]);
        }

        return $this->notFound();
    }

    /**
     * @param  array<mixed>  $body
     */
    protected function issue(array $body): PromiseInterface
    {
        if ($this->referenceIsTaken($body)) {
            return $this->error(409, 'external_reference_already_used', 'An invoice with this external reference was already issued.');
        }

        if ($this->recipientIsNotIdentified($body)) {
            return $this->error(422, 'recipient_not_identified', self::RecipientNotIdentified);
        }

        return Http::response(['data' => $this->store($body)], 201);
    }

    /**
     * @param  array<mixed>  $query
     */
    protected function list(array $query): PromiseInterface
    {
        $invoices = Collection::make($this->invoices);

        $reference = Arr::get($query, 'filter.external_reference');

        if (is_string($reference)) {
            $invoices = $invoices->where('external_reference', $reference);
        }

        return $this->page($invoices->map(fn (array $invoice): array => Arr::except($invoice, 'qr_code'))->values()->all(), 1);
    }

    protected function show(string $uuid): PromiseInterface
    {
        if (! isset($this->invoices[$uuid])) {
            return $this->notFound();
        }

        return Http::response(['data' => $this->invoices[$uuid]]);
    }

    /**
     * @param  array<mixed>  $body
     */
    protected function correct(string $uuid, array $body): PromiseInterface
    {
        $refusal = $this->refuseChange($uuid);

        if ($refusal !== null) {
            return $refusal;
        }

        if ($this->recipientIsNotIdentified($body)) {
            return $this->error(422, 'recipient_not_identified', self::RecipientNotIdentified);
        }

        $this->corrections[] = $uuid;

        return $this->awaitAeat($uuid);
    }

    protected function cancel(string $uuid): PromiseInterface
    {
        $refusal = $this->refuseChange($uuid);

        if ($refusal !== null) {
            return $refusal;
        }

        $this->cancellations[] = $uuid;

        return $this->awaitAeat($uuid);
    }

    /**
     * Issues each invoice now and answers as the service does, with the
     * batch queued; reading it afterwards finds it completed.
     *
     * @param  array<mixed>  $body
     */
    protected function issueBatch(array $body): PromiseInterface
    {
        /** @var list<array<string, mixed>> $invoices */
        $invoices = $body['invoices'] ?? [];

        $references = Collection::make($invoices)->pluck('external_reference')->filter();

        if ($references->duplicates()->isNotEmpty()) {
            return $this->error(422, 'validation_failed', 'Items that have an external_reference need distinct ones.');
        }

        $items = Collection::make($invoices)->map(function (array $invoice, int $index): array {
            $item = ['index' => $index, 'external_reference' => $invoice['external_reference'] ?? null];

            if ($this->referenceIsTaken($invoice)) {
                return [...$item, 'result' => 'failed', 'invoice' => null, 'error' => [
                    'code' => 'external_reference_already_used',
                    'message' => 'An invoice with this external reference was already issued.',
                ]];
            }

            if ($this->recipientIsNotIdentified($invoice)) {
                return [...$item, 'result' => 'failed', 'invoice' => null, 'error' => [
                    'code' => 'recipient_not_identified',
                    'message' => self::RecipientNotIdentified,
                ]];
            }

            return [...$item, 'result' => 'issued', 'invoice' => $this->store($invoice), 'error' => null];
        });

        $failed = $items->where('result', 'failed')->count();
        $now = Date::now()->toIso8601String();

        $batch = [
            'uuid' => (string) Str::uuid(),
            'environment' => 'test',
            'status' => 'queued',
            'total' => $items->count(),
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'created_at' => $now,
            'completed_at' => null,
        ];

        $status = 'completed';

        if ($failed > 0) {
            $status = 'completed_with_errors';
        }

        $this->batches[$batch['uuid']] = [
            'batch' => [
                ...$batch,
                'status' => $status,
                'processed' => $items->count(),
                'succeeded' => $items->count() - $failed,
                'failed' => $failed,
                'completed_at' => $now,
            ],
            'items' => $items->all(),
        ];

        return Http::response(['data' => $batch], 202);
    }

    protected function showBatch(string $uuid): PromiseInterface
    {
        if (! isset($this->batches[$uuid])) {
            return $this->notFound();
        }

        return Http::response(['data' => $this->batches[$uuid]['batch']]);
    }

    /**
     * @param  array<mixed>  $query
     */
    protected function batchItems(string $uuid, array $query): PromiseInterface
    {
        if (! isset($this->batches[$uuid])) {
            return $this->notFound();
        }

        $page = 1;

        if (is_numeric($query['page'] ?? null)) {
            $page = (int) $query['page'];
        }

        return $this->page($this->batches[$uuid]['items'], $page);
    }

    /**
     * Numbers the invoice and keeps it, as the service issues it.
     *
     * @param  array<mixed>  $body
     * @return array<string, mixed>
     */
    protected function store(array $body): array
    {
        $this->lastNumber++;

        $uuid = (string) Str::uuid();
        $number = (string) $this->lastNumber;
        $issueDate = Date::now('Europe/Madrid');
        $total = $body['total_amount'] ?? '0.00';

        $this->invoices[$uuid] = [
            'uuid' => $uuid,
            'environment' => 'test',
            'status' => InvoiceStatus::Pending->value,
            'series' => 'FACT',
            'number' => $number,
            'invoice_number' => "FACT{$number}",
            'issue_date' => $issueDate->toDateString(),
            'total_amount' => $total,
            'external_reference' => $body['external_reference'] ?? null,
            'verification_url' => 'https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR?'.http_build_query([
                'nif' => 'B00000000',
                'numserie' => "FACT{$number}",
                'fecha' => $issueDate->format('d-m-Y'),
                'importe' => $total,
            ]),
            'qr_code' => self::QrCode,
            'hash' => Str::upper(hash('sha256', $uuid)),
            'aeat' => ['verification_code' => null, 'error_code' => null, 'error_message' => null, 'registered_at' => null],
        ];

        $this->requests[$uuid] = $body;

        return $this->invoices[$uuid];
    }

    /**
     * The service's refusal to fix or cancel the invoice, if it has one.
     */
    protected function refuseChange(string $uuid): ?PromiseInterface
    {
        if (! isset($this->invoices[$uuid])) {
            return $this->notFound();
        }

        $status = $this->invoices[$uuid]['status'];

        if ($status === InvoiceStatus::Pending->value) {
            return $this->error(409, 'invoice_awaiting_aeat', 'AEAT has not answered for the invoice\'s last record yet.');
        }

        if ($status === InvoiceStatus::Cancelled->value) {
            return $this->error(409, 'invoice_cancelled', 'The invoice is cancelled.');
        }

        return null;
    }

    /**
     * Sends the invoice's new record to AEAT, which is to answer.
     */
    protected function awaitAeat(string $uuid): PromiseInterface
    {
        $this->invoices[$uuid]['status'] = InvoiceStatus::Pending->value;

        return Http::response(['data' => $this->invoices[$uuid]], 201);
    }

    /**
     * Whether the service refuses the body's Spanish customer: AEAT doesn't
     * know the tax ID under that name, unless the app skips the check.
     *
     * @param  array<mixed>  $body
     */
    protected function recipientIsNotIdentified(array $body): bool
    {
        return $this->taxIdCheck->result === TaxIdCheckResult::NotIdentified
            && is_string(Arr::get($body, 'recipient.tax_id'))
            && ($body['validate_recipient'] ?? true) !== false;
    }

    /**
     * @param  array<mixed>  $body
     */
    protected function referenceIsTaken(array $body): bool
    {
        $reference = $body['external_reference'] ?? null;

        return is_string($reference)
            && Collection::make($this->invoices)->contains('external_reference', $reference);
    }

    protected function uuidOf(Model&VerifactuInvoice $invoice): ?string
    {
        $uuid = Collection::make($this->invoices)
            ->firstWhere('external_reference', $invoice->verifactuReference())['uuid'] ?? null;

        if (! is_string($uuid)) {
            return null;
        }

        return $uuid;
    }

    /**
     * One page of a list, 100 items per page, as the service paginates.
     *
     * @param  array<int, array<mixed>>  $items
     */
    protected function page(array $items, int $page): PromiseInterface
    {
        $perPage = 100;
        $lastPage = max(1, (int) ceil(count($items) / $perPage));
        $slice = Collection::make($items)->forPage($page, $perPage)->values();
        $from = null;
        $to = null;

        if ($slice->isNotEmpty()) {
            $from = ($page - 1) * $perPage + 1;
            $to = $from + $slice->count() - 1;
        }

        return Http::response([
            'data' => $slice->all(),
            'links' => ['first' => null, 'last' => null, 'prev' => null, 'next' => null],
            'meta' => [
                'current_page' => $page,
                'from' => $from,
                'last_page' => $lastPage,
                'links' => [],
                'path' => null,
                'per_page' => $perPage,
                'to' => $to,
                'total' => count($items),
            ],
        ]);
    }

    protected function error(int $status, string $code, string $message): PromiseInterface
    {
        return Http::response(['message' => $message, 'code' => $code], $status);
    }

    protected function notFound(): PromiseInterface
    {
        return Http::response(['message' => 'Not found.'], 404);
    }
}
