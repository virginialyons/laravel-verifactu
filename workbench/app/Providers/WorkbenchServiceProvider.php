<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\Invoice;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * The workbench app stores the service's answer in columns named its own way.
     */
    public function register(): void
    {
        config([
            'verifactu.model' => Invoice::class,
            'verifactu.columns' => [
                'uuid' => 'verifactu_uuid',
                'status' => 'aeat_status',
                'invoice_number' => 'number',
                'number' => 'short_number',
                'issue_date' => 'issued_on',
                'verification_url' => 'verification_url',
                'qr_code' => 'qr',
            ],
        ]);
    }
}
