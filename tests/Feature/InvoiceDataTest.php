<?php

declare(strict_types=1);

use BcMath\Number;
use Illuminate\Database\Eloquent\Model;
use Vlec\Verifactu\Contracts\VerifactuInvoice;
use Vlec\Verifactu\ExemptionReason;
use Vlec\Verifactu\ForeignIdType;
use Vlec\Verifactu\InvoiceData;
use Vlec\Verifactu\Recipient;
use Vlec\Verifactu\TaxLine;

/**
 * The request body of one of the service's tested examples, with its
 * amounts written as the builder writes them ("200" becomes "200.00").
 *
 * @return array<string, mixed>
 */
function serviceExample(string $slug): array
{
    $example = json_decode((string) file_get_contents(__DIR__."/../Fixtures/examples/{$slug}.json"), true);

    return withTwoDecimals($example['request']['body']);
}

/**
 * @param  array<string, mixed>  $body
 * @return array<string, mixed>
 */
function withTwoDecimals(array $body): array
{
    array_walk_recursive($body, function (mixed &$value, string|int $key): void {
        if (in_array($key, ['tax_base', 'tax_amount', 'total_amount'], true)) {
            $value = (new Number($value))->add(0, 2)->value;
        }
    });

    return $body;
}

/**
 * An app's invoice the service has already issued.
 */
function issuedInvoice(string $number, string $issueDate, bool $simplified = false): Model&VerifactuInvoice
{
    $invoice = new class extends Model implements VerifactuInvoice
    {
        public bool $simplified = false;

        public function toVerifactu(): InvoiceData
        {
            $data = InvoiceData::make('Original sale');

            if ($this->simplified) {
                return $data->simplified();
            }

            return $data;
        }
    };

    $invoice->simplified = $simplified;

    return $invoice->forceFill(['verifactu_number' => $number, 'verifactu_issue_date' => $issueDate]);
}

$business = Recipient::spanish('A15022510', 'Nombre destinatario');

it('builds the body of the service example :dataset', function (Closure $build, string $slug): void {
    expect($build()->toArray())->toEqual(serviceExample($slug));
})->with([
    'standard' => [fn () => InvoiceData::make('Factura normal')->to($business)->lines([TaxLine::vat(20000, rate: 21)]), 'standard'],
    'vat-exempt' => [fn () => InvoiceData::make('Factura exenta de IVA')->to($business)->lines([TaxLine::exempt(20000)]), 'vat-exempt'],
    'several-vat-rates' => [fn () => InvoiceData::make('Factura con varios IVAs')->to($business)->lines([TaxLine::vat(20000, rate: 21), TaxLine::vat(10000, rate: 10)]), 'several-vat-rates'],
    'simplified' => [fn () => InvoiceData::make('Factura simplificada')->simplified()->lines([TaxLine::vat(20000, rate: 21), TaxLine::vat(10000, rate: 10)]), 'simplified'],
    'simplified-credit-note' => [fn () => InvoiceData::make('Factura de canje, opción 1: abono de la simplificada')->simplified()->lines([TaxLine::vat(-20000, rate: 21)]), 'simplified-credit-note'],
    'replaces-simplified' => [fn () => InvoiceData::make('Factura de canje, opción 2: sustitutiva (F3)')->to($business)->lines([TaxLine::vat(20000, rate: 21)])->replacing(issuedInvoice('S-1', '2026-10-02', simplified: true)), 'replaces-simplified'],
]);

it('builds the service example of a corrective invoice for the difference, as an R1', function (): void {
    $data = InvoiceData::make('Rectificación por diferencias (cambio de base)')
        ->to(Recipient::spanish('A15022510', 'Nombre destinatario'))
        ->lines([TaxLine::vat(-50000, rate: 21)])
        ->correcting(issuedInvoice('A-1', '2025-04-07'));

    // The example is an R4; the builder issues R1, the type for refunds and
    // discounts, and nothing else in the body differs.
    expect($data->toArray())->toEqual([...serviceExample('corrective-difference'), 'invoice_type' => 'R1']);
});

it('corrects a simplified invoice with an R5', function (): void {
    $data = InvoiceData::make('Refund')
        ->lines([TaxLine::vat(-1000, rate: 21)])
        ->correcting(issuedInvoice('7', '2026-11-02', simplified: true));

    expect($data->toArray())
        ->invoice_type->toBe('R5')
        ->correction->toBe(['method' => 'difference', 'corrected_invoices' => [['number' => '7', 'issue_date' => '2026-11-02']]]);
});

it('reads the referenced invoice from the configured columns, with a Carbon date', function (): void {
    config(['verifactu.columns.number' => 'short_number', 'verifactu.columns.issue_date' => 'issued_on']);
    $original = issuedInvoice('', '')->forceFill(['short_number' => '42', 'issued_on' => now()->setDate(2026, 11, 18)]);

    $data = InvoiceData::make('Refund')->to(Recipient::spanish('A15022510', 'Acme'))->lines([TaxLine::vat(-1000, rate: 21)])->correcting($original);

    expect($data->toArray()['correction']['corrected_invoices'])->toBe([['number' => '42', 'issue_date' => '2026-11-18']]);
});

it('refuses to correct an invoice the service has not issued', function (): void {
    $original = issuedInvoice('42', '2026-11-18')->forceFill(['verifactu_number' => null]);

    expect(fn () => InvoiceData::make('Refund')->correcting($original))
        ->toThrow(InvalidArgumentException::class, 'An invoice must be issued on Verifactu before it is corrected or replaced.');
});

it('groups lines with the same rate or exemption reason and adds up their bases', function (): void {
    $data = InvoiceData::make('Order 1')->to(Recipient::spanish('A15022510', 'Acme'))->lines([
        TaxLine::vat(10000, rate: 21),
        TaxLine::exempt(30000),
        TaxLine::vat(5000, rate: '21.00'),
    ]);

    expect($data->toArray())
        ->lines->toBe([
            ['tax_base' => '150.00', 'operation_qualification' => 'S1', 'tax_rate' => '21', 'tax_amount' => '31.50'],
            ['tax_base' => '300.00', 'exemption_reason' => 'E1'],
        ])
        ->total_amount->toBe('481.50')
        ->and($data->taxLines())->toHaveCount(2);
});

it('works the tax out once per grouped line', function (): void {
    $data = InvoiceData::make('Two pens')->lines([TaxLine::vat(25, rate: 10), TaxLine::vat(25, rate: 10)]);

    // Each 0.25 alone would round 0.025 up to 0.03; together 0.50 makes 0.05.
    expect($data->taxLines()[0]->taxCents())->toBe(5)
        ->and($data->totalCents())->toBe(55);
});

it('rounds half a cent of tax away from zero', function (int $baseCents, int $taxCents): void {
    expect(TaxLine::vat($baseCents, rate: 21)->taxCents())->toBe($taxCents);
})->with([
    '0.50 at 21% is 0.105' => [50, 11],
    '-0.50 at 21% is -0.105' => [-50, -11],
    '0.10 at 21% is 0.021' => [10, 2],
]);

it('writes negative bases and their tax as negative amounts', function (): void {
    $data = InvoiceData::make('Refund')->lines([TaxLine::vat(-1050, rate: 21), TaxLine::exempt(-2000)]);

    expect($data->toArray())
        ->lines->toBe([
            ['tax_base' => '-10.50', 'operation_qualification' => 'S1', 'tax_rate' => '21', 'tax_amount' => '-2.21'],
            ['tax_base' => '-20.00', 'exemption_reason' => 'E1'],
        ])
        ->total_amount->toBe('-32.71');
});

it('takes the default exemption reason from the config', function (): void {
    config(['verifactu.exemption_reason' => 'E2']);

    expect(TaxLine::exempt(100)->exemptionReason)->toBe(ExemptionReason::Exports)
        ->and(TaxLine::exempt(100, ExemptionReason::IntraEuSupplies)->toArray())
        ->toBe(['tax_base' => '1.00', 'exemption_reason' => 'E5']);
});

it('refuses an invoice without lines', function (): void {
    expect(fn () => InvoiceData::make('Empty order')->to(Recipient::spanish('A15022510', 'Acme'))->toArray())
        ->toThrow(InvalidArgumentException::class, 'An invoice has between 1 and 12 tax lines; this one has 0.');
});

it('refuses more than 12 tax lines', function (): void {
    $lines = collect(range(1, 13))->map(fn (int $rate): TaxLine => TaxLine::vat(100, rate: $rate));

    expect(fn () => InvoiceData::make('Too many rates')->lines($lines)->toArray())
        ->toThrow(InvalidArgumentException::class, 'An invoice has between 1 and 12 tax lines; this one has 13.');
});

it('refuses a recipient on a simplified invoice', function (): void {
    $data = InvoiceData::make('Coffee')->to(Recipient::spanish('A15022510', 'Acme'))->simplified()->lines([TaxLine::vat(100, rate: 10)]);

    expect(fn () => $data->toArray())->toThrow(InvalidArgumentException::class, 'A simplified invoice (F2) has no recipient.');
});

it('describes a foreign customer by their document', function (): void {
    expect(Recipient::foreign('Jean Dupont', 'FR', ForeignIdType::EuVatNumber, 'FR40303265045')->toArray())
        ->toBe(['name' => 'Jean Dupont', 'country_code' => 'FR', 'id_type' => '02', 'id' => 'FR40303265045']);
});

it('leaves the builder it starts from unchanged', function (): void {
    $sale = InvoiceData::make('Order 1')->lines([TaxLine::vat(1000, rate: 21)]);

    $sale->simplified();
    $sale->lines([]);

    expect($sale->isSimplified())->toBeFalse()
        ->and($sale->totalCents())->toBe(1210);
});
