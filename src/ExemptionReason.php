<?php

declare(strict_types=1);

namespace Vlec\Verifactu;

/**
 * Why a line is exempt from VAT, by the article of the VAT law (Ley 37/1992).
 */
enum ExemptionReason: string
{
    /** Article 20: education, health, rentals and other operations in Spain. */
    case InternalOperations = 'E1';

    /** Article 21: exports. */
    case Exports = 'E2';

    /** Article 22: operations treated as exports. TaxLine::exempt() refuses it until its regime key is confirmed. */
    case ExportLikeOperations = 'E3';

    /** Articles 23 and 24: free zones, customs and tax warehouses. */
    case CustomsAndTaxWarehouses = 'E4';

    /** Article 25: supplies to businesses in other EU countries. */
    case IntraEuSupplies = 'E5';

    case Other = 'E6';

    /**
     * AEAT refuses an export (E2) under the general regime key 01, so it
     * goes with key 02, as the service's export example does. Null leaves
     * the service's default, 01.
     */
    public function regimeKey(): ?string
    {
        if ($this === self::Exports) {
            return '02';
        }

        return null;
    }
}
