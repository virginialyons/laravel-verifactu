# Release Notes

All notable changes to `vlec/laravel-verifactu` are recorded here. Each release's notes are added when it is published on GitHub.

## v0.2.1 - 2026-11-25

`Verifactu::fake()` now answers tax-ID checks the way the Verifactu service does in each environment.

- **Tax-ID checks in Test:** with a `vf_test_` token (the fake's own, when none is set), a tax-ID check answers `not_checked` and no invoice is refused with `recipient_not_identified`, as the service does in Test, where AEAT has no checker (a malformed tax ID is still refused with `invalid_tax_id`). With a `vf_prod_` token it answers `identified`, as before.
- **`taxIdCheck()` works with any token:** `Verifactu::fake()->taxIdCheck(TaxIdCheckResult::NotIdentified)` still makes checks, and issuing to a Spanish tax ID, fail with `recipient_not_identified`, so your tests can try a refused customer without a Live token.
- **Docs:** the README names the two differences between Test and Live (Test checks no tax ID; Live has its own numbering, and an administrator sets its next number when you carry on from another system), and says the install wizard asks for all seven columns.

If a test expected `identified` from the fake with a Test token, expect `not_checked`, or call `taxIdCheck(TaxIdCheckResult::Identified)` first.

There is no new migration or config key.

## v0.2.0 - 2026-11-25

Corrective and replacing invoices refer to any original by its full number, including an invoice issued from another system before the service. Read [Upgrading from 0.1 to 0.2](README.md#from-01-to-02) before deploying: let the queue run dry first.

- **References by full number:** `correcting()` and `replacing()` send the original's `invoice_number` and `issue_date`, never its `number`. They also take an invoice the app issued from another system before the service, when its `invoice_number` and `issue_date` columns hold them. Cancelling stays limited to invoices issued through the service.
- **`taxLines()` returns a collection** of `TaxLine`, not an array.
- **Retries through Laravel's backoff:** the jobs wait 10, 30, 60, then 300 seconds through Laravel's `$backoff` property, as before. The `$releaseDelays` property is gone. A `Retry-After` from the service still wins.
- **The fake checks references as the service does:** a reference with neither `number` nor `invoice_number` (an original not issued yet) is refused with `422` `field_required` on its `number`, and its `invoice_number` and `issue_date` get the service's field rules.
- **AI agents:** the README shows how to install the package's Laravel Boost guideline and skill with `boost:install`, and refresh them with `boost:update`. Both describe 0.2.
- **Requirements:** Laravel 13.44 or later.

There is no new migration or config key.

## v0.1.1 - 2026-11-24

`Verifactu::fake()` now numbers invoices the way the Verifactu service does.

- **Yearly numbers:** invoice numbers are printed `SERIES-YYYY-NNNNN`, such as `FACT-2026-00042`. `number` is the counter within the series and the issue year, and it starts again at 1 every 1 January (Madrid time).
- **References by number:** a corrected or replaced invoice referenced by `number` is matched with the year of its `issue_date`: `number` `5` with `issue_date` `2025-03-01` is `FACT-2025-00005`.
- **Refused reference numbers:** a reference `number` that is not a whole number from 1, such as `A-1` or `07`, is refused with `422` `invalid_referenced_invoice`, as the service refuses it.

If your tests expected numbers such as `FACT1`, expect `FACT-2026-00001` instead.

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
