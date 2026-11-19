<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vlec\Verifactu\Http\Controllers\WebhookController;
use Vlec\Verifactu\Http\Middleware\VerifyWebhookSignature;

// Outside the web group: no CSRF token, session or cookies; the signature is the check.
Route::post(config()->string('verifactu.webhook_path'), WebhookController::class)
    ->middleware(VerifyWebhookSignature::class)
    ->name('verifactu.webhook');
