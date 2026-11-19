<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Vlec\Verifactu\Client;
use Vlec\Verifactu\VerifactuServiceProvider;

it('binds the client as a singleton', function (): void {
    expect(app(Client::class))->toBe(app(Client::class));
});

it('publishes the config under the verifactu-config tag', function (): void {
    expect(ServiceProvider::pathsToPublish(VerifactuServiceProvider::class, 'verifactu-config'))
        ->toContain(config_path('verifactu.php'));
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
