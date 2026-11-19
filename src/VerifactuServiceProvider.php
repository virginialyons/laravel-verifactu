<?php

declare(strict_types=1);

namespace Vlec\Verifactu;

use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;

class VerifactuServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/verifactu.php', 'verifactu');

        $this->app->singleton(Client::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/webhook.php');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/verifactu.php' => config_path('verifactu.php'),
        ], 'verifactu-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'verifactu-migrations');

        AboutCommand::add('Verifactu', fn (Client $client): array => [
            'URL' => config('verifactu.url') ?? 'Not set',
            'Environment' => $client->environment()->name ?? 'Unknown',
            'Invoice model' => config('verifactu.model'),
            'Webhook URL' => url(config()->string('verifactu.webhook_path')),
        ]);
    }
}
