<?php

declare(strict_types=1);

use Illuminate\Support\Str;

arch()->preset()->php();

arch()->preset()->security();

arch('it will not use dd(), ddd(), env(), or exit()')
    ->expect(['dd', 'ddd', 'env', 'exit'])
    ->each->not->toBeUsed();

/*
| Laravel's helpers over PHP's own functions, as in the service (owner, 2026-11-25): every array_* function, and the
| others with a direct Laravel twin. rector.php rewrites the ones whose helper takes the same arguments; the rest are
| written by hand with Arr, Str, collect(), File or now(). Two-number max() and min(), count(), $array[] = and spread
| syntax stay PHP.
*/
arch('Laravel\'s helpers are used instead of PHP\'s array, string, file and time functions')
    ->expect(['Vlec\Verifactu', 'Workbench'])
    ->not->toUse([
        ...collect(get_defined_functions()['internal'])->filter(fn (string $function): bool => Str::startsWith($function, 'array_'))->values()->all(),
        'explode',
        'implode',
        'in_array',
        'mb_strtoupper',
        'mb_strtolower',
        'dirname',
        'basename',
        'file_get_contents',
        'file_put_contents',
        'time',
        'date',
    ]);

arch('the package source declares strict types')
    ->expect('Vlec\Verifactu')
    ->toUseStrictTypes();
