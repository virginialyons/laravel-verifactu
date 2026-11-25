<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Workbench\App\Models\Invoice;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            'customer_tax_id' => 'A15022510',
            'customer_name' => 'Acme S.L.',
            'exempt_cents' => 30000,
            'taxable_cents' => 2000,
        ];
    }

    /**
     * An invoice the service has issued and AEAT is still to answer.
     */
    public function issued(string $number = '42', string $issueDate = '2026-11-18'): static
    {
        return $this->state([
            'verifactu_uuid' => fake()->uuid(),
            'aeat_status' => 'pending',
            'number' => sprintf('FACT-%s-%05d', Str::take($issueDate, 4), $number),
            'short_number' => $number,
            'issued_on' => $issueDate,
        ]);
    }

    public function simplified(): static
    {
        return $this->state(['is_simplified' => true, 'customer_tax_id' => null, 'customer_name' => null]);
    }
}
