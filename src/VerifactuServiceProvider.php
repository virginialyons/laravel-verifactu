<?php

declare(strict_types=1);

namespace Vlec\Verifactu;

use Illuminate\Support\ServiceProvider;

class VerifactuServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/verifactu.php', 'verifactu');

        $this->app->singleton(Verifactu::class);
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/verifactu.php' => config_path('verifactu.php'),
        ], 'verifactu-config');
    }
}
