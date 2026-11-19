<?php

declare(strict_types=1);

namespace Vlec\Verifactu;

/**
 * AEAT's answer about a customer's tax ID and name.
 */
readonly class TaxIdCheck
{
    /**
     * @param  string|null  $officialName  The name AEAT holds, when the result is similar.
     */
    public function __construct(
        public TaxIdCheckResult $result,
        public ?string $officialName = null,
    ) {}
}
