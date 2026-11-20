# Release Notes

All notable changes to `vlec/laravel-verifactu` are recorded here. Each release's notes are added when it is published on GitHub.

## v0.1.0 - 2026-11-20

The first release: issue VERI*FACTU invoices from a Laravel app through the Verifactu service.

### Requirements

- PHP 8.4+ with `ext-bcmath`
- Laravel 13
- A queue worker on a real queue connection, such as `database` or `redis`
- An app on the Verifactu service: its API token and webhook signing secret

### What's included

- **The invoice model:** the `VerifactuInvoice` contract and the `IssuesVerifactuInvoices` trait. The model's `toVerifactu()` describes the sale in whole cents: VAT, exempt and mixed tax lines, the recipient, and F2, F3, R1 and R5 invoices.
- **Issuing:** `$invoice->sendToVerifactu()` sends the invoice from a queued job after the transaction commits. The job retries a service that is down or busy for 24 hours with the same `Idempotency-Key`, so an invoice is never issued twice.
- **Batches:** `Verifactu::sendBatch($invoices)` sends several invoices at once and applies each result when the batch completes.
- **Fixing and cancelling:** `$invoice->correctOnVerifactu()` and `$invoice->cancelOnVerifactu()`.
- **Events:** `InvoiceIssued`, `InvoiceNotIssued`, `InvoiceAccepted`, `InvoiceAcceptedWithErrors`, `InvoiceRejected`, `InvoiceCancelled` and `InvoiceBatchCompleted`.
- **Webhooks:** a signed webhook route that keeps the model's columns up to date with AEAT's answer.
- **Tax-ID checks:** `Verifactu::checkTaxId()` and the `KnownToAeat` validation rule.
- **Calling the API directly:** the `Verifactu` facade for the service's other endpoints.
- **Testing:** `Verifactu::fake()` answers like the service in an app's tests, without sending anything.
- **Commands:** `verifactu:install` (the install wizard), `verifactu:status`, `verifactu:refresh-pending` (schedule it hourly) and `verifactu:correct`. `php artisan about` shows the connection, never the token or the secret.

The token decides the environment: a `vf_test_` token issues in Test, a `vf_prod_` token in Live.

### Installation

```bash
composer require vlec/laravel-verifactu:^0.1
php artisan verifactu:install
```

Until `1.0.0`, a minor version may change the public API. Read the [README](https://github.com/virginialyons/laravel-verifactu#readme) for the full guide.

