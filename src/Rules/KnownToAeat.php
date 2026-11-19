<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use Vlec\Verifactu\Exceptions\VerifactuException;
use Vlec\Verifactu\Facades\Verifactu;
use Vlec\Verifactu\TaxIdCheckResult;

/**
 * Asks AEAT whether it knows the Spanish tax ID under the name in another
 * field. Passes when AEAT can't answer: the invoice is checked again when
 * it is issued.
 */
class KnownToAeat implements DataAwareRule, ValidationRule
{
    /**
     * @var array<string, mixed>
     */
    protected array $data = [];

    public function __construct(protected string $nameField) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $name = Arr::get($this->data, $this->nameField);

        if (! is_string($value) || ! is_string($name) || blank($name)) {
            return;
        }

        try {
            $check = Verifactu::withoutRetries()->checkTaxId($value, $name);
        } catch (VerifactuException) {
            return;
        }

        if ($check->result->isRefused()) {
            $fail('AEAT does not accept this tax ID with this name.')->translate();

            return;
        }

        if ($check->result === TaxIdCheckResult::Similar) {
            $fail($this->nameField, 'AEAT holds this tax ID as: :name')->translate(['name' => $check->officialName]);
        }
    }
}
