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

    /** Article 22: operations treated as exports. */
    case ExportLikeOperations = 'E3';

    /** Articles 23 and 24: free zones, customs and tax warehouses. */
    case CustomsAndTaxWarehouses = 'E4';

    /** Article 25: supplies to businesses in other EU countries. */
    case IntraEuSupplies = 'E5';

    case Other = 'E6';
}
