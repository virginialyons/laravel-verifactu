<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Vlec\Verifactu\Contracts\VerifactuInvoice;
use Vlec\Verifactu\Exceptions\VerifactuException;

#[Signature('verifactu:correct {invoice : The key of the invoice model} {--description= : The record\'s new description}')]
#[Description('Send AEAT a new record of an invoice: with a new description, or unchanged after a technical rejection')]
class CorrectCommand extends Command
{
    public function handle(): int
    {
        /** @var class-string<Model&VerifactuInvoice> $model */
        $model = config()->string('verifactu.model');

        $invoice = $model::query()->whereKey($this->argument('invoice'))->first();

        if ($invoice === null) {
            $this->components->error('There is no invoice with that key.');

            return self::FAILURE;
        }

        // Without a filled description, the record is sent again unchanged.
        $description = null;

        if ($this->input()->filled('description')) {
            $description = $this->input()->string('description')->toString();
        }

        try {
            $invoice->correctOnVerifactu($description);
        } catch (VerifactuException|LogicException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('The invoice has a new record, pending at AEAT.');

        return self::SUCCESS;
    }
}
