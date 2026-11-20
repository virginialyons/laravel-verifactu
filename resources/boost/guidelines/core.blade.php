## Laravel Verifactu

`vlec/laravel-verifactu` issues Spanish VERI*FACTU invoices through the Verifactu service. The app's invoice model describes the sale; the package sends it, stores the service's answer in the model's columns and keeps the status current through signed webhooks.

- The invoice model implements `Vlec\Verifactu\Contracts\VerifactuInvoice`, uses `Vlec\Verifactu\IssuesVerifactuInvoices` and writes one method, `toVerifactu(): InvoiceData`.
- Money is whole cents (`int`), never a float: `TaxLine::vat($baseCents, rate: 21)`, `TaxLine::exempt($baseCents)`.
- Issue with `$invoice->sendToVerifactu()` (queued, after commit) or `Verifactu::sendBatch($invoices)`. Fix with `$invoice->correctOnVerifactu($description)`, cancel with `$invoice->cancelOnVerifactu()`: both call the service at once, so call them after the transaction commits.
- React to AEAT's answer with the events in `Vlec\Verifactu\Events` (`InvoiceIssued`, `InvoiceNotIssued`, `InvoiceAccepted`, `InvoiceRejected`…), never by polling.
- Branch on `VerifactuException::$errorCode`, never on the message.
- In tests, call `Verifactu::fake()` and play AEAT's answer with `Verifactu::decide()`; never send to the real service.
- Never log, print or commit `VERIFACTU_TOKEN` or `VERIFACTU_WEBHOOK_SECRET`.

Activate the `laravel-verifactu-development` skill for the model, tax lines, batches, webhooks, tax-ID checks and testing.

@verbatim
<code-snippet name="An invoice model" lang="php">
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
</code-snippet>
@endverbatim
