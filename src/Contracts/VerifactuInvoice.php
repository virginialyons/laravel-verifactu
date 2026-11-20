<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Contracts;

use Vlec\Verifactu\InvoiceData;

/**
 * An app's invoice model. The app writes toVerifactu(); the
 * IssuesVerifactuInvoices concern provides the rest.
 */
interface VerifactuInvoice
{
    /**
     * Describes the sale: what was sold, to whom, and its tax lines.
     */
    public function toVerifactu(): InvoiceData;

    public function sendToVerifactu(): void;

    public function refreshFromVerifactu(): void;

    public function correctOnVerifactu(?string $description = null): void;

    public function cancelOnVerifactu(): void;

    public function isOnVerifactu(): bool;

    /**
     * The invoice's id on the service; throws before it is issued.
     */
    public function verifactuUuid(): string;

    /**
     * The app's id for the sale on the service: "invoice:{key}".
     */
    public function verifactuReference(): string;

    /**
     * The body of POST /invoices: the sale and its reference.
     *
     * @return array<string, mixed>
     */
    public function toVerifactuRequest(): array;

    /**
     * Stores an invoice the service answered with, and fires the event of
     * its new status when the status changed.
     *
     * @param  array<string, mixed>  $invoice
     */
    public function storeVerifactuInvoice(array $invoice): void;
}
