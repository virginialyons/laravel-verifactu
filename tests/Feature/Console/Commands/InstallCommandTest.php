<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Vlec\Verifactu\Tests\Fixtures\App\Models\LegacyInvoice;
use Vlec\Verifactu\VerifactuServiceProvider;

use function Illuminate\Filesystem\join_paths;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Each test installs into its own folder, as parallel runs share Testbench's app.
    $this->base = join_paths(sys_get_temp_dir(), 'verifactu-install-'.Str::random(8));
    File::ensureDirectoryExists(join_paths($this->base, 'config'));
    File::ensureDirectoryExists(join_paths($this->base, 'database', 'migrations'));
    File::put(join_paths($this->base, '.env'), "APP_NAME=Laravel\nVERIFACTU_TOKEN=vf_test_kept\n");
    File::put(join_paths($this->base, '.env.example'), "APP_NAME=Laravel\n");

    $this->app->useConfigPath(join_paths($this->base, 'config'));
    $this->app->useDatabasePath(join_paths($this->base, 'database'));
    $this->app->useEnvironmentPath($this->base);

    // The app's models are the fixtures': app/Models and the app's namespace point there.
    $this->app->useAppPath(join_paths(__DIR__, '..', '..', '..', 'Fixtures', 'App'));
    (fn () => $this->namespace = 'Vlec\\Verifactu\\Tests\\Fixtures\\App\\')->call($this->app);

    // Booted again, the provider publishes into the folder.
    $this->app->register(VerifactuServiceProvider::class, force: true);

    // The package's own column names, not the workbench's.
    config(['verifactu.columns' => (require join_paths(__DIR__, '..', '..', '..', '..', 'config', 'verifactu.php'))['columns']]);
});

afterEach(function (): void {
    File::deleteDirectory($this->base);
});

function createLegacyInvoicesTable(): void
{
    Schema::create('legacy_invoices', function (Blueprint $table): void {
        $table->id();
        $table->string('customer_name')->nullable()->index('legacy_customer_index');
        $table->string('number')->nullable();
        $table->string('hash')->nullable()->index('legacy_hash_index');
        $table->string('aeat_csv')->nullable();
        $table->text('qr_code')->nullable();
        $table->timestamps();
    });
}

/**
 * The wizard on the legacy table: number and qr_code kept, the rest created.
 */
function installOnLegacyInvoices(PendingCommand $command, string $printedNumber = 'number'): PendingCommand
{
    return $command
        ->expectsQuestion('Which model is the invoice?', 'LegacyInvoice')
        ->expectsChoice('Which column holds the invoice\'s id on the service?', 'verifactu_uuid', [
            'verifactu_uuid' => 'Create verifactu_uuid',
            'customer_name' => 'customer_name',
            'number' => 'number',
            'hash' => 'hash',
            'aeat_csv' => 'aeat_csv',
            'qr_code' => 'qr_code',
        ])
        ->expectsQuestion('Which column holds its status at AEAT?', 'verifactu_status')
        ->expectsQuestion('Which column holds the number to print, such as FACT-2026-00042?', $printedNumber)
        ->expectsQuestion('Which column holds the bare number a corrective invoice refers to, such as 42?', 'verifactu_number')
        ->expectsQuestion('Which column holds the issue date?', 'verifactu_issue_date')
        ->expectsQuestion('Which column holds AEAT\'s verification URL?', 'verifactu_url')
        // qr_code is the table's own: nothing to create.
        ->expectsChoice('Which column holds the QR code?', 'qr_code', Arr::except([
            'customer_name' => 'customer_name',
            'number' => 'number',
            'hash' => 'hash',
            'aeat_csv' => 'aeat_csv',
            'qr_code' => 'qr_code',
        ], $printedNumber));
}

/**
 * @return list<string>
 */
function addColumnsMigrations(string $base): array
{
    return File::glob(join_paths($base, 'database', 'migrations', '*_add_verifactu_columns_to_legacy_invoices_table.php'));
}

it('sets up the app on a table with its own number, hash and QR code', function (): void {
    createLegacyInvoicesTable();

    installOnLegacyInvoices($this->artisan('verifactu:install'))
        ->expectsOutputToContain('The config is in config/verifactu.php.')
        ->expectsOutputToContain('.env and .env.example have VERIFACTU_URL, VERIFACTU_TOKEN and VERIFACTU_WEBHOOK_SECRET.')
        ->expectsOutputToContain('config/verifactu.php has the model and its columns.')
        ->expectsOutputToContain('Wrote the migration add_verifactu_columns_to_legacy_invoices_table, which adds verifactu_uuid, verifactu_status, verifactu_number, verifactu_issue_date and verifactu_url.')
        ->expectsOutputToContain('They were not dropped:')
        ->expectsOutputToContain('⇂ hash')
        ->expectsOutputToContain('php artisan make:migration drop_old_aeat_columns_from_legacy_invoices_table')
        ->expectsOutputToContain("Schema::table('legacy_invoices', function (Blueprint \$table) {")
        ->expectsOutputToContain("\$table->dropIndex('legacy_hash_index');")
        ->doesntExpectOutputToContain('legacy_customer_index')
        ->expectsOutputToContain("\$table->dropColumn(['hash', 'aeat_csv']);")
        ->expectsOutputToContain('    });')
        ->expectsOutputToContain('Next:')
        // The next steps are one write, which meets only the first expectation it matches: this one.
        ->expectsOutputToContain('Give an administrator of the service this webhook URL: http://localhost/verifactu/webhook')
        ->expectsOutputToContain('Add to Vlec\Verifactu\Tests\Fixtures\App\Models\LegacyInvoice:')
        ->expectsOutputToContain('class LegacyInvoice extends Model implements VerifactuInvoice')
        ->expectsOutputToContain('Schedule in routes/console.php:')
        ->expectsOutputToContain("Schedule::command('verifactu:refresh-pending')->hourly();")
        ->assertSuccessful();

    $config = require join_paths($this->base, 'config', 'verifactu.php');
    expect($config['model'])->toBe(LegacyInvoice::class)
        ->and($config['columns'])->toBe([
            'uuid' => 'verifactu_uuid',
            'status' => 'verifactu_status',
            'invoice_number' => 'number',
            'number' => 'verifactu_number',
            'issue_date' => 'verifactu_issue_date',
            'verification_url' => 'verifactu_url',
            'qr_code' => 'qr_code',
        ]);

    expect(File::get(join_paths($this->base, '.env')))
        ->toBe("APP_NAME=Laravel\nVERIFACTU_TOKEN=vf_test_kept\nVERIFACTU_URL=\nVERIFACTU_WEBHOOK_SECRET=\n");
    expect(File::get(join_paths($this->base, '.env.example')))
        ->toBe("APP_NAME=Laravel\n\nVERIFACTU_URL=\nVERIFACTU_TOKEN=\nVERIFACTU_WEBHOOK_SECRET=");

    expect(File::glob(join_paths($this->base, 'database', 'migrations', '*_create_verifactu_invoice_batches_table.php')))->toHaveCount(1);

    $migration = addColumnsMigrations($this->base);
    expect($migration)->toHaveCount(1);

    $this->artisan('migrate', ['--path' => $migration[0], '--realpath' => true])->assertSuccessful();
    // The old AEAT columns stay: dropping them is the developer's call.
    expect(Schema::getColumnListing('legacy_invoices'))->toBe([
        'id', 'customer_name', 'number', 'hash', 'aeat_csv', 'qr_code', 'created_at', 'updated_at',
        'verifactu_uuid', 'verifactu_status', 'verifactu_number', 'verifactu_issue_date', 'verifactu_url',
    ]);

    $this->artisan('migrate:rollback', ['--path' => $migration[0], '--realpath' => true])->assertSuccessful();
    expect(Schema::getColumnListing('legacy_invoices'))->toBe(['id', 'customer_name', 'number', 'hash', 'aeat_csv', 'qr_code', 'created_at', 'updated_at']);
});

it('offers a column for one field only, and never the key or a timestamp', function (): void {
    createLegacyInvoicesTable();

    $this->artisan('verifactu:install')
        ->expectsQuestion('Which model is the invoice?', 'LegacyInvoice')
        ->expectsQuestion('Which column holds the invoice\'s id on the service?', 'verifactu_uuid')
        ->expectsQuestion('Which column holds its status at AEAT?', 'verifactu_status')
        ->expectsQuestion('Which column holds the number to print, such as FACT-2026-00042?', 'number')
        ->expectsChoice('Which column holds the bare number a corrective invoice refers to, such as 42?', 'verifactu_number', [
            'verifactu_number' => 'Create verifactu_number',
            'customer_name' => 'customer_name',
            'hash' => 'hash',
            'aeat_csv' => 'aeat_csv',
            'qr_code' => 'qr_code',
        ])
        ->expectsQuestion('Which column holds the issue date?', 'verifactu_issue_date')
        ->expectsQuestion('Which column holds AEAT\'s verification URL?', 'verifactu_url')
        ->expectsQuestion('Which column holds the QR code?', 'qr_code')
        ->assertSuccessful();
});

it('takes the model by its full class name', function (): void {
    $this->artisan('verifactu:install')
        ->expectsQuestion('Which model is the invoice?', LegacyInvoice::class)
        ->expectsOutputToContain('The table legacy_invoices does not exist: migrate it first.')
        ->assertFailed();
});

it('adds nothing twice when run again', function (): void {
    createLegacyInvoicesTable();
    installOnLegacyInvoices($this->artisan('verifactu:install'))->assertSuccessful();
    $env = File::get(join_paths($this->base, '.env'));
    $example = File::get(join_paths($this->base, '.env.example'));
    // Publishing names a migration after the second it runs in.
    $this->travel(1)->seconds();

    installOnLegacyInvoices($this->artisan('verifactu:install'))
        ->expectsOutputToContain('A migration add_verifactu_columns_to_legacy_invoices_table adds these columns already: run php artisan migrate.')
        ->assertSuccessful();

    expect(File::get(join_paths($this->base, '.env')))->toBe($env)
        ->and(File::get(join_paths($this->base, '.env.example')))->toBe($example)
        ->and(addColumnsMigrations($this->base))->toHaveCount(1)
        ->and(File::glob(join_paths($this->base, 'database', 'migrations', '*_create_verifactu_invoice_batches_table.php')))->toHaveCount(1);
});

it('writes another migration when a second run creates a column the first did not', function (): void {
    createLegacyInvoicesTable();
    installOnLegacyInvoices($this->artisan('verifactu:install'))->assertSuccessful();
    $this->travel(1)->seconds();

    installOnLegacyInvoices($this->artisan('verifactu:install'), printedNumber: 'verifactu_invoice_number')
        ->expectsOutputToContain('Wrote the migration add_verifactu_columns_to_legacy_invoices_table, which adds verifactu_uuid, verifactu_status, verifactu_invoice_number, verifactu_number, verifactu_issue_date and verifactu_url.')
        ->assertSuccessful();

    expect(addColumnsMigrations($this->base))->toHaveCount(2)
        ->and((require join_paths($this->base, 'config', 'verifactu.php'))['columns']['invoice_number'])->toBe('verifactu_invoice_number');
});

it('writes no migration when the table has every column', function (): void {
    Schema::create('legacy_invoices', function (Blueprint $table): void {
        $table->id();

        foreach (config()->array('verifactu.columns') as $column) {
            $table->string($column)->nullable();
        }
    });

    $this->artisan('verifactu:install')
        ->expectsQuestion('Which model is the invoice?', 'LegacyInvoice')
        ->expectsQuestion('Which column holds the invoice\'s id on the service?', 'verifactu_uuid')
        ->expectsQuestion('Which column holds its status at AEAT?', 'verifactu_status')
        ->expectsQuestion('Which column holds the number to print, such as FACT-2026-00042?', 'verifactu_invoice_number')
        ->expectsQuestion('Which column holds the bare number a corrective invoice refers to, such as 42?', 'verifactu_number')
        ->expectsQuestion('Which column holds the issue date?', 'verifactu_issue_date')
        ->expectsQuestion('Which column holds AEAT\'s verification URL?', 'verifactu_url')
        ->expectsQuestion('Which column holds the QR code?', 'qr_code')
        ->expectsOutputToContain('The table legacy_invoices has every column.')
        ->assertSuccessful();

    expect(addColumnsMigrations($this->base))->toBe([]);
});

it('refuses a config whose keys are no longer on their own line, before asking anything', function (): void {
    $published = File::get(join_paths(__DIR__, '..', '..', '..', '..', 'config', 'verifactu.php'));
    File::put(join_paths($this->base, 'config', 'verifactu.php'), Str::replace("        'qr_code' => 'qr_code',\n", '', $published));

    $this->artisan('verifactu:install')
        ->expectsOutputToContain('config/verifactu.php must keep each of these keys on its own line, as published: qr_code.')
        ->assertFailed();
});

it('says where to set the keys when the app has no .env', function (): void {
    File::delete([join_paths($this->base, '.env'), join_paths($this->base, '.env.example')]);

    $this->artisan('verifactu:install')
        ->expectsOutputToContain('There is no .env: set VERIFACTU_URL, VERIFACTU_TOKEN and VERIFACTU_WEBHOOK_SECRET where the app keeps its environment.')
        ->expectsQuestion('Which model is the invoice?', 'LegacyInvoice')
        ->assertFailed();

    expect(File::exists(join_paths($this->base, '.env')))->toBeFalse();
});

it('fails for a model whose table does not exist', function (): void {
    $this->artisan('verifactu:install')
        ->expectsQuestion('Which model is the invoice?', 'LegacyInvoice')
        ->expectsOutputToContain('The table legacy_invoices does not exist: migrate it first.')
        ->assertFailed();
});

it('fails for a class that is not a model', function (): void {
    $this->artisan('verifactu:install')
        ->expectsQuestion('Which model is the invoice?', 'InvoiceKind')
        ->expectsOutputToContain('Vlec\Verifactu\Tests\Fixtures\App\Models\InvoiceKind is not an Eloquent model.')
        ->assertFailed();
});

it('lists every old AEAT column, whatever the case of its name', function (): void {
    Schema::create('legacy_invoices', function (Blueprint $table): void {
        $table->id();
        $table->string('customer_name')->nullable();

        foreach (['previousHash', 'sequential_number', 'aeat_response', 'signed_xml', 'csv'] as $column) {
            $table->string($column)->nullable();
        }
    });

    $this->artisan('verifactu:install')
        ->expectsQuestion('Which model is the invoice?', 'LegacyInvoice')
        ->expectsQuestion('Which column holds the invoice\'s id on the service?', 'verifactu_uuid')
        ->expectsQuestion('Which column holds its status at AEAT?', 'verifactu_status')
        ->expectsQuestion('Which column holds the number to print, such as FACT-2026-00042?', 'verifactu_invoice_number')
        ->expectsQuestion('Which column holds the bare number a corrective invoice refers to, such as 42?', 'verifactu_number')
        ->expectsQuestion('Which column holds the issue date?', 'verifactu_issue_date')
        ->expectsQuestion('Which column holds AEAT\'s verification URL?', 'verifactu_url')
        ->expectsQuestion('Which column holds the QR code?', 'qr_code')
        ->expectsOutputToContain("\$table->dropColumn(['previousHash', 'sequential_number', 'aeat_response', 'signed_xml', 'csv']);")
        ->assertSuccessful();
});

it('requires the model', function (): void {
    $this->artisan('verifactu:install')
        ->expectsQuestion('Which model is the invoice?', '')
        ->expectsOutputToContain('Required.')
        ->assertFailed();
});
