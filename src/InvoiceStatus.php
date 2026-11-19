<?php

declare(strict_types=1);

namespace Vlec\Verifactu;

/**
 * Where an invoice is with AEAT, as the service reports it.
 */
enum InvoiceStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case AcceptedWithErrors = 'accepted_with_errors';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
