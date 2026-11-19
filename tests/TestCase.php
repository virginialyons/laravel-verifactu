<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Vlec\Verifactu\VerifactuServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            VerifactuServiceProvider::class,
        ];
    }
}
