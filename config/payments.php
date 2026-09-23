<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Payment providers (Phase 14)
    |--------------------------------------------------------------------------
    |
    | Honest provider configuration. Every provider has an `enabled` flag and
    | a `mode` (sandbox | production). When credentials are absent, the
    | provider reports `configured = false` and the UI shows
    | "Provider is not configured" — the platform NEVER fabricates a
    | successful external transaction.
    |
    | Secrets are read from the environment only and are never committed.
    |
    */

    // NOTE: the shared webhook verification secret remains the Phase 08
    // `services.payments.webhook_secret` (single source of truth). This file
    // only adds the per-provider merchant configuration below.

    'providers' => [

        'bkash' => [
            'label' => 'bKash',
            'enabled' => (bool) env('BKASH_ENABLED', true),
            'mode' => env('BKASH_MODE', 'sandbox'),       // sandbox | production
            'base_url' => env('BKASH_BASE_URL'),
            'app_key' => env('BKASH_APP_KEY'),
            'app_secret' => env('BKASH_APP_SECRET'),
            'username' => env('BKASH_USERNAME'),
            'password' => env('BKASH_PASSWORD'),
            'merchant_number' => env('BKASH_MERCHANT_NUMBER'),
        ],

        'nagad' => [
            'label' => 'Nagad',
            'enabled' => (bool) env('NAGAD_ENABLED', true),
            'mode' => env('NAGAD_MODE', 'sandbox'),
            'base_url' => env('NAGAD_BASE_URL'),
            'merchant_id' => env('NAGAD_MERCHANT_ID'),
            'merchant_private_key' => env('NAGAD_MERCHANT_PRIVATE_KEY'),
            'pg_public_key' => env('NAGAD_PG_PUBLIC_KEY'),
            'merchant_number' => env('NAGAD_MERCHANT_NUMBER'),
        ],

        'rocket' => [
            'label' => 'Rocket',
            'enabled' => (bool) env('ROCKET_ENABLED', true),
            'mode' => env('ROCKET_MODE', 'sandbox'),
            'base_url' => env('ROCKET_BASE_URL'),
            'merchant_id' => env('ROCKET_MERCHANT_ID'),
            'merchant_secret' => env('ROCKET_MERCHANT_SECRET'),
        ],

        'card' => [
            'label' => 'Card',
            'enabled' => (bool) env('CARD_ENABLED', false),
            'mode' => env('CARD_MODE', 'sandbox'),
            'gateway' => env('CARD_GATEWAY'),              // hosted gateway slug
            'merchant_id' => env('CARD_MERCHANT_ID'),
            'merchant_secret' => env('CARD_MERCHANT_SECRET'),
        ],

        'bank' => [
            'label' => 'Bank Transfer',
            'enabled' => (bool) env('BANK_ENABLED', true),
            'mode' => 'manual',
            'account_name' => env('BANK_ACCOUNT_NAME'),
            'account_number' => env('BANK_ACCOUNT_NUMBER'),
        ],

        'sslcommerz' => [
            'label' => 'SSLCommerz',
            'enabled' => (bool) env('SSLCOMMERZ_ENABLED', false),
            'mode' => env('SSLCOMMERZ_MODE', 'sandbox'),
            'store_id' => env('SSLCOMMERZ_STORE_ID'),
            'store_password' => env('SSLCOMMERZ_STORE_PASSWORD'),
            'base_url' => env('SSLCOMMERZ_BASE_URL'),
        ],

    ],

];
