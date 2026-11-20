<?php

declare(strict_types=1);

namespace Vlec\Verifactu;

use Illuminate\Support\Str;

enum Environment: string
{
    case Test = 'test';
    case Live = 'production';

    public static function fromToken(string $token): ?self
    {
        if (Str::startsWith($token, 'vf_test_')) {
            return self::Test;
        }

        if (Str::startsWith($token, 'vf_prod_')) {
            return self::Live;
        }

        return null;
    }
}
