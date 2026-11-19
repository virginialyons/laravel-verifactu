<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Vlec\Verifactu\Verifactu;
use Vlec\Verifactu\VerifactuServiceProvider;

it('binds the client as a singleton', function (): void {
    expect(app(Verifactu::class))->toBe(app(Verifactu::class));
});

it('publishes the config under the verifactu-config tag', function (): void {
    expect(ServiceProvider::pathsToPublish(VerifactuServiceProvider::class, 'verifactu-config'))
        ->toContain(config_path('verifactu.php'));
});
