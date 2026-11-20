---
name: laravel-verifactu-development
description: >
  Issue, batch, fix and cancel Spanish VERI*FACTU invoices with the vlec/laravel-verifactu
  package: the invoice model and its tax lines, events, webhooks, tax-ID checks and testing
  with Verifactu::fake().
license: MIT
metadata:
  author: Ilie
---

# Laravel Verifactu

Use this skill when a Laravel app issues invoices through the Verifactu service with `vlec/laravel-verifactu`: setting it up, writing the invoice model, sending, fixing or cancelling invoices, listening to AEAT's answer, validating a customer's tax ID, or testing any of it.

## Primary Goal

- Keep the app's call sites short (`$invoice->sendToVerifactu()`) and let the package handle the HTTP, retries, idempotency, webhooks and stored columns.

## Workflow

### 1. Install and configure

```bash
composer require vlec/laravel-verifactu
php artisan verifactu:install   # once the invoice model's table is migrated
php artisan migrate
```

- The wizard publishes `config/verifactu.php`, adds `VERIFACTU_URL`, `VERIFACTU_TOKEN` and `VERIFACTU_WEBHOOK_SECRET` to `.env`, asks for the invoice model and its columns, and writes an add-only migration.
- The token decides the environment: `vf_test_` is Test (AEAT pre-production), `vf_prod_` is Live. Each database (each developer, staging, production) needs its own app and token on the service.
- The columns (`config('verifactu.columns')`) must exist before the first send. The status column is cast to `Vlec\Verifactu\InvoiceStatus`.
- The jobs need a real queue (`database`, `redis`) outside tests.
- Schedule the catch-up for missed webhooks in `routes/console.php`: `Schedule::command('verifactu:refresh-pending')->hourly();`

### 2. Write the model

```php
use Vlec\Verifactu\Contracts\VerifactuInvoice;
use Vlec\Verifactu\InvoiceData;
use Vlec\Verifactu\IssuesVerifactuInvoices;
use Vlec\Verifactu\Recipient;
use Vlec\Verifactu\TaxLine;

class Invoice extends Model implements VerifactuInvoice
{
    use IssuesVerifactuInvoices;

    public function toVerifactu(): InvoiceData
    {
        $lines = [];

        if ($this->exempt_cents !== 0) {
            $lines[] = TaxLine::exempt($this->exempt_cents);
        }

        if ($this->taxable_cents !== 0) {
            $lines[] = TaxLine::vat($this->taxable_cents, rate: 21);
        }

        return InvoiceData::make("Order {$this->id}")
            ->to(Recipient::spanish($this->customer_tax_id, $this->customer_name))
            ->lines($lines);
    }

    protected function casts(): array
    {
        return ['exempt_cents' => 'integer', 'taxable_cents' => 'integer'];
    }
}
```

- Amounts are whole cents, cast to `integer`. `TaxLine::vat($cents, rate: 21)` (rate in percent, an `int` or a numeric string); `TaxLine::exempt($cents)` uses `config('verifactu.exemption_reason')` (`E1`), or pass an `ExemptionReason` (`Exports`, `IntraEuSupplies`…).
- Pass the app's items as they are: same-rate lines are grouped and the tax is worked out once per group, rounded half away from zero.
- The package doesn't validate the invoice: the service does. Its refusals (`invalid_lines`, `recipient_not_allowed`, `invalid_referenced_invoice`…) arrive as `InvoiceNotIssued` with the service's `errorCode`, one invoice at a time, also in a batch. Don't add checks that copy the service's rules.
- `Recipient::spanish($taxId, $name)` or `Recipient::foreign($name, $countryCode, ForeignIdType::Passport, $id)`.
- `->simplified()` (F2, no recipient), `->replacing(...$simplifiedInvoices)` (F3), `->correcting($original)` (a corrective invoice for the difference, R1 or R5, with negative lines to lower the original; a new row of the model).
- `$invoice->toVerifactu()->taxLines()` and `->totalCents()` give what AEAT receives, for printing.

### 3. Issue, fix and cancel

```php
use Vlec\Verifactu\Facades\Verifactu;

$invoice->sendToVerifactu();          // queued, after commit
Verifactu::sendBatch($invoices);      // one batch, queued
$invoice->correctOnVerifactu('Spanish course, B2'); // new record: description only, or none to resend
$invoice->cancelOnVerifactu();        // issued by mistake; a refund is a corrective invoice instead
```

- `correctOnVerifactu()` and `cancelOnVerifactu()` call the service directly: call them after the transaction commits, and catch `Vlec\Verifactu\Exceptions\VerifactuException`. Branch on `$exception->errorCode` (`invoice_awaiting_aeat`, `invoice_cancelled`…) and show `getMessage()`.
- Print `invoice_number`, `issue_date` and `qr_code` (a base64 PNG: `<img src="data:image/png;base64,{{ $invoice->qr_code }}">`) from the configured columns.

### 4. Listen to the outcome

Events in `Vlec\Verifactu\Events`, each with `$event->invoice`:

- `InvoiceIssued`: number, date and QR code are stored. Send the PDF here.
- `InvoiceNotIssued` (`errorCode`, `message`): the service refused it, or never answered (null `errorCode`); nothing was issued.
- `InvoiceAccepted`, `InvoiceAcceptedWithErrors` and `InvoiceRejected` (`errorCode`, `errorMessage` from AEAT), `InvoiceCancelled`.
- `InvoiceBatchCompleted` (`batch`, `total`, `succeeded`, `failed`).

Make listeners `ShouldQueue` and safe to run twice. A status event fires only when the stored status changes.

The webhook route (`POST /verifactu/webhook`, named `verifactu.webhook`) is registered by the package outside the `web` group, with its signature checked. Don't add a route, CSRF exemption or controller for it.

### 5. Check a customer's tax ID

```php
use Vlec\Verifactu\Rules\KnownToAeat;

$request->validate([
    'legal_name' => ['required', 'string', 'max:120'],
    'tax_id' => ['bail', 'required', 'string', new KnownToAeat(nameField: 'legal_name')],
]);
```

Put the rule last, after `bail`. The name field is top-level or a fixed dotted path, never a wildcard. For a direct check: `Verifactu::checkTaxId($taxId, $name)` returns a `TaxIdCheck` (`result`, `officialName`).

### 6. Test

```php
use Vlec\Verifactu\Facades\Verifactu;
use Vlec\Verifactu\InvoiceStatus;

Verifactu::fake();
$invoice = Invoice::factory()->create();

$invoice->sendToVerifactu();
Verifactu::decide($invoice, InvoiceStatus::Accepted);

Verifactu::assertIssued($invoice, fn (array $body): bool => $body['total_amount'] === '121.00');
expect($invoice->fresh()->verifactu_status)->toBe(InvoiceStatus::Accepted);
```

- Run the jobs on the `sync` queue; don't `Queue::fake()` them. Call `Verifactu::fake()` before any catch-all `Http::fake()`.
- `decide($invoice, InvoiceStatus::Rejected, errorCode: '1105')` plays AEAT's answer.
- `taxIdCheck(TaxIdCheckResult::NotIdentified)` makes tax-ID checks, and issuing to a Spanish tax ID, fail as the service does.
- Assertions: `assertIssued`, `assertNothingIssued`, `assertBatchSent`, `assertCorrected`, `assertCancelled`.

## Rules, References, and Templates

- The package's `README.md` holds the full guide, with every config key and command.
- Commands: `verifactu:install`, `verifactu:status`, `verifactu:refresh-pending`, `verifactu:correct {invoice} {--description=}`.
- Endpoints without a facade method: `Verifactu::http()->get('exports/xml', ['year' => 2026, 'month' => 11])->throw()->json('data')`.

## Examples

- "Send the invoice when the order is paid": in the payment handler's transaction, save the invoice row and call `$invoice->sendToVerifactu()`; listen to `InvoiceIssued` to email the PDF.
- "Refund part of an order": create a new invoice row whose `toVerifactu()` returns negative lines with `->correcting($original)`, then `sendToVerifactu()` it.
- "Show AEAT's rejection to the accounts team": listen to `InvoiceRejected` and notify with `$event->errorCode` and `$event->errorMessage`.

## Anti-patterns

- Floats or decimal strings for money; computing VAT in the app.
- Writing `external_reference`, `Idempotency-Key`s, HTTP calls or webhook handling by hand.
- Editing or deleting an issued invoice's row, or cancelling a refunded sale (issue a corrective invoice).
- Calling `correctOnVerifactu()` or `cancelOnVerifactu()` inside a transaction that may roll back.
- Branching on an exception's message; logging the token or the webhook secret.
- `Queue::fake()` or `Http::fake()` in place of `Verifactu::fake()` in feature tests.
