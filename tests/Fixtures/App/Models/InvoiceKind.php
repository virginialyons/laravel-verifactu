<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Tests\Fixtures\App\Models;

/**
 * Not a model, though it lives in app/Models.
 */
enum InvoiceKind: string
{
    case Sale = 'sale';
}
