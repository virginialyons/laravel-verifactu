<?php

declare(strict_types=1);

namespace Vlec\Verifactu;

use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Queue\QueueRoutes;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;
use Vlec\Verifactu\Console\Commands\CorrectCommand;
use Vlec\Verifactu\Console\Commands\InstallCommand;
use Vlec\Verifactu\Console\Commands\RefreshPendingCommand;
use Vlec\Verifactu\Console\Commands\StatusCommand;
use Vlec\Verifactu\Jobs\ApplyInvoiceBatch;
use Vlec\Verifactu\Jobs\IssueInvoice;
use Vlec\Verifactu\Jobs\RefreshInvoice;
use Vlec\Verifactu\Jobs\SendInvoiceBatch;

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

        // A default route: an app's own Queue::route() for one of these jobs still wins.
        $this->callAfterResolving('queue.routes', function (QueueRoutes $routes): void {
            $queue = config('verifactu.queue');

            if (! is_string($queue)) {
                return;
            }

            $routes->set(Collection::make([IssueInvoice::class, RefreshInvoice::class, SendInvoiceBatch::class, ApplyInvoiceBatch::class])
                ->mapWithKeys(fn (string $job): array => [$job => $queue])
                ->all());
        });

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/verifactu.php' => config_path('verifactu.php'),
        ], 'verifactu-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'verifactu-migrations');

        $this->commands([
            InstallCommand::class,
            StatusCommand::class,
            RefreshPendingCommand::class,
            CorrectCommand::class,
        ]);

        AboutCommand::add('Verifactu', function (Client $client): array {
            $url = config('verifactu.url');

            if (blank($url)) {
                $url = 'Not set';
            }

            return [
                'URL' => $url,
                'Environment' => $client->environment()->name ?? 'Unknown',
                'Invoice model' => config('verifactu.model'),
                'Webhook URL' => route('verifactu.webhook'),
            ];
        });
    }
}
