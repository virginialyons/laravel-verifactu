<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Workbench\App\Models\Invoice;

uses(RefreshDatabase::class);

function fakeInvoiceList(): void
{
    Http::fake(['verifactu.test/api/v1/invoices*' => Http::response(['data' => [], 'links' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0]])]);
}

it('shows the environment, the connection and the invoices by status', function (): void {
    Invoice::factory()->count(2)->create();
    Invoice::factory()->issued()->create();
    Invoice::factory()->issued()->create(['aeat_status' => 'accepted']);
    fakeInvoiceList();

    expect(Artisan::call('verifactu:status'))->toBe(0);

    expect(Artisan::output())
        ->toMatch('/Environment\W+Test/')
        ->toMatch('/Connection\W+OK/')
        ->toMatch('/Not sent\W+2/')
        ->toMatch('/Pending\W+1/')
        ->toMatch('/Accepted\W+1/')
        ->toMatch('/Accepted With Errors\W+0/')
        ->toMatch('/Rejected\W+0/')
        ->toMatch('/Cancelled\W+0/');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://verifactu.test/api/v1/invoices?page%5Bsize%5D=1');
});

it('fails with the service\'s message when the token is refused', function (): void {
    Http::fake(['verifactu.test/api/v1/invoices*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    expect(Artisan::call('verifactu:status'))->toBe(1);

    expect(Artisan::output())->toMatch('/Connection\W+Unauthenticated\./')->toContain('Not sent');
});

it('fails without a URL', function (): void {
    config(['verifactu.url' => null]);

    expect(Artisan::call('verifactu:status'))->toBe(1);

    expect(Artisan::output())->toContain('Set VERIFACTU_URL');
});

it('never shows the token', function (): void {
    fakeInvoiceList();

    expect(Artisan::call('verifactu:status'))->toBe(0);

    expect(Artisan::output())->not->toContain('token-for-tests');
});
