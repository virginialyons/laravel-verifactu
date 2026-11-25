<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Vlec\Verifactu\Client;
use Vlec\Verifactu\Contracts\VerifactuInvoice;
use Vlec\Verifactu\Events\InvoiceBatchCompleted;
use Vlec\Verifactu\Events\InvoiceIssued;
use Vlec\Verifactu\Events\InvoiceNotIssued;
use Vlec\Verifactu\Exceptions\VerifactuException;
use Vlec\Verifactu\InvoiceBatchStatus;
use Vlec\Verifactu\Jobs\Concerns\RetriesThroughTheQueue;
use Vlec\Verifactu\Models\InvoiceBatch;

/**
 * Applies a completed batch to the app's invoices: stores each issued
 * invoice and fires InvoiceIssued, fires InvoiceNotIssued for each failed
 * one, then InvoiceBatchCompleted. A batch still running is left for later.
 *
 * @phpstan-type Item array{external_reference: ?string, result: ?string, invoice: ?array<string, mixed>, error: ?array{code: string, message: string}}
 */
class ApplyInvoiceBatch implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
    use RetriesThroughTheQueue;

    /**
     * The lock outlasts the 24 hours of retries, so a second job never
     * applies the batch alongside one waiting out an outage.
     */
    public int $uniqueFor = 90000;

    public function __construct(public InvoiceBatch $batch) {}

    public function uniqueId(): string
    {
        return $this->batch->uuid;
    }

    public function handle(Client $client): void
    {
        if ($this->batch->isApplied()) {
            return;
        }

        $client = $client->withoutRetries();

        try {
            /** @var array{status: string, total: int, succeeded: int, failed: int} $batch */
            $batch = $client->getInvoiceBatch($this->batch->uuid);

            if (InvoiceBatchStatus::from($batch['status'])->isNotComplete()) {
                return;
            }

            // Every page is read before any is applied, so a page the service fails to answer fires no event twice.
            $items = $this->items($client);
        } catch (VerifactuException $exception) {
            $this->releaseOrFail($exception);

            return;
        }

        $this->apply($items);

        $this->batch->update(['status' => $batch['status']]);

        event(new InvoiceBatchCompleted($this->batch, $batch['total'], $batch['succeeded'], $batch['failed']));
    }

    /**
     * @return Collection<int, Item>
     */
    protected function items(Client $client): Collection
    {
        $items = Collection::make();
        $page = 1;

        do {
            /** @var array{data: list<Item>, meta: array{last_page: int}} $answer */
            $answer = $client->listInvoiceBatchItems($this->batch->uuid, $page);

            $items->push(...$answer['data']);

            $page++;
        } while ($page <= $answer['meta']['last_page']);

        return $items;
    }

    /**
     * @param  Collection<int, Item>  $items
     */
    protected function apply(Collection $items): void
    {
        $invoices = $this->invoices($items->map(fn (array $item): ?string => $item['external_reference']));

        foreach ($items as $item) {
            $invoice = $invoices->get($item['external_reference']);

            // Not the app's invoice, or already stored, for instance sent again on its own.
            if ($invoice === null || $invoice->isOnVerifactu()) {
                continue;
            }

            if ($item['result'] === 'issued' && $item['invoice'] !== null) {
                $invoice->storeVerifactuInvoice($item['invoice']);

                event(new InvoiceIssued($invoice));

                continue;
            }

            if ($item['result'] !== 'failed' || $item['error'] === null) {
                continue;
            }

            // Issued by another batch or send not yet applied: IssueInvoice reads it back and stores it.
            if ($item['error']['code'] === 'external_reference_already_used') {
                $invoice->sendToVerifactu();

                continue;
            }

            event(new InvoiceNotIssued($invoice, $item['error']['code'], $item['error']['message']));
        }
    }

    /**
     * The app's invoices with these references, keyed by reference ("invoice:{key}").
     *
     * @param  Collection<int, ?string>  $references
     * @return Collection<string, Model&VerifactuInvoice>
     */
    protected function invoices(Collection $references): Collection
    {
        /** @var class-string<Model&VerifactuInvoice> $model */
        $model = config()->string('verifactu.model');

        $keys = $references
            ->filter(fn (?string $reference): bool => Str::startsWith((string) $reference, 'invoice:'))
            ->map(fn (?string $reference): string => Str::after((string) $reference, 'invoice:'));

        return $model::query()
            ->whereKey($keys->all())
            ->get()
            ->keyBy(fn (Model&VerifactuInvoice $invoice): string => $invoice->verifactuReference());
    }
}
