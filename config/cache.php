<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Cache Store — G4 Redis Production
    |--------------------------------------------------------------------------
    |
    | Local: database (or file) by default for zero-setup dev.
    | Test: array (isolated, no Redis required unless integration tests).
    | Staging/Production: redis (set CACHE_STORE=redis).
    |
    | G4 documents that production MUST use redis for distributed locks,
    | rate limiting, and cache coherence across workers.
    |
    */

    'default' => env('CACHE_STORE', 'database'),

    'stores' => [

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_CACHE_CONNECTION'),
            'table' => env('DB_CACHE_TABLE', 'cache'),
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
            'lock_table' => env('DB_CACHE_LOCK_TABLE'),
        ],

        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],

        'memcached' => [
            'driver' => 'memcached',
            'persistent_id' => env('MEMCACHED_PERSISTENT_ID'),
            'sasl' => [
                env('MEMCACHED_USERNAME'),
                env('MEMCACHED_PASSWORD'),
            ],
            'options' => [],
            'servers' => [
                [
                    'host' => env('MEMCACHED_HOST', '127.0.0.1'),
                    'port' => env('MEMCACHED_PORT', 11211),
                    'weight' => 100,
                ],
            ],
        ],

        // G4 — Redis cache store with isolated connection and lock connection.
        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
            'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
        ],

        'dynamodb' => [
            'driver' => 'dynamodb',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'table' => env('DYNAMODB_CACHE_TABLE', 'cache'),
            'endpoint' => env('DYNAMODB_ENDPOINT'),
        ],

        'octane' => [
            'driver' => 'octane',
        ],

        // Failover: try redis, then database, then array — safe degradation when Redis unavailable.
        'failover' => [
            'driver' => 'failover',
            'stores' => [
                'redis',
                'database',
                'array',
            ],
        ],

        // G4 — dedicated failover for non-critical caches that may fall back to DB.
        'redis_failover' => [
            'driver' => 'failover',
            'stores' => [
                'redis',
                'database',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix — G4 environment-aware
    |--------------------------------------------------------------------------
    |
    | Format: ffarena:{env}:cache- by default, e.g. ffarena:local:cache-
    | This prefix is prepended to every key by Laravel's cache manager.
    | Application-level keys (CacheKeys, RedisKeys) add their own namespace
    | on top: ffarena:{env}:{domain}:{identifier}:{version}
    |
    */

    'prefix' => env('CACHE_PREFIX', 'ffarena-'.env('APP_ENV', 'local').'-cache-'),

];
