<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Testing;

use BcMath\Number;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\Fake;
use LogicException;
use PHPUnit\Framework\Assert as PHPUnit;
use Psr\Http\Message\ResponseInterface;
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
 * requests like the service, refusals included, so the real jobs, events
 * and IssuesVerifactuInvoices concern run. It numbers invoices in order
 * within each issue year (FACT-2026-00001, FACT-2026-00002…, then
 * FACT-2027-00001), keeps them in memory and completes batches at once;
 * decide() plays AEAT's answer. The requests it doesn't play, such as an
 * invoice's XML, are left to the app's own Http::fake(), or reported as
 * stray.
 *
 * Its invoices live in the test's memory, so the jobs must run in the test:
 * on the sync queue, not with Queue::fake(). Call Verifactu::fake() before
 * any catch-all Http::fake(), whose answers would come first.
 *
 * Its constants are left out of mutation testing, which finds no test
 * covering a constant's line; the fake's tests cover their values.
 *
 * @phpstan-type Invoice array{uuid: string, environment: string|null, status: string, series: string, number: string, invoice_number: string, issue_date: string, total_amount: numeric-string, external_reference: string|null, verification_url: string, qr_code: string, hash: string|null, aeat: Aeat}
 * @phpstan-type Aeat array{verification_code: string|null, error_code: string|null, error_message: string|null, registered_at: string|null}
 * @phpstan-type Refusal array{status: int, code: string, message: string, errors?: array<string, list<string>>}
 * @phpstan-type BillingRecord array{uuid: string, type: string, status: string, hash: string, generated_at: string}
 *
 * @phpstan-import-type Row from InvoiceListQuery
 */
class VerifactuFake extends Client implements Fake
{
    protected const string Series = 'FACT';

    protected const string RecipientNotIdentified = 'AEAT does not know this tax ID under this name.';

    /**
     * A 1×1 PNG, so an app that prints the QR code gets a real image.
     */
    protected const string QrCode = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    /**
     * What a correction may change on each line, besides the description.
     *
     * @pest-mutate-ignore
     */
    protected const array CorrectableLineFields = ['regime_key', 'operation_qualification', 'exemption_reason'];

    /**
     * A tax line as the service answers it, with its defaults.
     *
     * @pest-mutate-ignore
     */
    protected const array TaxLine = [
        'tax_type' => '01',
        'regime_key' => '01',
        'operation_qualification' => null,
        'exemption_reason' => null,
        'tax_base' => null,
        'tax_rate' => null,
        'tax_amount' => null,
        'equivalence_surcharge_rate' => null,
        'equivalence_surcharge_amount' => null,
    ];

    /**
     * An invoice's AEAT answer while its last record awaits one.
     *
     * @pest-mutate-ignore
     */
    protected const array NoAeatAnswer = ['verification_code' => null, 'error_code' => null, 'error_message' => null, 'registered_at' => null];

    /**
     * The issued invoices, by uuid.
     *
     * @var array<string, Invoice>
     */
    protected array $invoices = [];

    /**
     * The body each invoice was issued from, by uuid.
     *
     * @var array<string, array<mixed>>
     */
    protected array $requests = [];

    /**
     * The body of each invoice's last correction, by uuid.
     *
     * @var array<string, array<mixed>>
     */
    protected array $corrections = [];

    /**
     * Each invoice's AEAT records, oldest first, by uuid.
     *
     * @var array<string, non-empty-list<BillingRecord>>
     */
    protected array $records = [];

    /**
     * The batches, completed, with their items, by uuid.
     *
     * @var array<string, array{batch: array<string, mixed>, items: array<int, array<string, mixed>>}>
     */
    protected array $batches = [];

    /**
     * The first answer to each Idempotency-Key, with the request it answered.
     *
     * @var array<string, array{request: string, status: int, body: array<mixed>}>
     */
    protected array $replies = [];

    /**
     * The last number given in each issue year, by year.
     *
     * @var array<int, int>
     */
    protected array $lastNumbers = [];

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
        Http::fake(["{$url}api/v1/*" => fn (Request $request): ?PromiseInterface => $this->answer($request)]);
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
     * Plays AEAT's answer to the invoice's last record, then refreshes the
     * invoice, as the invoices.updated webhook would. AEAT accepts, accepts
     * with errors or rejects a record of the invoice, and registers a
     * cancellation: decide Cancelled, or Accepted, after cancelling it.
     */
    public function decide(Model&VerifactuInvoice $invoice, InvoiceStatus $status, ?string $errorCode = null, ?string $errorMessage = null): static
    {
        $reference = $invoice->verifactuReference();
        $uuid = $this->uuidOf($invoice);

        if ($uuid === null) {
            throw new LogicException("The invoice [{$reference}] is not on the fake Verifactu: issue it first.");
        }

        $records = $this->records[$uuid];
        $last = array_key_last($records);
        $record = $records[$last];

        if ($record['status'] !== 'pending') {
            throw new LogicException("Nothing of the invoice [{$reference}] awaits AEAT's answer: correct or cancel it first.");
        }

        if ($status === InvoiceStatus::Pending) {
            throw new LogicException('AEAT answers accepted, accepted with errors or rejected, or registers a cancellation: pending is no answer.');
        }

        $cancellation = $record['type'] === 'cancellation';

        if ($status === InvoiceStatus::Cancelled && ! $cancellation) {
            throw new LogicException("No cancellation of the invoice [{$reference}] awaits AEAT's answer: cancel it first.");
        }

        if ($cancellation && $status !== InvoiceStatus::Cancelled && $status !== InvoiceStatus::Accepted) {
            throw new LogicException("The cancellation of the invoice [{$reference}] awaits AEAT's answer: decide Cancelled.");
        }

        if ($errorCode !== null && $status !== InvoiceStatus::AcceptedWithErrors && $status !== InvoiceStatus::Rejected) {
            throw new LogicException('AEAT gives an error code only when it accepts a record with errors or rejects it.');
        }

        $recordStatus = $status->value;

        if ($cancellation) {
            $status = InvoiceStatus::Cancelled;
            $recordStatus = InvoiceStatus::Accepted->value;
        }

        $aeat = [...self::NoAeatAnswer, 'error_code' => $errorCode, 'error_message' => $errorMessage];

        if ($status !== InvoiceStatus::Rejected) {
            $aeat['verification_code'] = 'A-'.Str::upper(Str::random(14));
            $aeat['registered_at'] = $this->now()->toIso8601String();
        }

        $this->invoices[$uuid] = [...$this->invoices[$uuid], 'status' => $status->value, 'aeat' => $aeat];
        $this->records[$uuid][$last] = [...$record, 'status' => $recordStatus];

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
            $uuid !== null && isset($this->corrections[$uuid]),
            "The invoice [{$invoice->verifactuReference()}] was not corrected on Verifactu.",
        );
    }

    public function assertCancelled(Model&VerifactuInvoice $invoice): void
    {
        $uuid = $this->uuidOf($invoice);

        PHPUnit::assertTrue(
            $uuid !== null && Collection::make($this->records[$uuid])->whereStrict('type', 'cancellation')->isNotEmpty(),
            "The invoice [{$invoice->verifactuReference()}] was not cancelled on Verifactu.",
        );
    }

    /**
     * The service's answer to a request it plays, or null for the next
     * Http::fake() stub to answer.
     */
    protected function answer(Request $request): ?PromiseInterface
    {
        $segments = Str::of(Str::after($request->uri()->path(), 'api/v1/'))->explode('/');
        $uuid = $segments->get(1, '');

        if ($segments->has(1)) {
            $segments->put(1, '{uuid}');
        }

        $route = "{$request->method()} {$segments->implode('/')}";

        $handler = match ($route) {
            'GET invoices' => fn (): PromiseInterface => $this->list($request),
            'POST invoices' => fn (): PromiseInterface => $this->issue($request->data()),
            'GET invoices/{uuid}' => fn (): PromiseInterface => $this->show($uuid),
            'POST invoices/{uuid}/corrections' => fn (): PromiseInterface => $this->correct($uuid, $request->data()),
            'POST invoices/{uuid}/cancellation' => fn (): PromiseInterface => $this->cancel($uuid),
            'POST invoice-batches' => fn (): PromiseInterface => $this->issueBatch($request->data()),
            'GET invoice-batches/{uuid}' => fn (): PromiseInterface => $this->showBatch($uuid),
            'GET invoice-batches/{uuid}/items' => fn (): PromiseInterface => $this->batchItems($uuid, $request),
            'POST tax-id-checks' => fn (): PromiseInterface => $this->answerTaxIdCheck($request->data()),
            default => null,
        };

        if ($handler === null) {
            return null;
        }

        if ($this->environment() === null) {
            return Http::response(['message' => 'Unauthenticated.'], 401);
        }

        if ($request->method() === 'GET' || $route === 'POST tax-id-checks') {
            return $handler();
        }

        return $this->idempotently($request, $route, $handler);
    }

    /**
     * Answers a POST under its Idempotency-Key: the same key with the same
     * request replays the first answer, refusals included; with another
     * request it is refused.
     *
     * @param  Closure(): PromiseInterface  $handler
     */
    protected function idempotently(Request $request, string $route, Closure $handler): PromiseInterface
    {
        $key = Arr::first($request->header('Idempotency-Key'));

        if (! is_string($key) && $route === 'POST invoice-batches') {
            return $this->refuse($this->refusal(422, 'idempotency_key_required', 'This endpoint needs an Idempotency-Key header, so a retry never sends the same work twice.'));
        }

        if (! is_string($key)) {
            return $handler();
        }

        $fingerprint = "{$route} {$request->uri()->path()} {$request->body()}";
        $reply = $this->replies[$key] ?? null;

        if ($reply !== null && $reply['request'] !== $fingerprint) {
            return $this->refuse($this->refusal(422, 'idempotency_key_reused', 'This Idempotency-Key was already used with a different request. Use a new key for a new request.'));
        }

        if ($reply !== null) {
            return Http::response($reply['body'], $reply['status'], ['Idempotent-Replayed' => 'true']);
        }

        /** @var ResponseInterface $answer */
        $answer = $handler()->wait();
        $response = new Response($answer);

        /** @var array<mixed> $body */
        $body = $response->json();

        $this->replies[$key] = ['request' => $fingerprint, 'status' => $response->status(), 'body' => $body];

        return Http::response($body, $response->status());
    }

    /**
     * @param  array<mixed>  $body
     */
    protected function issue(array $body): PromiseInterface
    {
        $refusal = $this->issueRefusal($body);

        if ($refusal !== null) {
            return $this->refuse($refusal);
        }

        return Http::response(['data' => $this->store($body)], 201);
    }

    protected function list(Request $request): PromiseInterface
    {
        $query = new InvoiceListQuery($request->uri()->query()->all());
        $refusal = $query->refusal();

        if ($refusal !== null) {
            return Http::response($refusal, 400);
        }

        $rows = Collection::make($this->invoices)->keys()->flip()->map($this->row(...))->all();

        $invoices = $query->apply($rows)
            ->map(fn (array $row, string $uuid): array => [
                ...Arr::except($row['invoice'], 'qr_code'),
                ...Arr::only($this->relations($uuid), $query->includes()),
            ])
            ->values()
            ->all();

        return Http::response($this->page($request, $invoices, $query->pageSize(), 'page.number'));
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
        if (! isset($this->invoices[$uuid])) {
            return $this->notFound();
        }

        $refusal = $this->invalidity($body)
            ?? $this->changeRefusal($uuid)
            ?? $this->fiscalChange($uuid, $body)
            ?? $this->recipientRefusal($body);

        if ($refusal !== null) {
            return $this->refuse($refusal);
        }

        $this->corrections[$uuid] = $body;
        $this->invoices[$uuid] = [...$this->invoices[$uuid], ...$this->sendRecord($uuid, 'registration')];

        return Http::response(['data' => $this->invoices[$uuid]], 201);
    }

    protected function cancel(string $uuid): PromiseInterface
    {
        if (! isset($this->invoices[$uuid])) {
            return $this->notFound();
        }

        $refusal = $this->changeRefusal($uuid);

        if ($refusal !== null) {
            return $this->refuse($refusal);
        }

        $this->invoices[$uuid] = [...$this->invoices[$uuid], ...$this->sendRecord($uuid, 'cancellation')];

        return Http::response(['data' => $this->invoices[$uuid]], 201);
    }

    /**
     * Issues each invoice now and answers as the service does, with the
     * batch queued; reading it afterwards finds it completed.
     *
     * @param  array<mixed>  $body
     */
    protected function issueBatch(array $body): PromiseInterface
    {
        if (! is_array($body['invoices'] ?? null)) {
            return $this->refuse($this->invalid('validation_failed', 'invoices', 'The invoices field is required.'));
        }

        /** @var Collection<int, array<mixed>> $invoices */
        $invoices = Collection::make($body['invoices'])->values();

        $repeated = $invoices->pluck('external_reference')->filter()->duplicatesStrict();

        if ($repeated->isNotEmpty()) {
            $message = 'Items that have an external_reference need distinct ones.';

            $errors = $invoices
                ->filter(fn (array $invoice): bool => $repeated->containsStrict($invoice['external_reference'] ?? null))
                ->mapWithKeys(fn (array $invoice, int $index): array => ["invoices.{$index}.external_reference" => [$message]])
                ->all();

            return $this->refuse($this->refusal(422, 'validation_failed', $message, $errors));
        }

        $items = $invoices->map(function (array $invoice, int $index): array {
            $item = ['index' => $index, 'external_reference' => $invoice['external_reference'] ?? null];
            $refusal = $this->issueRefusal($invoice);

            if ($refusal !== null) {
                return [...$item, 'result' => 'failed', 'invoice' => null, 'error' => Arr::except($refusal, 'status')];
            }

            return [...$item, 'result' => 'issued', 'invoice' => $this->store($invoice), 'error' => null];
        });

        $failed = $items->whereStrict('result', 'failed')->count();
        $now = $this->now()->toIso8601String();

        $batch = [
            'uuid' => (string) Str::uuid(),
            'environment' => $this->environment()?->value,
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

    protected function batchItems(string $uuid, Request $request): PromiseInterface
    {
        if (! isset($this->batches[$uuid])) {
            return $this->notFound();
        }

        return Http::response($this->page($request, $this->batches[$uuid]['items'], 100, 'page'));
    }

    /**
     * @param  array<mixed>  $body
     */
    protected function answerTaxIdCheck(array $body): PromiseInterface
    {
        foreach (['tax_id', 'name'] as $field) {
            if (blank($body[$field] ?? null)) {
                return $this->refuse($this->invalid('field_required', $field, "The {$field} field is required."));
            }
        }

        if (is_string($body['tax_id']) && SpanishTaxId::isInvalid($body['tax_id'])) {
            return $this->refuse($this->invalid('invalid_tax_id', 'tax_id', InvoiceRules::InvalidTaxId));
        }

        if (InvoiceRules::isInvalidName($body['name'])) {
            return $this->refuse($this->invalid('invalid_recipient_name', 'name', 'The name is not text or is longer than 120 characters.'));
        }

        return Http::response(['data' => [
            'tax_id' => $body['tax_id'],
            'result' => $this->taxIdCheck->result->value,
            'official_name' => $this->taxIdCheck->officialName,
        ]]);
    }

    /**
     * Numbers the invoice and keeps it, as the service issues it.
     *
     * @param  array<mixed>  $body
     * @return Invoice
     */
    protected function store(array $body): array
    {
        $uuid = (string) Str::uuid();
        $issueDate = $this->now();
        $year = $issueDate->year;
        $this->lastNumbers[$year] = ($this->lastNumbers[$year] ?? 0) + 1;
        $number = $this->lastNumbers[$year];
        $invoiceNumber = $this->invoiceNumber($year, $number);

        /** @var array{total_amount: numeric-string, external_reference?: string|null} $body InvoiceRules let it through */
        $total = $body['total_amount'];

        $this->invoices[$uuid] = [
            'uuid' => $uuid,
            'environment' => $this->environment()?->value,
            'series' => self::Series,
            'number' => (string) $number,
            'invoice_number' => $invoiceNumber,
            'issue_date' => $issueDate->toDateString(),
            'total_amount' => $total,
            'external_reference' => $body['external_reference'] ?? null,
            'verification_url' => 'https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR?'.Arr::query([
                'nif' => 'B00000000',
                'numserie' => $invoiceNumber,
                'fecha' => $issueDate->format('d-m-Y'),
                'importe' => $total,
            ]),
            'qr_code' => self::QrCode,
            ...$this->sendRecord($uuid, 'registration'),
        ];

        $this->requests[$uuid] = $body;

        return $this->invoices[$uuid];
    }

    /**
     * Generates the invoice's next AEAT record, which awaits AEAT's answer,
     * and gives what the invoice shows meanwhile: pending, with the
     * record's hash and no answer yet.
     *
     * @return array{status: string, hash: string, aeat: Aeat}
     */
    protected function sendRecord(string $uuid, string $type): array
    {
        $recordUuid = (string) Str::uuid();
        $hash = Str::upper(hash('sha256', $recordUuid));

        $this->records[$uuid] = [...$this->records[$uuid] ?? [], [
            'uuid' => $recordUuid,
            'type' => $type,
            'status' => 'pending',
            'hash' => $hash,
            'generated_at' => $this->now()->toIso8601String(),
        ]];

        return ['status' => InvoiceStatus::Pending->value, 'hash' => $hash, 'aeat' => self::NoAeatAnswer];
    }

    /**
     * The service's refusal to issue the body, if it has one.
     *
     * @param  array<mixed>  $body
     * @return Refusal|null
     */
    protected function issueRefusal(array $body): ?array
    {
        return $this->invalidity($body)
            ?? $this->referenceRefusal($body)
            ?? $this->recipientRefusal($body);
    }

    /**
     * The service's validation error for an invoice's body, if it has one.
     *
     * @param  array<mixed>  $body
     * @return Refusal|null
     */
    protected function invalidity(array $body): ?array
    {
        $error = InvoiceRules::firstError($body, self::Series);

        if ($error === null) {
            return null;
        }

        return $this->invalid(...$error);
    }

    /**
     * The service's refusal to correct or cancel the invoice, if it has one.
     *
     * @return Refusal|null
     */
    protected function changeRefusal(string $uuid): ?array
    {
        $status = $this->invoices[$uuid]['status'];

        if ($status === InvoiceStatus::Pending->value) {
            return $this->refusal(409, 'invoice_awaiting_aeat', 'AEAT has not answered for the invoice\'s last record yet.');
        }

        if ($status === InvoiceStatus::Cancelled->value) {
            return $this->refusal(409, 'invoice_cancelled', 'The invoice is cancelled.');
        }

        return null;
    }

    /**
     * The refusal of a correction that changes more than the description
     * and each line's codes not printed on the invoice.
     *
     * @param  array<mixed>  $body
     * @return Refusal|null
     */
    protected function fiscalChange(string $uuid, array $body): ?array
    {
        $issued = $this->fiscalData($this->requests[$uuid]);
        $sent = $this->fiscalData($body);

        $changed = Collection::make([...$issued, ...$sent])->keys()
            ->map(fn (int|string $field): string => (string) $field)
            ->reject(fn (string $field): bool => ($issued[$field] ?? null) === ($sent[$field] ?? null));

        if ($changed->isEmpty()) {
            return null;
        }

        $message = 'A correction may change only the description and the codes not printed on the invoice. To change amounts, rates or the customer, issue a corrective invoice (R1–R4).';

        return $this->refusal(422, 'correction_changes_fiscal_data', $message, $changed->mapWithKeys(fn (string $field): array => [$field => [$message]])->all());
    }

    /**
     * The body without what a correction may change.
     *
     * @param  array<mixed>  $body
     * @return array<mixed>
     */
    protected function fiscalData(array $body): array
    {
        $body = Arr::except($body, 'description');

        if (Arr::exists($body, 'lines')) {
            $body['lines'] = Collection::make(Arr::wrap($body['lines']))
                ->map(fn (mixed $line): array => Arr::except(Arr::wrap($line), self::CorrectableLineFields))
                ->all();
        }

        return $this->withSortedKeys($body);
    }

    /**
     * The order of fields never matters; the order of lines and of the
     * invoices a correction refers to does, and sorting a list by its keys
     * keeps it.
     *
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    protected function withSortedKeys(array $value): array
    {
        $value = Arr::map($value, function (mixed $item): mixed {
            if (is_array($item)) {
                return $this->withSortedKeys($item);
            }

            return $item;
        });

        return Collection::make($value)->sortKeys()->all();
    }

    /**
     * The refusal of a Spanish customer AEAT doesn't know under that name,
     * unless the app skips the check.
     *
     * @param  array<mixed>  $body
     * @return Refusal|null
     */
    protected function recipientRefusal(array $body): ?array
    {
        if ($this->taxIdCheck->result !== TaxIdCheckResult::NotIdentified
            || ! is_string(Arr::get($body, 'recipient.tax_id'))
            || ($body['validate_recipient'] ?? true) === false) {
            return null;
        }

        return $this->refusal(422, 'recipient_not_identified', self::RecipientNotIdentified, ['recipient.tax_id' => [self::RecipientNotIdentified]]);
    }

    /**
     * @param  array<mixed>  $body
     * @return Refusal|null
     */
    protected function referenceRefusal(array $body): ?array
    {
        $reference = $body['external_reference'] ?? null;

        if (! is_string($reference) || ! Collection::make($this->invoices)->pluck('external_reference')->containsStrict($reference)) {
            return null;
        }

        return $this->refusal(409, 'external_reference_already_used', 'An invoice with this external reference was already issued.');
    }

    /**
     * The invoice as the list query reads it, with its place in the order
     * the invoices were created.
     *
     * @return Row
     */
    protected function row(int $position, string $uuid): array
    {
        return [
            'invoice' => $this->invoices[$uuid],
            'body' => $this->lastBody($uuid),
            'total' => new Number($this->invoices[$uuid]['total_amount']),
            'position' => $position,
        ];
    }

    /**
     * The relations a list can include, built from the invoice's last record.
     *
     * @return array{tax_lines: array<int, array<mixed>>, references: list<array{kind: string, invoice_number: string, issue_date: string}>, billing_records: list<BillingRecord>}
     */
    protected function relations(string $uuid): array
    {
        $body = $this->lastBody($uuid);

        // Without lines, the invoice has one line for the whole total, exempt with E1.
        /** @var list<array<string, mixed>> $lines */
        $lines = $body['lines'] ?? [['tax_base' => $this->invoices[$uuid]['total_amount']]];

        /** @var list<array{number: string, issue_date: string}|array{invoice_number: string, issue_date: string}> $corrected InvoiceRules let them through */
        $corrected = Arr::get($body, 'correction.corrected_invoices', []);

        /** @var list<array{number: string, issue_date: string}|array{invoice_number: string, issue_date: string}> $replaced InvoiceRules let them through */
        $replaced = $body['replaced_invoices'] ?? [];

        return [
            'tax_lines' => Collection::make($lines)->map($this->taxLine(...))->all(),
            'references' => [
                ...Collection::make($corrected)->map(fn (array $invoice): array => $this->reference('corrected', $invoice)),
                ...Collection::make($replaced)->map(fn (array $invoice): array => $this->reference('replaced', $invoice)),
            ],
            'billing_records' => $this->records[$uuid],
        ];
    }

    /**
     * A line as the service answers it: a line without a qualification or
     * an exemption reason is exempt with E1.
     *
     * @param  array<string, mixed>  $line
     * @return array<mixed>
     */
    protected function taxLine(array $line): array
    {
        $line = [...self::TaxLine, ...Arr::only($line, Collection::make(self::TaxLine)->keys()->all())];

        if ($line['operation_qualification'] === null && $line['exemption_reason'] === null) {
            $line['exemption_reason'] = 'E1';
        }

        return $line;
    }

    /**
     * A reference by number names an invoice of the series in the year of
     * its issue_date.
     *
     * @param  array{number: string, issue_date: string}|array{invoice_number: string, issue_date: string}  $invoice
     * @return array{kind: string, invoice_number: string, issue_date: string}
     */
    protected function reference(string $kind, array $invoice): array
    {
        if (isset($invoice['invoice_number'])) {
            return ['kind' => $kind, 'invoice_number' => $invoice['invoice_number'], 'issue_date' => $invoice['issue_date']];
        }

        $year = (int) Str::before($invoice['issue_date'], '-');

        return ['kind' => $kind, 'invoice_number' => $this->invoiceNumber($year, (int) $invoice['number']), 'issue_date' => $invoice['issue_date']];
    }

    /**
     * The printed invoice number, such as FACT-2026-00042: the series, the
     * issue year and the number padded to 5 digits, which it may outgrow.
     */
    protected function invoiceNumber(int $year, int $number): string
    {
        return sprintf('%s-%04d-%05d', self::Series, $year, $number);
    }

    /**
     * The body of the invoice's last record: its last correction, or the
     * body it was issued from.
     *
     * @return array<mixed>
     */
    protected function lastBody(string $uuid): array
    {
        return $this->corrections[$uuid] ?? $this->requests[$uuid];
    }

    protected function uuidOf(Model&VerifactuInvoice $invoice): ?string
    {
        return Collection::make($this->invoices)
            ->whereStrict('external_reference', $invoice->verifactuReference())
            ->keys()
            ->first();
    }

    /**
     * One page of a list, built by Laravel's own paginator and resource
     * collection, as the service builds its lists.
     *
     * @param  array<int, array<mixed>>  $items
     * @param  string  $pageKey  The query key of the page number, in dot notation, such as "page.number".
     * @return array<string, mixed>
     */
    protected function page(Request $request, array $items, int $size, string $pageKey): array
    {
        $query = $request->uri()->query()->all();
        $requested = Arr::pull($query, $pageKey);
        $number = 1;

        // The paginator, as the forPage() slice, reads a number below 1 as the first page.
        if (is_numeric($requested)) {
            $number = (int) $requested;
        }

        $paginator = new LengthAwarePaginator(
            Collection::make($items)->forPage($number, $size)->values(),
            Collection::make($items)->count(),
            $size,
            $number,
            // "page.number" is the query's page[number].
            ['path' => Str::before($request->url(), '?'), 'pageName' => Str::replaceMatches('/\.([^.]+)/', '[$1]', $pageKey)],
        );

        /** @var array<string, mixed> */
        return JsonResource::collection($paginator->appends($query))->response()->getData(true);
    }

    /**
     * A validation error of one field.
     *
     * @return Refusal
     */
    protected function invalid(string $code, string $field, string $message): array
    {
        return $this->refusal(422, $code, $message, [$field => [$message]]);
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return Refusal
     */
    protected function refusal(int $status, string $code, string $message, array $errors = []): array
    {
        $refusal = ['status' => $status, 'code' => $code, 'message' => $message];

        if ($errors !== []) {
            $refusal['errors'] = $errors;
        }

        return $refusal;
    }

    /**
     * @param  Refusal  $refusal
     */
    protected function refuse(array $refusal): PromiseInterface
    {
        return Http::response(Arr::except($refusal, 'status'), $refusal['status']);
    }

    protected function notFound(): PromiseInterface
    {
        return Http::response(['message' => 'Not found.'], 404);
    }

    protected function now(): Carbon
    {
        return Date::now('Europe/Madrid');
    }
}
