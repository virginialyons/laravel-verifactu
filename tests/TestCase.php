<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
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

    /**
     * Runs the job's given attempt through the database queue, with time
     * frozen, and gives back the seconds it then waits for the next one.
     */
    protected function secondsUntilRetry(ShouldQueue $job, int $attempt = 1): int
    {
        config(['queue.default' => 'database']);
        $this->freezeSecond();

        dispatch($job);
        // The worker counts the attempt it reserves.
        DB::table('jobs')->update(['attempts' => $attempt - 1]);

        $this->artisan('queue:work', ['--once' => true])->assertSuccessful();

        return DB::table('jobs')->sole()->available_at - now()->getTimestamp();
    }

    protected function defineEnvironment($app): void
    {
        $app->make(Repository::class)->set([
            'verifactu.url' => 'https://verifactu.test',
            'verifactu.token' => 'vf_test_token-for-tests',
            'verifactu.webhook_secret' => 'whsec_secret-for-tests',
            'queue.failed.database' => 'testing',
        ]);
    }
}
