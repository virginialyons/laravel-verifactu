<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Facades;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Facade;
use Vlec\Verifactu\Client;

/**
 * @method static PendingRequest http()
 * @method static Client withoutRetries()
 * @method static void sendBatch(iterable<int, \Illuminate\Database\Eloquent\Model&\Vlec\Verifactu\Contracts\VerifactuInvoice> $invoices)
 * @method static array<string, mixed> listInvoices(array<string, mixed> $query = [])
 * @method static array<string, mixed> createInvoice(array<string, mixed> $invoice, ?string $idempotencyKey = null)
 * @method static array<string, mixed> getInvoice(string $uuid)
 * @method static array<string, mixed> correctInvoice(string $uuid, array<string, mixed> $correction, ?string $idempotencyKey = null)
 * @method static array<string, mixed> cancelInvoice(string $uuid, ?string $idempotencyKey = null)
 * @method static array<string, mixed> createInvoiceBatch(array<string, mixed> $batch, ?string $idempotencyKey = null)
 * @method static array<string, mixed> getInvoiceBatch(string $uuid)
 * @method static array<string, mixed> listInvoiceBatchItems(string $uuid, int $page = 1)
 *
 * @see Client
 */
class Verifactu extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
