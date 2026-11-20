<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Tests\Fixtures\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An app's invoice before the package: its own number, hash and QR code.
 */
class LegacyInvoice extends Model {}
