<?php

declare(strict_types=1);

return [

    /*
     * The Verifactu service's host, such as https://verifactu.example.com.
     * The client adds /api/v1 itself.
     */
    'url' => env('VERIFACTU_URL'),

    /*
     * The app's API token. It decides the environment: a vf_test_ token
     * works in Test (AEAT's pre-production), a vf_prod_ token in Live.
     */
    'token' => env('VERIFACTU_TOKEN'),

    /*
     * The secret the service signs its webhooks with (whsec_...).
     */
    'webhook_secret' => env('VERIFACTU_WEBHOOK_SECRET'),

    /*
     * The app's invoice model.
     */
    'model' => 'App\Models\Invoice',

    /*
     * The path the service posts its webhooks to.
     */
    'webhook_path' => 'verifactu/webhook',

    /*
     * How many times a call is retried after a connection error, a 429,
     * a 5xx or a 409 idempotency_key_in_progress, with the same
     * Idempotency-Key. Queued jobs retry through the queue instead.
     */
    'retries' => 2,

    /*
     * Seconds to wait for a connection, and for the whole answer.
     */
    'connect_timeout' => 5,

    'timeout' => 30,

];
