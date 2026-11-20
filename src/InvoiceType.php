<?php

declare(strict_types=1);

namespace Vlec\Verifactu;

/**
 * The AEAT invoice types the builder issues.
 */
enum InvoiceType: string
{
    case Full = 'F1';
    case Simplified = 'F2';
    case ReplacesSimplified = 'F3';
    case Corrective = 'R1';
    case CorrectiveOfSimplified = 'R5';

    public function isCorrective(): bool
    {
        return $this === self::Corrective || $this === self::CorrectiveOfSimplified;
    }
}
