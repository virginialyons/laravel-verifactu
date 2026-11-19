<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Vlec\Verifactu\Verifactu
 */
class Verifactu extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Vlec\Verifactu\Verifactu::class;
    }
}
