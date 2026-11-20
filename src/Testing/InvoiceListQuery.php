<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Testing;

use BcMath\Number;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The query of GET /invoices: its filters, sorts, includes and page, as
 * the service reads them. Its constants are left out of mutation testing,
 * which finds no test covering a constant's line; the list tests cover
 * their values.
 *
 * @phpstan-type Row array{invoice: array<string, mixed>, body: array<mixed>, total: Number, position: int}
 */
class InvoiceListQuery
{
    // @pest-mutate-ignore
    public const array Filters = [
        'number', 'invoice_number', 'external_reference', 'status', 'invoice_type',
        'recipient_tax_id', 'issue_date_from', 'issue_date_to', 'search',
    ];

    // @pest-mutate-ignore
    public const array Sorts = ['issue_date', 'invoice_number', 'total_amount', 'created_at'];

    // @pest-mutate-ignore
    public const array Includes = ['tax_lines', 'references', 'billing_records'];

    // @pest-mutate-ignore
    protected const int DefaultPageSize = 25;

    // @pest-mutate-ignore
    protected const int MaxPageSize = 100;

    /**
     * @param  array<mixed>  $query
     */
    public function __construct(protected array $query) {}

    /**
     * The service's 400 for a filter, sort or include the list doesn't allow.
     *
     * @return array{code: string, message: string}|null
     */
    public function refusal(): ?array
    {
        if (Collection::make(Arr::wrap($this->query['filter'] ?? []))->keys()->diff(self::Filters)->isNotEmpty()) {
            return ['code' => 'invalid_filter', 'message' => 'The list has no such filter. Use one of the filters the endpoint lists.'];
        }

        if (Collection::make($this->sorts())->map(fn (string $sort): string => Str::ltrim($sort, '-'))->diff(self::Sorts)->isNotEmpty()) {
            return ['code' => 'invalid_sort', 'message' => 'The list cannot be sorted by this field. Use one of the sorts the endpoint lists.'];
        }

        if (Collection::make($this->includes())->diff(self::Includes)->isNotEmpty()) {
            return ['code' => 'invalid_include', 'message' => 'The list cannot include this relation. Use one of the includes the endpoint lists.'];
        }

        return null;
    }

    /**
     * The rows the filters keep, in the sort's order, keyed as given.
     *
     * @param  array<string, Row>  $rows
     * @return Collection<string, Row>
     */
    public function apply(array $rows): Collection
    {
        return Collection::make($rows)->filter($this->matches(...))->sort($this->compare(...));
    }

    /**
     * @return array<int, string>
     */
    public function includes(): array
    {
        return $this->names('include');
    }

    public function pageSize(): int
    {
        $size = Arr::get($this->query, 'page.size');

        if (is_numeric($size) && (int) $size >= 1) {
            return min((int) $size, self::MaxPageSize);
        }

        return self::DefaultPageSize;
    }

    /**
     * @param  Row  $row
     */
    protected function matches(array $row): bool
    {
        return Collection::make(Arr::wrap($this->query['filter'] ?? []))
            ->filter(fn (mixed $value): bool => is_string($value))
            ->every(fn (string $value, string $filter): bool => $this->matchesFilter($row, $filter, $value));
    }

    /**
     * @param  Row  $row
     */
    protected function matchesFilter(array $row, string $filter, string $value): bool
    {
        $invoice = $row['invoice'];

        return match ($filter) {
            'status' => Str::of($value)->explode(',')->containsStrict(Arr::string($invoice, 'status')),
            'invoice_type' => Str::of($value)->explode(',')->containsStrict(Arr::string($row['body'], 'invoice_type', 'F1')),
            'recipient_tax_id' => Arr::get($row['body'], 'recipient.tax_id') === $value,
            'issue_date_from' => $invoice['issue_date'] >= $value,
            'issue_date_to' => $invoice['issue_date'] <= $value,
            'search' => Collection::make([
                $invoice['number'],
                $invoice['invoice_number'],
                $invoice['external_reference'],
                Arr::get($row['body'], 'recipient.name'),
                Arr::get($row['body'], 'recipient.tax_id'),
            ])->contains(fn (mixed $field): bool => is_string($field) && Str::contains($field, $value, ignoreCase: true)),
            default => $invoice[$filter] === $value,
        };
    }

    /**
     * @param  Row  $first
     * @param  Row  $second
     */
    protected function compare(array $first, array $second): int
    {
        foreach ($this->sorts() as $sort) {
            $field = Str::ltrim($sort, '-');
            $result = $this->sortValue($first, $field) <=> $this->sortValue($second, $field);

            if ($result === 0) {
                continue;
            }

            if (Str::startsWith($sort, '-')) {
                return -$result;
            }

            return $result;
        }

        return 0;
    }

    /**
     * @param  Row  $row
     */
    protected function sortValue(array $row, string $field): mixed
    {
        return match ($field) {
            'total_amount' => $row['total'],
            'created_at' => $row['position'],
            default => $row['invoice'][$field],
        };
    }

    /**
     * @return array<int, string>
     */
    protected function sorts(): array
    {
        $sorts = $this->names('sort');

        if ($sorts === []) {
            return ['-issue_date'];
        }

        return $sorts;
    }

    /**
     * The comma-separated names of a query parameter, such as the sorts. A
     * parameter sent as a list names nothing the list allows.
     *
     * @return array<int, string>
     */
    protected function names(string $parameter): array
    {
        $value = $this->query[$parameter] ?? '';

        if (! is_string($value)) {
            return ["{$parameter}[]"];
        }

        return Str::of($value)->explode(',')->reject(fn (string $name): bool => blank($name))->values()->all();
    }
}
