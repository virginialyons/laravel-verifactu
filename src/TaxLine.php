<?php

declare(strict_types=1);

namespace Vlec\Verifactu;

use BcMath\Number;
use Illuminate\Contracts\Support\Arrayable;
use RoundingMode;

/**
 * @implements Arrayable<string, string>
 */
class TaxLine implements Arrayable
{
    protected function __construct(
        public readonly int $baseCents,
        public readonly ?Number $rate = null,
        public readonly ?ExemptionReason $exemptionReason = null,
    ) {}

    /**
     * A line subject to VAT at the given rate in percent, such as 21.
     *
     * @param  int|numeric-string  $rate
     */
    public static function vat(int $baseCents, int|string $rate): self
    {
        return new self($baseCents, rate: new Number($rate));
    }

    /**
     * A line exempt from VAT, by default for config('verifactu.exemption_reason').
     */
    public static function exempt(int $baseCents, ?ExemptionReason $reason = null): self
    {
        $reason ??= ExemptionReason::from(config()->string('verifactu.exemption_reason'));

        return new self($baseCents, exemptionReason: $reason);
    }

    /**
     * The tax, base × rate / 100, rounded half up to the cent.
     */
    public function taxCents(): int
    {
        if ($this->rate === null) {
            return 0;
        }

        return (int) (new Number($this->baseCents))
            ->mul($this->rate)
            ->div(100)
            ->round(0, RoundingMode::HalfAwayFromZero)
            ->value;
    }

    public function totalCents(): int
    {
        return $this->baseCents + $this->taxCents();
    }

    /**
     * Lines with the same key become one line on the invoice.
     */
    public function groupKey(): string
    {
        if ($this->exemptionReason !== null) {
            return "exempt:{$this->exemptionReason->value}";
        }

        return "vat:{$this->rate?->add(0, 2)->value}";
    }

    public function withBaseCents(int $baseCents): self
    {
        return new self($baseCents, $this->rate, $this->exemptionReason);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $base = self::decimal($this->baseCents);

        if ($this->exemptionReason !== null) {
            return ['tax_base' => $base, 'exemption_reason' => $this->exemptionReason->value];
        }

        return [
            'tax_base' => $base,
            'operation_qualification' => 'S1',
            'tax_rate' => (string) $this->rate?->value,
            'tax_amount' => self::decimal($this->taxCents()),
        ];
    }

    /**
     * Whole cents as the API's decimal string, such as "200.00".
     */
    public static function decimal(int $cents): string
    {
        return (new Number($cents))->div(100, 2)->value;
    }
}
