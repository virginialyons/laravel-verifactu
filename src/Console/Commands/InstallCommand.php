<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\Concerns\FindsAvailableModels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

use function Illuminate\Filesystem\join_paths;
use function Laravel\Prompts\select;
use function Laravel\Prompts\suggest;

#[Signature('verifactu:install')]
#[Description('Set up Verifactu: the config, the .env keys, and the invoice model\'s columns')]
class InstallCommand extends Command
{
    use FindsAvailableModels;

    /**
     * Words in the names of the columns an app's own AEAT setup needed,
     * whose data the service keeps now: hash, previous_hash, sequential...
     */
    protected const array OldAeatColumnWords = ['hash', 'sequential', 'aeat', 'xml', 'csv'];

    public function __construct(protected Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->callSilently('vendor:publish', ['--tag' => 'verifactu-config']);

        $misplaced = $this->configKeysNotOnTheirOwnLine();

        if ($misplaced !== []) {
            $this->components->error('config/verifactu.php must keep each of these keys on its own line, as published: '.Collection::make($misplaced)->join(', ').'.');

            return self::FAILURE;
        }

        $this->components->info('The config is in config/verifactu.php.');

        $this->addEnvironmentKeys();

        $model = $this->askForModel();

        if (! is_subclass_of($model, Model::class)) {
            $this->components->error("{$model} is not an Eloquent model.");

            return self::FAILURE;
        }

        $invoice = new $model;

        $existing = Schema::connection($invoice->getConnectionName())->getColumnListing($invoice->getTable());

        if ($existing === []) {
            $this->components->error("The table {$invoice->getTable()} does not exist: migrate it first.");

            return self::FAILURE;
        }

        $columns = $this->askForColumns($invoice, $existing);

        $this->writeConfig($model, $columns);

        $this->writeMigration($invoice->getTable(), array_diff($columns, $existing));

        $this->publishBatchesMigration();

        $this->listOldAeatColumns($invoice, array_diff($existing, $columns));

        $this->printNextSteps($model);

        return self::SUCCESS;
    }

    /**
     * The keys the wizard rewrites, which an earlier edit may have moved off their own line.
     *
     * @return array<int, string>
     */
    protected function configKeysNotOnTheirOwnLine(): array
    {
        $config = $this->files->get(config_path('verifactu.php'));

        /** @var array<string, string> $columns */
        $columns = config()->array('verifactu.columns');

        return Collection::make(['model', ...array_keys($columns)])
            ->reject(fn (string $key): bool => Str::isMatch($this->configLine($key), $config))
            ->values()
            ->all();
    }

    /**
     * A key's line in config/verifactu.php. \K keeps the indentation out of
     * the match, and a key never matches inside another ('number' in 'invoice_number').
     */
    protected function configLine(string $key): string
    {
        return "/^\s*\K'{$key}' => .*,$/m";
    }

    /**
     * Adds the keys to .env, and empty to .env.example; a key already there keeps its value.
     */
    protected function addEnvironmentKeys(): void
    {
        $files = Collection::make(['.env', '.env.example'])
            ->filter(fn (string $file): bool => $this->files->exists(join_paths($this->laravel->environmentPath(), $file)))
            ->each(fn (string $file) => Env::writeVariables(
                ['VERIFACTU_URL' => '', 'VERIFACTU_TOKEN' => '', 'VERIFACTU_WEBHOOK_SECRET' => ''],
                join_paths($this->laravel->environmentPath(), $file),
            ));

        if ($files->isEmpty()) {
            $this->components->warn('There is no .env: set VERIFACTU_URL, VERIFACTU_TOKEN and VERIFACTU_WEBHOOK_SECRET where the app keeps its environment.');

            return;
        }

        $this->components->info($files->join(' and ').' have VERIFACTU_URL, VERIFACTU_TOKEN and VERIFACTU_WEBHOOK_SECRET.');
    }

    /**
     * A model in app/Models by its name, or any model by its full class
     * name, as make:observer asks for it and GeneratorCommand qualifies it.
     */
    protected function askForModel(): string
    {
        $model = Str::of(suggest(label: 'Which model is the invoice?', options: $this->findAvailableModels(), required: true))
            ->ltrim('\\/')
            ->replace('/', '\\')
            ->toString();

        if (Str::startsWith($model, $this->laravel->getNamespace())) {
            return $model;
        }

        if (is_dir(app_path('Models'))) {
            return "{$this->laravel->getNamespace()}Models\\{$model}";
        }

        return "{$this->laravel->getNamespace()}{$model}";
    }

    /**
     * Each field's column: one of the table's, or a new one named as the
     * config says. A column holds one field, and never the key or a timestamp.
     *
     * @param  list<string>  $existing
     * @return array<string, string>
     */
    protected function askForColumns(Model $invoice, array $existing): array
    {
        /** @var array<string, string> $defaults */
        $defaults = config()->array('verifactu.columns');

        $reserved = Arr::whereNotNull([$invoice->getKeyName(), $invoice->getCreatedAtColumn(), $invoice->getUpdatedAtColumn()]);

        $columns = [];

        foreach ($defaults as $field => $default) {
            $options = Collection::make($existing)
                ->diff([...$reserved, ...$columns])
                ->mapWithKeys(fn (string $column): array => [$column => $column]);

            if (Collection::make([...$existing, ...$columns])->doesntContain($default)) {
                $options->prepend("Create {$default}", $default);
            }

            $selected = null;

            if ($options->has($default)) {
                $selected = $default;
            }

            $columns[$field] = (string) select(
                label: "Which column holds {$this->describe($field)}?",
                options: $options->all(),
                default: $selected,
                scroll: 10,
            );
        }

        return $columns;
    }

    /**
     * @param  array<string, string>  $columns
     */
    protected function writeConfig(string $model, array $columns): void
    {
        $path = config_path('verifactu.php');

        $config = Str::of($this->files->get($path))->replaceMatches($this->configLine('model'), fn (): string => "'model' => {$model}::class,");

        foreach ($columns as $field => $column) {
            $config = $config->replaceMatches($this->configLine($field), fn (): string => "'{$field}' => '{$column}',");
        }

        $this->files->put($path, $config->toString());

        $this->components->info('config/verifactu.php has the model and its columns.');
    }

    /**
     * Writes a migration that adds the missing columns and drops nothing,
     * the way the framework's queue:table writes its migration.
     *
     * @param  array<string, string>  $columns
     */
    protected function writeMigration(string $table, array $columns): void
    {
        if ($columns === []) {
            $this->components->info("The table {$table} has every column.");

            return;
        }

        $name = "add_verifactu_columns_to_{$table}_table";

        $directory = $this->laravel->databasePath('migrations');

        // One an earlier run wrote and that is not run yet: a second would add the same columns again.
        $pending = Collection::make($this->files->glob(join_paths($directory, "*_*_*_*_{$name}.php")))
            ->contains(fn (string $file): bool => Str::containsAll($this->files->get($file), Arr::map($columns, fn (string $column): string => "'{$column}'")));

        if ($pending) {
            $this->components->warn("A migration {$name} adds these columns already: run php artisan migrate.");

            return;
        }

        $path = $this->laravel->make('migration.creator')->create($name, $directory);

        $this->files->put($path, Str::replace(
            ['{{table}}', '{{up}}', '{{down}}'],
            [$table, $this->indent($this->columnDefinitions($columns)), $this->indent($this->columnDrops($columns))],
            $this->files->get(__DIR__.'/../../../stubs/add_verifactu_columns.stub'),
        ));

        $this->components->info("Wrote the migration {$name}, which adds ".Collection::make($columns)->join(', ', ' and ').'.');
    }

    /**
     * Publishing renames the migration to the time it runs, so publishing
     * again would add a second copy under a new name: once is enough.
     */
    protected function publishBatchesMigration(): void
    {
        if ($this->files->glob(join_paths($this->laravel->databasePath('migrations'), '*_*_*_*_create_verifactu_invoice_batches_table.php')) !== []) {
            return;
        }

        $this->callSilently('vendor:publish', ['--tag' => 'verifactu-migrations']);
    }

    /**
     * @param  array<string, string>  $columns
     * @return array<int, string>
     */
    protected function columnDefinitions(array $columns): array
    {
        return Collection::make($columns)
            ->map(fn (string $column, string $field): string => match ($field) {
                'uuid' => "\$table->uuid('{$column}')->nullable()->unique();",
                'status' => "\$table->string('{$column}')->nullable()->index();",
                'issue_date' => "\$table->date('{$column}')->nullable();",
                'qr_code' => "\$table->text('{$column}')->nullable();",
                default => "\$table->string('{$column}')->nullable();",
            })
            ->values()
            ->all();
    }

    /**
     * The indexes go first: SQLite refuses to drop an indexed column.
     *
     * @param  array<string, string>  $columns
     * @return list<string>
     */
    protected function columnDrops(array $columns): array
    {
        $drops = [];

        if (isset($columns['uuid'])) {
            $drops[] = "\$table->dropUnique(['{$columns['uuid']}']);";
        }

        if (isset($columns['status'])) {
            $drops[] = "\$table->dropIndex(['{$columns['status']}']);";
        }

        $drops[] = "\$table->dropColumn([{$this->quote($columns)}]);";

        return $drops;
    }

    /**
     * Lists the columns of the app's own AEAT setup and shows how to drop
     * them, as Passport's upgrade guide does for a table it no longer needs:
     * like Laravel's own install commands, the wizard never drops anything.
     *
     * @param  array<string>  $others  The table's columns Verifactu doesn't use.
     */
    protected function listOldAeatColumns(Model $invoice, array $others): void
    {
        $old = Collection::make($others)
            ->filter(fn (string $column): bool => Str::contains($column, self::OldAeatColumnWords, ignoreCase: true))
            ->values();

        if ($old->isEmpty()) {
            return;
        }

        $table = $invoice->getTable();

        // SQLite refuses to drop an indexed column, so its indexes go first, by their real names.
        $indexDrops = Collection::make(Schema::connection($invoice->getConnectionName())->getIndexes($table))
            ->reject(fn (array $index): bool => $index['primary'] || $old->intersect($index['columns'])->isEmpty())
            ->map(function (array $index): string {
                if ($index['unique']) {
                    return "\$table->dropUnique('{$index['name']}');";
                }

                return "\$table->dropIndex('{$index['name']}');";
            });

        $this->components->warn('The service keeps this data now, so these columns are no longer needed. They were not dropped:');

        $this->components->bulletList($old->all());

        $this->line("  To drop them, create a migration (php artisan make:migration drop_old_aeat_columns_from_{$table}_table) with:");
        $this->newLine();
        $this->line("    Schema::table('{$table}', function (Blueprint \$table) {");
        $indexDrops->each(fn (string $drop) => $this->line("        {$drop}"));
        $this->line("        \$table->dropColumn([{$this->quote($old->all())}]);");
        $this->line('    });');
        $this->newLine();
    }

    /**
     * @param  array<string>  $columns
     */
    protected function quote(array $columns): string
    {
        return Collection::make($columns)->map(fn (string $column): string => "'{$column}'")->join(', ');
    }

    /**
     * @param  array<int, string>  $lines
     */
    protected function indent(array $lines): string
    {
        return Collection::make($lines)->map(fn (string $line): string => "            {$line}")->join(PHP_EOL);
    }

    protected function printNextSteps(string $model): void
    {
        $this->components->info('Next:');

        $this->components->bulletList([
            'Fill VERIFACTU_URL, VERIFACTU_TOKEN and VERIFACTU_WEBHOOK_SECRET in .env, then run php artisan migrate.',
            'Give an administrator of the service this webhook URL: '.url(config()->string('verifactu.webhook_path')),
        ]);

        $class = class_basename($model);

        $this->line("  Add to {$model}:");
        $this->newLine();
        $this->line(<<<PHP
                use Vlec\Verifactu\Contracts\VerifactuInvoice;
                use Vlec\Verifactu\InvoiceData;
                use Vlec\Verifactu\IssuesVerifactuInvoices;
                use Vlec\Verifactu\Recipient;
                use Vlec\Verifactu\TaxLine;

                class {$class} extends Model implements VerifactuInvoice
                {
                    use IssuesVerifactuInvoices;

                    public function toVerifactu(): InvoiceData
                    {
                        return InvoiceData::make("Invoice {\$this->id}")
                            ->to(Recipient::spanish(\$this->customer_tax_id, \$this->customer_name))
                            ->lines([TaxLine::vat(\$this->subtotal_cents, rate: 21)]);
                    }
                }
            PHP);
        $this->newLine();

        $this->line('  Schedule in routes/console.php:');
        $this->newLine();
        $this->line("    Schedule::command('verifactu:refresh-pending')->hourly();");
        $this->newLine();
    }

    protected function describe(string $field): string
    {
        return match ($field) {
            'uuid' => 'the invoice\'s id on the service',
            'status' => 'its status at AEAT',
            'invoice_number' => 'the number to print, such as FACT42',
            'number' => 'the bare number a corrective invoice refers to, such as 42',
            'issue_date' => 'the issue date',
            'verification_url' => 'AEAT\'s verification URL',
            'qr_code' => 'the QR code',
            default => $field,
        };
    }
}
