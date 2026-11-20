# Laravel Verifactu

Issue VERI*FACTU invoices from a Laravel app through the Verifactu service.

Your invoice model describes the sale. The package sends it to the service, which numbers it and sends its record to AEAT, and keeps the model's table up to date with AEAT's answer through signed webhooks. It also issues batches, fixes and cancels invoices, and checks a customer's tax ID.

This package is built for the company's own apps.

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [The invoice model](#the-invoice-model)
- [Issuing invoices](#issuing-invoices)
- [Batches](#batches)
- [Fixing and cancelling](#fixing-and-cancelling)
- [Events](#events)
- [Webhooks](#webhooks)
- [Tax-ID checks](#tax-id-checks)
- [Calling the API directly](#calling-the-api-directly)
- [Testing](#testing)
- [Commands](#commands)
- [Upgrading](#upgrading)

## Requirements

- PHP 8.4+ with `ext-bcmath`
- Laravel 13
- A queue worker on a real queue connection, such as `database` or `redis`
- An app on the Verifactu service: its API token and webhook signing secret

## Installation

```bash
composer require vlec/laravel-verifactu
php artisan verifactu:install
```

Run the wizard once the invoice model's table is migrated:

1. Publishes `config/verifactu.php`.
2. Adds `VERIFACTU_URL`, `VERIFACTU_TOKEN` and `VERIFACTU_WEBHOOK_SECRET`, empty, to `.env` and `.env.example`. A key already set keeps its value.
3. Asks which model is the invoice, and which of its columns already hold the number, date, status and QR code.
4. Writes those choices into the config, and a migration that adds only the missing columns. It also publishes the migration of the package's own `verifactu_invoice_batches` table.
5. Lists the columns of an earlier AEAT setup of your own (a hash, a chain, XML), which the service keeps now, and prints a migration to drop them if you want to. It never drops anything.
6. Prints the lines to add to the model, the schedule line and the webhook URL.

Then fill the three keys and run the migrations:

```bash
php artisan migrate
```

The wizard's migration runs on the default database connection, as every app's own migrations do. If the invoice model lives on another connection, move the columns there by hand.

## Configuration

```ini
VERIFACTU_URL=https://verifactu.example.com
VERIFACTU_TOKEN=vf_test_...
VERIFACTU_WEBHOOK_SECRET=whsec_...
```

An administrator of the service gives you these on your app's page, under Apps. The token decides the environment: a `vf_test_` token works in Test (AEAT's pre-production), a `vf_prod_` token in Live.

Give each database its own app and token on the service: each developer, staging and production. The package finds an invoice on the service by its model key (`invoice:42`), so two databases sharing a token would both claim `invoice:1`.

Everything else lives in `config/verifactu.php`:

| Key                | Default               | What it does                                                                 |
| ------------------ | --------------------- | ---------------------------------------------------------------------------- |
| `model`            | `App\Models\Invoice`  | The invoice model                                                            |
| `columns`          | `verifactu_*`         | The model's columns for the service's answer (below)                         |
| `exemption_reason` | `E1`                  | The reason `TaxLine::exempt()` uses when given none                          |
| `webhook_path`     | `verifactu/webhook`   | The path the service posts its webhooks to                                   |
| `queue`            | `null`                | The queue of the package's jobs; `null` for the default one                 |
| `retries`          | `2`                   | Retries of a direct call after no answer, a `429`, a `5xx`                   |
| `connect_timeout`  | `5`                   | Seconds to wait for a connection                                             |
| `timeout`          | `30`                  | Seconds to wait for the whole answer                                         |

The columns, each of which you may rename:

| Field              | Column                      | Holds                                                         |
| ------------------ | --------------------------- | ------------------------------------------------------------- |
| `uuid`             | `verifactu_uuid`            | The invoice's id on the service                               |
| `status`           | `verifactu_status`          | Its status at AEAT, cast to `Vlec\Verifactu\InvoiceStatus`    |
| `invoice_number`   | `verifactu_invoice_number`  | The number to print, such as `FACT42`                         |
| `number`           | `verifactu_number`          | The bare number a corrective invoice refers to, such as `42`  |
| `issue_date`       | `verifactu_issue_date`      | The issue date, cast to a date unless the model casts it      |
| `verification_url` | `verifactu_url`             | AEAT's verification URL                                       |
| `qr_code`          | `qr_code`                   | The QR code, a base64 PNG without a `data:` prefix            |

Every column must exist before the first invoice is sent: the package stores the service's answer the moment it arrives.

## The invoice model

The model implements `VerifactuInvoice`, uses `IssuesVerifactuInvoices`, and describes the sale in `toVerifactu()`. Amounts are whole cents, never floats.

```php
use Illuminate\Database\Eloquent\Model;
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
        return InvoiceData::make("Order {$this->id}")
            ->to(Recipient::spanish($this->customer_tax_id, $this->customer_name))
            ->lines([TaxLine::vat($this->subtotal_cents, rate: 21)]);
    }
}
```

### Tax lines

A line subject to VAT takes its base in cents and the rate in percent, as an `int` or a numeric string:

```php
TaxLine::vat(10000, rate: 21);
```

An exempt line takes its base and, optionally, the reason. Without one it uses `config('verifactu.exemption_reason')`, `E1` (article 20 of the VAT law: education, health…):

```php
use Vlec\Verifactu\ExemptionReason;

TaxLine::exempt(30000);
TaxLine::exempt(20000, ExemptionReason::Exports);
```

The reasons are `InternalOperations` (`E1`), `Exports` (`E2`), `ExportLikeOperations` (`E3`), `CustomsAndTaxWarehouses` (`E4`), `IntraEuSupplies` (`E5`) and `Other` (`E6`).

An invoice may mix VAT and exempt lines. An order with an exempt course and a book at 21% VAT:

```php
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
    return [
        'exempt_cents' => 'integer',
        'taxable_cents' => 'integer',
    ];
}
```

Cast the cent columns to `integer`, so a `0` read from the database or a request is the integer `0`.

Pass your own items as they are: lines with the same VAT rate, or the same exemption reason, become one line with their bases added up. The tax is worked out once per grouped line, rounded half away from zero to the cent (-52.5 cents is -53). The service takes 1 to 12 grouped lines.

`taxLines()` and `totalCents()` give back exactly what AEAT receives, to print on the invoice:

```php
$data = $invoice->toVerifactu();

foreach ($data->taxLines() as $line) {
    $line->baseCents;
    $line->rate;            // a BcMath\Number, or null for an exempt line
    $line->exemptionReason; // an ExemptionReason, or null
    $line->taxCents();
}

$data->totalCents();
```

### The recipient

A Spanish customer goes by their tax ID (NIF) and the name AEAT knows. A foreign one goes by a document:

```php
use Vlec\Verifactu\ForeignIdType;

Recipient::spanish('A15022510', 'Acme S.L.');
Recipient::foreign('Jean Dupont', 'FR', ForeignIdType::EuVatNumber, 'FR40303265045');
```

The document types are `EuVatNumber`, `Passport`, `NationalId`, `ResidenceCertificate`, `OtherDocument` and `NotRegistered`.

### Other kinds of invoice

```php
// A simplified invoice (F2): no recipient.
InvoiceData::make('Coffee')->simplified()->lines([TaxLine::vat(150, rate: 10)]);

// A full invoice (F3) that replaces simplified invoices already issued.
InvoiceData::make('Order 7, with your details')
    ->to(Recipient::spanish('A15022510', 'Acme S.L.'))
    ->lines([TaxLine::vat(20000, rate: 21)])
    ->replacing($simplifiedInvoice);

// A corrective invoice for the difference: R1, or R5 when the original is simplified.
InvoiceData::make('Refund of 10.00 on order 42')
    ->to(Recipient::spanish('A15022510', 'Acme S.L.'))
    ->lines([TaxLine::vat(-1000, rate: 21)])
    ->correcting($original);
```

A corrective invoice is a new invoice: a new row of your invoice model whose `toVerifactu()` calls `correcting()`. Its lines are the change, negative to lower the original. `replacing()` and `correcting()` take your issued models and read their number and issue date.

The package doesn't judge the invoice: the service does. It sends what `toVerifactu()` describes, and whatever the service refuses comes back with the service's error code: an invoice with no lines or more than 12 (`invalid_lines`), a recipient on a simplified invoice (`recipient_not_allowed`), a reference to an original not issued yet… Sent with `sendToVerifactu()`, that is `InvoiceNotIssued` with the code; from a direct call, a `VerifactuException`.

## Issuing invoices

```php
$invoice->sendToVerifactu();
```

The invoice is sent from a queued job once the database transaction around the call commits, so it carries everything the transaction saved. When the service answers, the job stores the number, date, QR code and verification URL in the model's columns, sets the status to `pending` and fires `InvoiceIssued`. AEAT's answer arrives later by webhook.

The job retries a service that is down or busy for up to 24 hours, with the same `Idempotency-Key`, so an invoice is never issued twice. Sending an invoice that is already on the service stores the invoice the service holds instead of issuing a second one. If the service refuses the invoice, the job fires `InvoiceNotIssued` with the service's error code and message.

Run the jobs on a real queue. On the `sync` queue a job can't wait to retry, so a failure is lost.

```php
$invoice->isOnVerifactu();   // issued on the service
$invoice->verifactu_status;  // InvoiceStatus::Pending, Accepted, AcceptedWithErrors, Rejected or Cancelled
$invoice->refreshFromVerifactu(); // reads the invoice from the service and stores it
```

To print the QR code in HTML:

```blade
<img src="data:image/png;base64,{{ $invoice->qr_code }}" alt="QR">
```

## Batches

```php
use Vlec\Verifactu\Facades\Verifactu;

Verifactu::sendBatch($invoices);
```

Sends the invoices as one batch from a queued job, after the transaction commits. Invoices already on the service, and an invoice passed twice, are left out. When the batch is done, each issued invoice is stored and fires `InvoiceIssued`, each one the service refused fires `InvoiceNotIssued` with its error code, and the batch fires `InvoiceBatchCompleted`.

The service judges each invoice on its own: one it refuses doesn't stop the others.

Don't change the invoices while their batch is being sent. If the first answer is lost and an invoice changed before the retry, the service refuses the retry, and every invoice of the batch fires `InvoiceNotIssued`. Sending them again stores the invoices the service did issue.

## Fixing and cancelling

```php
$invoice->correctOnVerifactu('Spanish course, B2');
$invoice->cancelOnVerifactu();
```

`correctOnVerifactu()` sends AEAT a new record of the same invoice, which keeps its number, date and amounts. It fixes the description after a rejection or an acceptance with errors. Without a description it sends the same record again, after a technical rejection. Wrong amounts, a wrong rate or the wrong customer need a [corrective invoice](#other-kinds-of-invoice) instead.

`cancelOnVerifactu()` tells AEAT the invoice should never have been issued, such as a duplicate. A refunded sale is not cancelled: it needs a corrective invoice.

Both call the service at once, not from a queue, and store the status `pending`. AEAT's answer arrives by webhook. Call them after your database transaction commits: a call inside a transaction that rolls back would leave the service changed and your app not.

A refusal throws a `VerifactuException`. Branch on its `errorCode`, never on the message:

```php
use Vlec\Verifactu\Exceptions\VerifactuException;

try {
    $invoice->cancelOnVerifactu();
} catch (VerifactuException $exception) {
    // invoice_awaiting_aeat: AEAT has not answered for the last record yet; try again later.
    // invoice_cancelled: the invoice is cancelled already.
    return back()->withErrors(['invoice' => $exception->getMessage()]);
}
```

The exception carries `status`, `errorCode`, `errors` (the fields the service refused) and `retryAfter`. A service that doesn't answer throws too, after the configured retries, and the stored status stays as it was. The next webhook settles what happened, or call `$invoice->refreshFromVerifactu()`.

## Events

| Event                        | Fires when                                                          | Carries                                  |
| ---------------------------- | ------------------------------------------------------------------- | ---------------------------------------- |
| `InvoiceIssued`              | The service issued the invoice and its columns are stored           | `invoice`                                |
| `InvoiceNotIssued`           | The service refused the invoice, or never answered in 24 hours      | `invoice`, `errorCode`, `message`        |
| `InvoiceAccepted`            | AEAT accepted it                                                    | `invoice`                                |
| `InvoiceAcceptedWithErrors`  | AEAT registered it with errors: it counts, but needs a fix          | `invoice`, `errorCode`, `errorMessage`   |
| `InvoiceRejected`            | AEAT rejected it: fix it, or issue it again                         | `invoice`, `errorCode`, `errorMessage`   |
| `InvoiceCancelled`           | AEAT registered its cancellation                                    | `invoice`                                |
| `InvoiceBatchCompleted`      | A batch is done and each of its invoices was stored                 | `batch`, `total`, `succeeded`, `failed`  |

They live in `Vlec\Verifactu\Events`. A status event fires only when the stored status changes. AEAT's error code and message (in Spanish) stay on the event, not in a column.

```php
use Illuminate\Support\Facades\Event;
use Vlec\Verifactu\Events\InvoiceIssued;
use Vlec\Verifactu\Events\InvoiceRejected;

Event::listen(InvoiceIssued::class, SendInvoicePdf::class);
Event::listen(InvoiceRejected::class, NotifyAccounts::class);
```

Make listeners queued (`ShouldQueue`), so one that fails doesn't stop the events after it, and safe to run twice:

- When an invoice sent again is already decided by AEAT, its status event (`InvoiceAccepted`…) fires before `InvoiceIssued`.
- If storing a batch's results fails halfway, the batch is applied again later, and its refused invoices fire `InvoiceNotIssued` a second time.
- If storing an issued invoice keeps failing for 24 hours (a missing column), `InvoiceNotIssued` fires for an invoice AEAT has. Running the migration before sending avoids it.

## Webhooks

The package registers `POST /verifactu/webhook` (`config('verifactu.webhook_path')`), named `verifactu.webhook`. Give its full URL to an administrator of the service, who sets it on your app's page. `verifactu:install` and `php artisan about` print it.

The route is outside the `web` group, so it needs no CSRF exemption. Each delivery's signature is checked against `VERIFACTU_WEBHOOK_SECRET`; a wrong or stale one gets `403`. For each invoice in the delivery, the package reads the invoice from the service and stores it, so the newest state always wins, whatever order deliveries arrive in. Invoices it doesn't know are skipped.

A webhook can be missed, such as while the app is down. Schedule the catch-up in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('verifactu:refresh-pending')->hourly();
```

## Tax-ID checks

Check a Spanish customer's tax ID and name with AEAT before issuing to them:

```php
use Vlec\Verifactu\TaxIdCheckResult;

$check = Verifactu::checkTaxId('A15022510', 'Acme S.L.');

$check->result;       // TaxIdCheckResult::Identified, Similar, NotIdentified, Deregistered, Revoked, NotProcessed or NotChecked
$check->officialName; // the name AEAT holds, when the result is Similar
```

Or validate a form with the `KnownToAeat` rule, naming the field that holds the customer's name:

```php
use Vlec\Verifactu\Rules\KnownToAeat;

$request->validate([
    'legal_name' => ['required', 'string', 'max:120'],
    'tax_id' => ['bail', 'required', 'string', new KnownToAeat(nameField: 'legal_name')],
]);
```

- It refuses a tax ID AEAT doesn't know under that name, or knows as deregistered or revoked, and one the service finds malformed.
- When AEAT holds a similar name, it fails on the name field, with the official name.
- When AEAT or the service can't answer, it passes: the invoice is checked again when it is issued. A refusal that isn't an outage, such as a refused token, passes too, and is reported.
- Put it last, after `bail`, so it calls the service only once the rest of the field is valid. Other fields' rules don't stop it.
- The name field is a top-level field, or a fixed dotted one (`customer.legal_name`). A wildcard (`customers.*.legal_name`) checks nothing.

Its messages go through Laravel's translator. For Spanish, add them to `lang/es.json`:

```json
{
    "AEAT does not accept this tax ID with this name.": "AEAT no acepta este NIF con este nombre.",
    "AEAT holds this tax ID as: :name": "AEAT tiene este NIF como: :name",
    "This is not a valid Spanish tax ID.": "Este NIF no es válido.",
    "AEAT takes a name of at most 120 characters.": "AEAT admite un nombre de 120 caracteres como máximo."
}
```

## Calling the API directly

The `Verifactu` facade has a method for each operation the package uses. They take the API's arrays, retry as the config says, and throw `VerifactuException`. An invoice or a batch comes back as the resource itself (`['uuid' => …, 'status' => …]`); `listInvoices()` and `listInvoiceBatchItems()` return the page, with `data` and `meta`; `checkTaxId()` returns a `TaxIdCheck`.

```php
Verifactu::createInvoice($body);
Verifactu::getInvoice($uuid);
Verifactu::listInvoices(['filter' => ['external_reference' => 'invoice:42']]);
Verifactu::correctInvoice($uuid, $body);
Verifactu::cancelInvoice($uuid);
Verifactu::createInvoiceBatch($body);
Verifactu::getInvoiceBatch($uuid);
Verifactu::listInvoiceBatchItems($uuid, page: 2);
Verifactu::checkTaxId($taxId, $name);
```

For the other endpoints, such as a month's AEAT XML, `Verifactu::http()` gives Laravel's HTTP client with the base URL, token, timeouts and retries set. It doesn't throw on its own:

```php
$submissions = Verifactu::http()
    ->get('exports/xml', ['year' => 2026, 'month' => 11])
    ->throw()
    ->json('data');
```

## Testing

`Verifactu::fake()` replaces the service with an in-memory one that answers as the service does: it numbers invoices in order (`FACT1`, `FACT2`…), completes batches at once, and refuses what the service refuses. Your tests run the package's real jobs, model methods and events, with no HTTP.

```php
use Illuminate\Support\Facades\Event;
use Vlec\Verifactu\Events\InvoiceAccepted;
use Vlec\Verifactu\Facades\Verifactu;
use Vlec\Verifactu\InvoiceStatus;

it('issues the invoice and stores AEAT\'s acceptance', function () {
    Event::fake([InvoiceAccepted::class]);
    Verifactu::fake();
    $invoice = Invoice::factory()->create();

    $invoice->sendToVerifactu();
    Verifactu::decide($invoice, InvoiceStatus::Accepted);

    Verifactu::assertIssued($invoice);
    Event::assertDispatched(InvoiceAccepted::class);
    expect($invoice->fresh()->verifactu_status)->toBe(InvoiceStatus::Accepted);
});
```

- The jobs must run inside the test, on the `sync` queue (the default in `phpunit.xml`), not with `Queue::fake()`. Call `Verifactu::fake()` before any catch-all `Http::fake()`.
- `decide($invoice, $status, errorCode: '1105', errorMessage: '…')` plays AEAT's answer, as a webhook would: `Accepted`, `AcceptedWithErrors` or `Rejected`, or `Cancelled` after `cancelOnVerifactu()`.
- `taxIdCheck(TaxIdCheckResult::NotIdentified)` sets the answer to every later tax-ID check (`Similar` takes the official name too). With `NotIdentified`, issuing to a Spanish tax ID is refused with `recipient_not_identified`, as the service does.
- Assertions: `assertIssued($invoice, ?fn (array $body): bool)`, `assertNothingIssued()`, `assertBatchSent()`, `assertCorrected($invoice)` and `assertCancelled($invoice)`.

```php
Verifactu::assertIssued($invoice, fn (array $body): bool => $body['total_amount'] === '300.00');
```

## Commands

| Command                                              | What it does                                                                                     |
| ---------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| `verifactu:install`                                  | The install wizard. Running it again adds no second set of keys or migration                     |
| `verifactu:status`                                   | Checks the connection and the token's environment, and counts the invoices by status             |
| `verifactu:refresh-pending`                          | Refreshes invoices `pending` for over an hour, and applies batches not applied yet. Schedule it hourly |
| `verifactu:correct {invoice} {--description=}`       | `correctOnVerifactu()` from the command line, by the invoice's key                               |

`php artisan about` shows the service's URL, the environment, the invoice model and the webhook URL. It never shows the token or the secret.

## Upgrading

Until `1.0.0`, a minor version (`0.1` → `0.2`) may change the public API: require `^0.1` and read the [changelog](CHANGELOG.md) before moving up. When a release adds a migration or a config key, its changelog entry says how to add it. Don't publish `verifactu-migrations` again: publishing renames the migration to the time it runs, so it would add a second copy of the batches table.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
