<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Vlec\Verifactu\Client;
use Vlec\Verifactu\Jobs\ApplyInvoiceBatch;
use Vlec\Verifactu\Jobs\RefreshInvoice;
use Vlec\Verifactu\Jobs\SendInvoiceBatch;
use Vlec\Verifactu\Models\InvoiceBatch;
use Vlec\Verifactu\VerifactuServiceProvider;
use Workbench\App\Models\Invoice;

it('binds the client as a singleton', function (): void {
    expect(app(Client::class))->toBe(app(Client::class));
});

it('publishes the config under the verifactu-config tag', function (): void {
    expect(ServiceProvider::pathsToPublish(VerifactuServiceProvider::class, 'verifactu-config'))
        ->toContain(config_path('verifactu.php'));
});

it('publishes the batches migration under the verifactu-migrations tag', function (): void {
    expect(ServiceProvider::pathsToPublish(VerifactuServiceProvider::class, 'verifactu-migrations'))
        ->toContain(database_path('migrations'));
});

it('shows the environment the token works in on about', function (string $token, string $environment): void {
    config(['verifactu.token' => $token]);

    $this->artisan('about', ['--only' => 'verifactu'])
        ->expectsOutputToContain($environment)
        ->expectsOutputToContain('https://verifactu.test')
        ->expectsOutputToContain('Workbench\App\Models\Invoice')
        ->expectsOutputToContain('verifactu/webhook')
        ->assertSuccessful();
})->with([
    'a test token' => ['vf_test_token-for-tests', 'Test'],
    'a live token' => ['vf_prod_token-for-tests', 'Live'],
    'an unknown token' => ['sk_token-for-tests', 'Unknown'],
]);

it('never shows the token or the webhook secret on about', function (): void {
    $this->artisan('about', ['--only' => 'verifactu'])
        ->doesntExpectOutputToContain('token-for-tests')
        ->doesntExpectOutputToContain('secret-for-tests')
        ->assertSuccessful();
});

it('runs its other jobs on the configured queue too: :dataset', function (Closure $job): void {
    config(['verifactu.queue' => 'invoices']);
    Queue::fake();

    $job = $job();
    dispatch($job);

    Queue::assertPushedOn('invoices', $job::class);
})->with([
    'RefreshInvoice' => [fn () => new RefreshInvoice(Invoice::factory()->make(['id' => 1]))],
    'SendInvoiceBatch' => [fn () => new SendInvoiceBatch(Collection::make([Invoice::factory()->make(['id' => 1])]))],
    'ApplyInvoiceBatch' => [fn () => new ApplyInvoiceBatch(new InvoiceBatch(['uuid' => 'a-batch']))],
]);
