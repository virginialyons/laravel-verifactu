<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Facades;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Facade;
use Vlec\Verifactu\Client;
use Vlec\Verifactu\Contracts\VerifactuInvoice;
use Vlec\Verifactu\InvoiceStatus;
use Vlec\Verifactu\TaxIdCheck;
use Vlec\Verifactu\TaxIdCheckResult;
use Vlec\Verifactu\Testing\VerifactuFake;

/**
 * @method static PendingRequest http()
 * @method static Client withoutRetries()
 * @method static void sendBatch(iterable<int, Model&VerifactuInvoice> $invoices)
 * @method static array<string, mixed> listInvoices(array<string, mixed> $query = [])
 * @method static array<string, mixed> createInvoice(array<string, mixed> $invoice, ?string $idempotencyKey = null)
 * @method static array<string, mixed> getInvoice(string $uuid)
 * @method static array<string, mixed> correctInvoice(string $uuid, array<string, mixed> $correction, ?string $idempotencyKey = null)
 * @method static array<string, mixed> cancelInvoice(string $uuid, ?string $idempotencyKey = null)
 * @method static array<string, mixed> createInvoiceBatch(array<string, mixed> $batch, ?string $idempotencyKey = null)
 * @method static array<string, mixed> getInvoiceBatch(string $uuid)
 * @method static array<string, mixed> listInvoiceBatchItems(string $uuid, int $page = 1)
 * @method static TaxIdCheck checkTaxId(string $taxId, string $name)
 * @method static VerifactuFake decide(Model&VerifactuInvoice $invoice, InvoiceStatus $status, ?string $errorCode = null, ?string $errorMessage = null)
 * @method static VerifactuFake taxIdCheck(TaxIdCheckResult $result, ?string $officialName = null)
 * @method static void assertIssued(Model&VerifactuInvoice $invoice, (Closure(array<mixed>): bool)|null $callback = null)
 * @method static void assertNothingIssued()
 * @method static void assertBatchSent()
 * @method static void assertCorrected(Model&VerifactuInvoice $invoice)
 * @method static void assertCancelled(Model&VerifactuInvoice $invoice)
 *
 * @see Client
 */
class Verifactu extends Facade
{
    /**
     * Replaces the Verifactu service with an in-memory one for the app's
     * tests; the package's jobs, model methods and events still run.
     */
    public static function fake(): VerifactuFake
    {
        $fake = static::getFacadeRoot();

        // A second call, such as in a test after a beforeEach, keeps the fake the first one made.
        if (static::isFake() && $fake instanceof VerifactuFake) {
            return $fake;
        }

        $fake = new VerifactuFake;

        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
