<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Node\RemoveNonExistingVarAnnotationRector;
use Rector\Php84\Rector\Foreach_\ForeachToArrayFindKeyRector;
use Rector\Set\ValueObject\SetList;
use RectorLaravel\Rector\FuncCall\ConfigToTypedConfigMethodCallRector;
use RectorLaravel\Rector\FuncCall\FuncCallToStaticCallRector;
use RectorLaravel\Rector\StaticCall\AssertWithClassStringToTypeHintedClosureRector;
use RectorLaravel\Set\LaravelSetList;
use RectorLaravel\ValueObject\FuncCallToStaticCall;

/*
 * Laravel's helpers over PHP's own functions, as in the service (owner, 2026-11-25). FuncCallToStaticCallRector keeps
 * the arguments as they are, so it only maps functions whose helper takes the same arguments in the same order. The
 * Str helpers count characters, not bytes (mb_*): where a byte count matters, such as a signed body's length, skip the
 * rule for that file. tests/ArchTest.php forbids every other array_* function and the remaining functions with a
 * Laravel twin (explode, implode, in_array, dirname, file_get_contents, time, date…): their helpers reorder the
 * arguments, also pass the key, drop the encoding or return a collection, so they are written by hand.
 */
$helpers = [
    new FuncCallToStaticCall('array_all', Arr::class, 'every'),
    new FuncCallToStaticCall('array_any', Arr::class, 'some'),
    new FuncCallToStaticCall('array_find', Arr::class, 'first'),
    new FuncCallToStaticCall('array_first', Arr::class, 'first'),
    new FuncCallToStaticCall('array_last', Arr::class, 'last'),
    new FuncCallToStaticCall('array_is_list', Arr::class, 'isList'),
    new FuncCallToStaticCall('array_column', Arr::class, 'pluck'),
    new FuncCallToStaticCall('str_contains', Str::class, 'contains'),
    new FuncCallToStaticCall('str_starts_with', Str::class, 'startsWith'),
    new FuncCallToStaticCall('str_ends_with', Str::class, 'endsWith'),
    new FuncCallToStaticCall('str_replace', Str::class, 'replace'),
    new FuncCallToStaticCall('preg_replace', Str::class, 'replaceMatches'),
    new FuncCallToStaticCall('strtoupper', Str::class, 'upper'),
    new FuncCallToStaticCall('strtolower', Str::class, 'lower'),
    new FuncCallToStaticCall('ucfirst', Str::class, 'ucfirst'),
    new FuncCallToStaticCall('lcfirst', Str::class, 'lcfirst'),
    new FuncCallToStaticCall('ucwords', Str::class, 'ucwords'),
    new FuncCallToStaticCall('strlen', Str::class, 'length'),
    new FuncCallToStaticCall('mb_strlen', Str::class, 'length'),
    new FuncCallToStaticCall('substr', Str::class, 'substr'),
    new FuncCallToStaticCall('mb_substr', Str::class, 'substr'),
    new FuncCallToStaticCall('trim', Str::class, 'trim'),
    new FuncCallToStaticCall('ltrim', Str::class, 'ltrim'),
    new FuncCallToStaticCall('rtrim', Str::class, 'rtrim'),
    new FuncCallToStaticCall('str_repeat', Str::class, 'repeat'),
    new FuncCallToStaticCall('strrev', Str::class, 'reverse'),
    new FuncCallToStaticCall('wordwrap', Str::class, 'wordWrap'),
    new FuncCallToStaticCall('base64_encode', Str::class, 'toBase64'),
    new FuncCallToStaticCall('base64_decode', Str::class, 'fromBase64'),
];

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/src',
        __DIR__.'/tests',
        __DIR__.'/workbench',
    ])
    ->withPhpSets()
    ->withSets([
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::TYPE_DECLARATION,
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
        LaravelSetList::LARAVEL_IF_HELPERS,
        LaravelSetList::LARAVEL_TESTING,
        LaravelSetList::LARAVEL_ARRAY_STR_FUNCTION_TO_STATIC_CALL,
        LaravelSetList::LARAVEL_ARRAYACCESS_TO_METHOD_CALL,
        LaravelSetList::LARAVEL_FACADE_ALIASES_TO_FULL_NAMES,
        LaravelSetList::LARAVEL_CONTAINER_STRING_TO_FULLY_QUALIFIED_NAME,
    ])
    ->withComposerBased(laravel: true)
    // Rector's default cache folder is shared by every project on the machine; a run with another config can leave it half-deleted.
    ->withCache(cacheDirectory: sys_get_temp_dir().'/rector/laravel-verifactu')
    ->withRules([
        ConfigToTypedConfigMethodCallRector::class,
    ])
    ->withConfiguredRule(FuncCallToStaticCallRector::class, $helpers)
    ->withSkip([
        __DIR__.'/database/migrations',
        // Arr has no helper for array_find_key.
        ForeachToArrayFindKeyRector::class,
        // PHPStan can't type $body['data'] without its @var, so the return would become mixed.
        RemoveNonExistingVarAnnotationRector::class => [__DIR__.'/src/Client.php'],
        // rector-laravel 2.7.3 crashes on this file's union-typed assert (ObjectWithoutClassType::getClassName()); its other asserts are written by hand.
        AssertWithClassStringToTypeHintedClosureRector::class => [__DIR__.'/tests/Feature/IssuesVerifactuInvoicesTest.php'],
    ]);
