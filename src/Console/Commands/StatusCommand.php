<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Vlec\Verifactu\Client;
use Vlec\Verifactu\Contracts\VerifactuInvoice;
use Vlec\Verifactu\Exceptions\VerifactuException;
use Vlec\Verifactu\InvoiceStatus;

#[Signature('verifactu:status')]
#[Description('Check the connection to the Verifactu service, and count the app\'s invoices by status')]
class StatusCommand extends Command
{
    public function handle(Client $client): int
    {
        $this->components->twoColumnDetail('Environment', $client->environment()->name ?? 'Unknown: the token is neither a vf_test_ nor a vf_prod_ token');

        $connected = $this->checkConnection($client);

        $this->newLine();

        /** @var class-string<Model&VerifactuInvoice> $model */
        $model = config()->string('verifactu.model');

        $column = config()->string('verifactu.columns.status');

        $this->components->twoColumnDetail('Not sent', (string) $model::query()->whereNull($column)->count());

        foreach (InvoiceStatus::cases() as $status) {
            $this->components->twoColumnDetail(Str::headline($status->name), (string) $model::query()->where($column, $status)->count());
        }

        if (! $connected) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function checkConnection(Client $client): bool
    {
        try {
            $client->listInvoices(['page' => ['size' => 1]]);
        } catch (VerifactuException|InvalidArgumentException $exception) {
            $this->components->twoColumnDetail('Connection', "<fg=red>{$exception->getMessage()}</>");

            return false;
        }

        $this->components->twoColumnDetail('Connection', '<fg=green>OK</>');

        return true;
    }
}
