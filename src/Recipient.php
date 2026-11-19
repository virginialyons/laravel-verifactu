<?php

declare(strict_types=1);

namespace Vlec\Verifactu;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, string>
 */
class Recipient implements Arrayable
{
    /**
     * @param  array<string, string>  $attributes
     */
    protected function __construct(protected array $attributes) {}

    /**
     * A customer with a Spanish tax ID (NIF), known to AEAT under this name.
     */
    public static function spanish(string $taxId, string $name): self
    {
        return new self(['tax_id' => $taxId, 'name' => $name]);
    }

    /**
     * A customer identified by a foreign document.
     */
    public static function foreign(string $name, string $countryCode, ForeignIdType $idType, string $id): self
    {
        return new self([
            'name' => $name,
            'country_code' => $countryCode,
            'id_type' => $idType->value,
            'id' => $id,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }
}
