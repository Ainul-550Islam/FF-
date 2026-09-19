<?php

return [
    'go_payment' => [
        'enabled' => env('GO_PAYMENT_ENABLED', false),
        'url' => env('GO_PAYMENT_URL', 'http://localhost:8081'),
        'secret' => env('GO_PAYMENT_SECRET', 'CHANGE_ME_GO_PAYMENT_SECRET_PLACEHOLDER'),
        'token' => env('GO_PAYMENT_TOKEN', 'CHANGE_ME_GO_PAYMENT_TOKEN_PLACEHOLDER'),
        'timeout' => env('GO_PAYMENT_TIMEOUT', 5),
        'retry_max' => env('GO_PAYMENT_RETRY_MAX', 3),
        'circuit_breaker_threshold' => env('GO_PAYMENT_CB_THRESHOLD', 5),
        'service_id' => env('GO_PAYMENT_SERVICE_ID', 'payment-gateway-go'),
        'hmac_secret' => env('GO_PAYMENT_HMAC_SECRET', 'CHANGE_ME_GO_PAYMENT_HMAC_SECRET_PLACEHOLDER'),
        'rate_limit_per_min' => env('GO_PAYMENT_RATE_LIMIT', 60),
    ],
    'rust_security' => [
        'enabled' => env('RUST_SECURITY_ENABLED', false),
        'url' => env('RUST_SECURITY_URL', 'http://localhost:8082'),
        'secret' => env('RUST_SECURITY_SECRET', 'CHANGE_ME_RUST_SECURITY_SECRET_PLACEHOLDER'),
        'token' => env('RUST_SECURITY_TOKEN', 'CHANGE_ME_RUST_SECURITY_TOKEN_PLACEHOLDER'),
        'timeout' => env('RUST_SECURITY_TIMEOUT', 5),
        'retry_max' => env('RUST_SECURITY_RETRY_MAX', 3),
        'service_id' => env('RUST_SECURITY_SERVICE_ID', 'security-rust'),
        'hmac_secret' => env('RUST_SECURITY_HMAC_SECRET', 'CHANGE_ME_RUST_SECURITY_HMAC_SECRET_PLACEHOLDER'),
        'rate_limit_per_min' => env('RUST_SECURITY_RATE_LIMIT', 60),
    ],
    'postgres' => [
        'host' => env('POSTGRES_HOST', env('DB_HOST', '127.0.0.1')),
        'port' => env('POSTGRES_PORT', env('DB_PORT', '5432')),
        'database' => env('POSTGRES_DB', env('DB_DATABASE', 'ffarena')),
        'username' => env('POSTGRES_USER', env('DB_USERNAME', 'ffarena')),
        'password' => env('POSTGRES_PASSWORD', env('DB_PASSWORD', 'CHANGE_ME_POSTGRES_PASSWORD_PLACEHOLDER')),
    ],
    'redis' => [
        'enabled' => env('REDIS_ENABLED', false),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => env('REDIS_PORT', '6379'),
        'password' => env('REDIS_PASSWORD', 'CHANGE_ME_REDIS_PASSWORD_PLACEHOLDER'),
        'db' => env('REDIS_DB', 0),
        'cache_db' => env('REDIS_CACHE_DB', 1),
        'queue_db' => env('REDIS_QUEUE_DB', 2),
        'prefix' => env('REDIS_PREFIX', 'ffarena-local-'),
        'url' => env('REDIS_URL', 'redis://127.0.0.1:6379'),
    ],
    'events' => [
        'enabled' => env('EVENTS_ENABLED', false),
        'version' => env('EVENTS_VERSION', 'v1'),
    ],
    'service_auth' => [
        'service_id' => env('SERVICE_ID', 'ffarena-laravel'),
        'hmac_secret' => env('SERVICE_HMAC_SECRET', 'CHANGE_ME_SERVICE_HMAC_SECRET_PLACEHOLDER'),
        'timestamp_tolerance_seconds' => env('SERVICE_AUTH_TOLERANCE', 300),
    ],
];
