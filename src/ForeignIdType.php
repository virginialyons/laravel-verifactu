<?php

declare(strict_types=1);

namespace Vlec\Verifactu;

/**
 * The document that identifies a foreign customer.
 */
enum ForeignIdType: string
{
    case EuVatNumber = '02';
    case Passport = '03';
    case NationalId = '04';
    case ResidenceCertificate = '05';
    case OtherDocument = '06';
    case NotRegistered = '07';
}
