<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) — Phase 16
    |--------------------------------------------------------------------------
    |
    | The public API authenticates with bearer tokens (Sanctum) and never
    | uses ambient cross-origin cookies, so `supports_credentials` stays off.
    | Allowed origins are an explicit, env-driven list — never "*" — and the
    | browser preflight only applies to the API path group.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Comma-separated list of exact origins, e.g. "https://app.example.com".
    // Empty = no cross-origin browser access (secure default).
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Request-ID', 'Idempotency-Key', 'X-Requested-With'],

    'exposed_headers' => ['X-Request-ID'],

    'max_age' => 0,

    'supports_credentials' => false,
];
