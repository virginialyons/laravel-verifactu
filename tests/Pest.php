<?php

declare(strict_types=1);

use Vlec\Verifactu\Tests\TestCase;

pest()->extend(TestCase::class)->in(__DIR__);

/**
 * An invoice as the service answers with it.
 *
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
function serviceInvoice(array $attributes = []): array
{
    return [
        'uuid' => '9d2c4b1e-0f6a-4c1b-9a59-6f1f2b3c4d5e',
        'environment' => 'test',
        'status' => 'pending',
        'series' => 'FACT',
        'number' => '42',
        'invoice_number' => 'FACT-2026-00042',
        'issue_date' => '2026-11-19',
        'total_amount' => '324.20',
        'external_reference' => 'invoice:1',
        'verification_url' => 'https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR?nif=B00000000&numserie=FACT-2026-00042&fecha=19-11-2026&importe=324.20',
        'qr_code' => 'iVBORw0KGgo=',
        'hash' => '3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60',
        'aeat' => ['verification_code' => null, 'error_code' => null, 'error_message' => null, 'registered_at' => null],
        ...$attributes,
    ];
}

/**
 * A batch as the service answers with it.
 *
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
function serviceBatch(array $attributes = []): array
{
    return [
        'uuid' => '5f0c6a2e-8a5b-4c1e-9d7a-2b8e4f6a1c3d',
        'environment' => 'test',
        'status' => 'queued',
        'total' => 2,
        'processed' => 0,
        'succeeded' => 0,
        'failed' => 0,
        'created_at' => '2026-11-19T10:30:00+01:00',
        'completed_at' => null,
        ...$attributes,
    ];
}
