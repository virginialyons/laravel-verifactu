<?php

declare(strict_types=1);

namespace Vlec\Verifactu;

/**
 * What AEAT's census says about a Spanish tax ID and name.
 */
enum TaxIdCheckResult: string
{
    case Identified = 'identified';
    case Similar = 'similar';
    case NotIdentified = 'not_identified';
    case Deregistered = 'deregistered';
    case Revoked = 'revoked';
    case NotProcessed = 'not_processed';
    case NotChecked = 'not_checked';

    /**
     * AEAT doesn't accept invoices to this tax ID under this name.
     */
    public function isRefused(): bool
    {
        return match ($this) {
            self::NotIdentified, self::Deregistered, self::Revoked => true,
            default => false,
        };
    }
}
