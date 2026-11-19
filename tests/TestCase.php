<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Tests;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;
use Vlec\Verifactu\VerifactuServiceProvider;
use Workbench\App\Providers\WorkbenchServiceProvider;

abstract class TestCase extends Orchestra
{
    use WithWorkbench;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
    }

    protected function getPackageProviders($app): array
    {
        return [
            VerifactuServiceProvider::class,
            WorkbenchServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('verifactu.url', 'https://verifactu.test');
        $app['config']->set('verifactu.token', 'vf_test_token-for-tests');
        $app['config']->set('verifactu.webhook_secret', 'whsec_secret-for-tests');
        $app['config']->set('queue.failed.database', 'testing');
    }
}
