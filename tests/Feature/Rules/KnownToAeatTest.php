<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Sleep;
use Vlec\Verifactu\Rules\KnownToAeat;

function validateCustomer(array $customer): Illuminate\Validation\Validator
{
    return Validator::make($customer, [
        'tax_id' => [new KnownToAeat(nameField: 'legal_name')],
    ]);
}

function aeatAnswers(string $result, ?string $officialName = null): void
{
    Http::fake(['verifactu.test/api/v1/tax-id-checks' => Http::response(['data' => [
        'tax_id' => 'A15022510',
        'result' => $result,
        'official_name' => $officialName,
    ]])]);
}

it('asks AEAT about the tax ID with the name in the other field', function (): void {
    aeatAnswers('identified');

    expect(validateCustomer(['tax_id' => 'A15022510', 'legal_name' => 'Acme S.L.'])->passes())->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->data() === ['tax_id' => 'A15022510', 'name' => 'Acme S.L.']);
});

it('refuses a tax ID that is :dataset', function (string $result): void {
    aeatAnswers($result);

    $validator = validateCustomer(['tax_id' => 'A15022510', 'legal_name' => 'Acme S.L.']);

    expect($validator->errors()->toArray())->toBe([
        'tax_id' => ['AEAT does not accept this tax ID with this name.'],
    ]);
})->with(['not_identified', 'deregistered', 'revoked']);

it('accepts a tax ID that is :dataset', function (string $result): void {
    aeatAnswers($result);

    expect(validateCustomer(['tax_id' => 'A15022510', 'legal_name' => 'Acme S.L.'])->passes())->toBeTrue();
})->with(['identified', 'not_processed', 'not_checked']);

it('gives the official name on the name field when AEAT holds a similar one', function (): void {
    aeatAnswers('similar', officialName: 'ACME SOCIEDAD LIMITADA');

    $validator = validateCustomer(['tax_id' => 'A15022510', 'legal_name' => 'Acme S.L.']);

    expect($validator->errors()->toArray())->toBe([
        'legal_name' => ['AEAT holds this tax ID as: ACME SOCIEDAD LIMITADA'],
    ]);
});

it('passes at once when AEAT can\'t answer: :dataset', function (Closure $failure): void {
    Sleep::fake();
    Http::fake(['verifactu.test/api/v1/tax-id-checks' => $failure(Http::sequence())]);

    expect(validateCustomer(['tax_id' => 'A15022510', 'legal_name' => 'Acme S.L.'])->passes())->toBeTrue();
    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
})->with([
    '503' => [fn (ResponseSequence $sequence) => $sequence->push(['message' => 'AEAT did not answer.', 'code' => 'aeat_unavailable'], 503, ['Retry-After' => '30'])],
    '429' => [fn (ResponseSequence $sequence) => $sequence->push(['message' => 'Too many.', 'code' => 'too_many_requests'], 429, ['Retry-After' => '30'])],
    'connection error' => [fn (ResponseSequence $sequence) => $sequence->pushFailedConnection()],
    'an unknown result' => [fn (ResponseSequence $sequence) => $sequence->push(['data' => ['tax_id' => 'A15022510', 'result' => 'something_new', 'official_name' => null]])],
]);

it('asks nothing without a tax ID and a name: :dataset', function (array $customer): void {
    Http::fake();

    expect(validateCustomer($customer)->passes())->toBeTrue();
    Http::assertNothingSent();
})->with([
    'no name' => [['tax_id' => 'A15022510']],
    'blank name' => [['tax_id' => 'A15022510', 'legal_name' => ' ']],
    'tax ID not a string' => [['tax_id' => ['A15022510'], 'legal_name' => 'Acme S.L.']],
]);

it('lets the app translate its messages', function (string $result, string $field, string $translated): void {
    aeatAnswers($result, officialName: 'ACME SOCIEDAD LIMITADA');
    Lang::addJsonPath(__DIR__.'/../../Fixtures/lang');
    app()->setLocale('es');

    expect(validateCustomer(['tax_id' => 'A15022510', 'legal_name' => 'Acme S.L.'])->errors()->first($field))
        ->toBe($translated);
})->with([
    'refused' => ['not_identified', 'tax_id', 'AEAT no acepta este NIF con este nombre.'],
    'similar' => ['similar', 'legal_name', 'AEAT tiene este NIF como: ACME SOCIEDAD LIMITADA'],
]);
