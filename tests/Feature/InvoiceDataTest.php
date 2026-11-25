<?php

declare(strict_types=1);

use BcMath\Number;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Vlec\Verifactu\ExemptionReason;
use Vlec\Verifactu\ForeignIdType;
use Vlec\Verifactu\InvoiceData;
use Vlec\Verifactu\Recipient;
use Vlec\Verifactu\TaxLine;
use Workbench\App\Models\Invoice;

/**
 * The request body of one of the service's tested examples, with its
 * amounts written as the builder writes them ("200" becomes "200.00").
 *
 * @return array<string, mixed>
 */
function serviceExample(string $slug): array
{
    $example = File::json(__DIR__."/../Fixtures/examples/{$slug}.json");

    return withTwoDecimals($example['request']['body']);
}

/**
 * @param  array<string, mixed>  $body
 * @return array<string, mixed>
 */
function withTwoDecimals(array $body): array
{
    return Arr::undot(Arr::map(Arr::dot($body), function (mixed $value, string $key): mixed {
        if (Collection::make(['tax_base', 'tax_amount', 'total_amount'])->doesntContainStrict(Str::afterLast($key, '.'))) {
            return $value;
        }

        return new Number($value)->add(0, 2)->value;
    }));
}

/**
 * An app's invoice the service has already issued, not saved.
 */
function issuedInvoice(string $number, string $issueDate, bool $simplified = false): Invoice
{
    $factory = Invoice::factory()->issued($number, $issueDate);

    if ($simplified) {
        $factory = $factory->simplified();
    }

    return $factory->make();
}

$business = Recipient::spanish('A15022510', 'Nombre destinatario');

it('builds the body of the service example :dataset', function (Closure $build, string $slug): void {
    expect($build()->toArray())->toEqual(serviceExample($slug));
})->with([
    'standard' => [fn (): InvoiceData => InvoiceData::make('Factura normal')->to($business)->lines([TaxLine::vat(20000, rate: 21)]), 'standard'],
    'vat-exempt' => [fn (): InvoiceData => InvoiceData::make('Factura exenta de IVA')->to($business)->lines([TaxLine::exempt(20000)]), 'vat-exempt'],
    'several-vat-rates' => [fn (): InvoiceData => InvoiceData::make('Factura con varios IVAs')->to($business)->lines([TaxLine::vat(20000, rate: 21), TaxLine::vat(10000, rate: 10)]), 'several-vat-rates'],
    'simplified' => [fn (): InvoiceData => InvoiceData::make('Factura simplificada')->simplified()->lines([TaxLine::vat(20000, rate: 21), TaxLine::vat(10000, rate: 10)]), 'simplified'],
    'simplified-credit-note' => [fn (): InvoiceData => InvoiceData::make('Factura de canje, opción 1: abono de la simplificada')->simplified()->lines([TaxLine::vat(-20000, rate: 21)]), 'simplified-credit-note'],
    'replaces-simplified' => [fn (): InvoiceData => InvoiceData::make('Factura de canje, opción 2: sustitutiva (F3)')->to($business)->lines([TaxLine::vat(20000, rate: 21)])->replacing(issuedInvoice('1', '2026-10-02', simplified: true)), 'replaces-simplified'],
    'credit-note' => [fn (): InvoiceData => InvoiceData::make('Factura de abono')->to($business)->lines([TaxLine::vat(-20000, rate: 21)]), 'credit-note'],
    'export-of-goods' => [fn (): InvoiceData => InvoiceData::make('Factura extracomunitaria - Exportación de bienes')->to(Recipient::foreign('Nombre destinatario', 'US', ForeignIdType::Passport, 'M76543210'))->lines([TaxLine::exempt(20000, ExemptionReason::Exports)]), 'export-of-goods'],
    'intra-eu-goods' => [fn (): InvoiceData => InvoiceData::make('Factura B2B intracomunitaria - Venta de bienes')->to(Recipient::foreign('Nombre destinatario', 'BE', ForeignIdType::EuVatNumber, 'BE0404621642'))->lines([TaxLine::exempt(20000, ExemptionReason::IntraEuSupplies)]), 'intra-eu-goods'],
]);

it('builds the service example of a corrective invoice for the difference, as an R1', function (): void {
    $data = InvoiceData::make('Rectificación por diferencias (cambio de base)')
        ->to(Recipient::spanish('A15022510', 'Nombre destinatario'))
        ->lines([TaxLine::vat(-50000, rate: 21)])
        ->correcting(issuedInvoice('1', '2025-04-07'));

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
    $original = issuedInvoice('42', '2026-11-18');

    expect($original->issued_on)->toBeInstanceOf(DateTimeInterface::class);

    $data = InvoiceData::make('Refund')->to(Recipient::spanish('A15022510', 'Acme'))->lines([TaxLine::vat(-1000, rate: 21)])->correcting($original);

    expect($data->toArray()['correction']['corrected_invoices'])->toBe([['number' => '42', 'issue_date' => '2026-11-18']]);
});

it('leaves the number and date out of a reference to an invoice not issued yet, for the service to refuse', function (): void {
    $data = InvoiceData::make('Refund')->to(Recipient::spanish('A15022510', 'Acme'))->lines([TaxLine::vat(-1000, rate: 21)])->correcting(Invoice::factory()->make());

    expect($data->toArray()['correction']['corrected_invoices'])->toBe([[]]);
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

it('keeps lines exempt for different reasons apart', function (): void {
    $data = InvoiceData::make('Exports')->lines([TaxLine::exempt(1000, ExemptionReason::Exports), TaxLine::exempt(2000, ExemptionReason::IntraEuSupplies)]);

    expect($data->toArray()['lines'])->toBe([
        ['tax_base' => '10.00', 'regime_key' => '02', 'exemption_reason' => 'E2'],
        ['tax_base' => '20.00', 'exemption_reason' => 'E5'],
    ]);
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

it('sends an invoice without lines as it is, for the service to judge', function (): void {
    expect(InvoiceData::make('Empty order')->to(Recipient::spanish('A15022510', 'Acme'))->toArray())
        ->lines->toBe([])
        ->total_amount->toBe('0.00');
});

it('takes 12 tax lines', function (): void {
    $lines = Collection::range(1, 12)->map(fn (int $rate): TaxLine => TaxLine::vat(100, rate: $rate));

    expect(InvoiceData::make('Twelve rates')->to(Recipient::spanish('A15022510', 'Acme'))->lines($lines)->toArray()['lines'])->toHaveCount(12);
});

it('sends more than 12 tax lines as they are, for the service to judge', function (): void {
    $lines = Collection::range(1, 13)->map(fn (int $rate): TaxLine => TaxLine::vat(100, rate: $rate));

    expect(InvoiceData::make('Too many rates')->lines($lines)->toArray()['lines'])->toHaveCount(13);
});

it('sends the recipient the app sets on a :dataset, for the service to judge', function (Closure $data, string $type): void {
    expect($data()->toArray())
        ->invoice_type->toBe($type)
        ->recipient->toBe(['tax_id' => 'A15022510', 'name' => 'Acme']);
})->with([
    'simplified invoice' => [fn (): InvoiceData => InvoiceData::make('Coffee')->to(Recipient::spanish('A15022510', 'Acme'))->simplified()->lines([TaxLine::vat(100, rate: 10)]), 'F2'],
    'corrective invoice of a simplified one' => [fn (): InvoiceData => InvoiceData::make('Refund')->to(Recipient::spanish('A15022510', 'Acme'))->lines([TaxLine::vat(-1000, rate: 21)])->correcting(issuedInvoice('7', '2026-11-02', simplified: true)), 'R5'],
]);

it('sends a rate as given and groups only the same rate', function (): void {
    $data = InvoiceData::make('Two rates')->lines([TaxLine::vat(1000, rate: '21.001'), TaxLine::vat(1000, rate: 21)]);

    expect($data->toArray()['lines'])->sequence(
        fn ($line) => $line->tax_rate->toBe('21.001'),
        fn ($line) => $line->tax_rate->toBe('21'),
    );
});

it('sends an exemption reason as given: :dataset', function (?ExemptionReason $reason, string $default): void {
    config(['verifactu.exemption_reason' => $default]);

    expect(TaxLine::exempt(1000, $reason)->toArray())->toBe(['tax_base' => '10.00', 'exemption_reason' => 'E3']);
})->with([
    'given' => [ExemptionReason::ExportLikeOperations, 'E1'],
    'from the config' => [null, 'E3'],
]);

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
