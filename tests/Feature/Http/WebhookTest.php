<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Vlec\Verifactu\Http\Middleware\VerifyWebhookSignature;
use Vlec\Verifactu\InvoiceStatus;
use Vlec\Verifactu\Jobs\RefreshInvoice;
use Workbench\App\Models\Invoice;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
});

/**
 * A delivery's body as the service sends it. Pretty-printed with escaped
 * slashes, so a check over the decoded and re-encoded JSON would fail.
 *
 * @param  list<string>  $uuids
 */
function webhookBody(string $event = 'invoices.updated', array $uuids = []): string
{
    return (string) json_encode([
        'event' => $event,
        'environment' => 'test',
        'created_at' => '2026-11-19T10:31:05.123+01:00',
        'data' => collect($uuids)->map(fn (string $uuid): array => serviceInvoice(['uuid' => $uuid, 'qr_code' => null]))->all(),
    ], JSON_PRETTY_PRINT);
}

function webhookSignature(string $body, ?int $time = null, string $secret = 'whsec_secret-for-tests'): string
{
    $time ??= now()->getTimestamp();

    return "t={$time},v1=".hash_hmac('sha256', "{$time}.{$body}", $secret);
}

/**
 * @return TestResponse<Response>
 */
function postWebhook(string $body, ?string $signature = null, string $contentType = 'application/json'): TestResponse
{
    $server = ['CONTENT_TYPE' => $contentType, 'HTTP_ACCEPT' => 'application/json'];

    if ($signature !== null) {
        $server['HTTP_X_WEBHOOK_SIGNATURE'] = $signature;
    }

    return test()->call('POST', '/verifactu/webhook', server: $server, content: $body);
}

it('answers a signed ping with 200, without a session or cookies', function (): void {
    $body = webhookBody('ping');

    $response = postWebhook($body, webhookSignature($body));

    $response->assertOk();
    expect($response->headers->getCookies())->toBeEmpty();
});

it('runs no middleware but the signature check: no CSRF token and no session', function (): void {
    expect(Route::getRoutes()->getByName('verifactu.webhook')?->gatherMiddleware())->toBe([VerifyWebhookSignature::class]);
});

it('accepts a signature made 300 seconds ago, as a retry of the service may be', function (): void {
    $body = webhookBody('ping');

    postWebhook($body, webhookSignature($body, now()->subSeconds(300)->getTimestamp()))->assertOk();
});

it('refuses with a final 403, queueing nothing, a delivery with :dataset', function (Closure $signature): void {
    Queue::fake();
    $invoice = Invoice::factory()->issued()->create();
    $body = webhookBody(uuids: [$invoice->verifactu_uuid]);

    postWebhook($body, $signature($body))->assertForbidden();

    Queue::assertNothingPushed();
})->with([
    'a tampered body' => [fn (string $body): string => webhookSignature(str_replace('pending', 'accepted', $body))],
    'a body decoded and encoded again' => [fn (string $body): string => webhookSignature((string) json_encode(json_decode($body)))],
    'the wrong secret' => [fn (string $body): string => webhookSignature($body, secret: 'whsec_another-secret')],
    'a signature 301 seconds old' => [fn (string $body): string => webhookSignature($body, now()->subSeconds(301)->getTimestamp())],
    'a signature 301 seconds ahead' => [fn (string $body): string => webhookSignature($body, now()->addSeconds(301)->getTimestamp())],
    'no v1' => [fn (string $body): string => 't='.now()->getTimestamp()],
    'no t' => [fn (string $body): string => Str::after(webhookSignature($body), ',')],
    'a t that is not a number' => [fn (string $body): string => 't=now,v1='.hash_hmac('sha256', "now.{$body}", 'whsec_secret-for-tests')],
    'no signature header' => [fn (string $body): ?string => null],
]);

it('answers a 5xx while the webhook secret is not set, so the service retries', function (): void {
    config(['verifactu.webhook_secret' => null]);
    $body = webhookBody('ping');

    postWebhook($body, webhookSignature($body))->assertServerError();
});

it('queues one refresh per known invoice in invoices.updated and skips the others', function (): void {
    Queue::fake();
    $first = Invoice::factory()->issued()->create();
    $second = Invoice::factory()->issued()->create();
    Invoice::factory()->issued()->create();
    $body = webhookBody(uuids: [$first->verifactu_uuid, 'an-invoice-of-another-app', $second->verifactu_uuid]);

    postWebhook($body, webhookSignature($body))->assertOk();

    Queue::assertPushedTimes(RefreshInvoice::class, 2);
    Queue::assertPushed(RefreshInvoice::class, fn (RefreshInvoice $job): bool => $job->invoice->is($first));
    Queue::assertPushed(RefreshInvoice::class, fn (RefreshInvoice $job): bool => $job->invoice->is($second));
});

it('reads the body as JSON whatever its Content-Type', function (): void {
    Queue::fake();
    $invoice = Invoice::factory()->issued()->create();
    $body = webhookBody(uuids: [$invoice->verifactu_uuid]);

    postWebhook($body, webhookSignature($body), contentType: 'text/plain')->assertOk();

    Queue::assertPushed(RefreshInvoice::class);
});

it('queues nothing for :dataset', function (string $event): void {
    Queue::fake();
    $invoice = Invoice::factory()->issued()->create();
    $body = webhookBody($event, [$invoice->verifactu_uuid]);

    postWebhook($body, webhookSignature($body))->assertOk();

    Queue::assertNothingPushed();
})->with(['ping', 'an event this version does not know' => 'invoices.archived']);

it('answers a 5xx when the refreshes cannot be queued, so the service retries', function (): void {
    config(['queue.default' => 'database']);
    $invoice = Invoice::factory()->issued()->create();
    Schema::drop('jobs');
    $body = webhookBody(uuids: [$invoice->verifactu_uuid]);

    postWebhook($body, webhookSignature($body))->assertServerError();
});

it('leaves the newest state after deliveries that arrive before and during a refresh', function (): void {
    config(['queue.default' => 'database']);
    $invoice = Invoice::factory()->issued()->create();
    $body = webhookBody(uuids: [$invoice->verifactu_uuid]);
    $reads = 0;
    Http::fake(["verifactu.test/api/v1/invoices/{$invoice->verifactu_uuid}" => function () use (&$reads, $body, $invoice) {
        $reads++;

        if ($reads === 1) {
            // AEAT answers while the first read is under way.
            postWebhook($body, webhookSignature($body))->assertOk();

            return Http::response(['data' => serviceInvoice(['uuid' => $invoice->verifactu_uuid, 'status' => 'pending'])]);
        }

        return Http::response(['data' => serviceInvoice(['uuid' => $invoice->verifactu_uuid, 'status' => 'accepted'])]);
    }]);

    postWebhook($body, webhookSignature($body))->assertOk();
    postWebhook($body, webhookSignature($body))->assertOk();

    expect(DB::table('jobs')->count())->toBe(1);

    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();

    expect(DB::table('jobs')->count())->toBe(1);

    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();

    expect($reads)->toBe(2)
        ->and($invoice->fresh()->aeat_status)->toBe(InvoiceStatus::Accepted);
});
