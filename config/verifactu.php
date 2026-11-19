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
     * The columns of the invoice model's table that hold the service's
     * answer. invoice_number is the number to print (series and number);
     * number is the bare number a corrective invoice refers to.
     */
    'columns' => [
        'uuid' => 'verifactu_uuid',
        'status' => 'verifactu_status',
        'invoice_number' => 'verifactu_invoice_number',
        'number' => 'verifactu_number',
        'issue_date' => 'verifactu_issue_date',
        'verification_url' => 'verifactu_url',
        'qr_code' => 'qr_code',
    ],

    /*
     * The exemption reason of TaxLine::exempt() when none is given:
     * E1, exempt under article 20 of the VAT law (education, health...).
     */
    'exemption_reason' => 'E1',

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
