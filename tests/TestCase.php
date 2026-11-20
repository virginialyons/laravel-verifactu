<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Tests;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * The package's provider and migrations, and Laravel's own migrations
 * (workbench.install), come from testbench.yaml through WithWorkbench.
 */
abstract class TestCase extends Orchestra
{
    use WithWorkbench;

    /**
     * The workbench's .env, which composer build and serve copy into
     * Testbench's skeleton, never reaches the tests.
     *
     * @var bool
     */
    protected $loadEnvironmentVariables = false;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('verifactu.url', 'https://verifactu.test');
        $app['config']->set('verifactu.token', 'vf_test_token-for-tests');
        $app['config']->set('verifactu.webhook_secret', 'whsec_secret-for-tests');
        $app['config']->set('queue.failed.database', 'testing');
    }
}
