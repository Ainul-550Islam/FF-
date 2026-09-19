# R9 Full File Content Part 7 - Files 91-105

Total files in this part: 15

## File: ./config/audit.php

```
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Central audit log (Phase 13)
    |--------------------------------------------------------------------------
    |
    | The central audit trail is append-only and stores only whitelisted,
    | display-safe data. Every key listed in `redact_keys` is replaced with
    | "[redacted]" by AuditLogService before anything is persisted, and any
    | JSON payload larger than `max_payload_chars` is truncated to a preview.
    |
    */

    // Keys that must never be persisted, even when a caller passes them.
    // Includes credentials, payment/webhook secrets, device/IP pseudonyms
    // and personal identifiers (defence in depth on top of caller
    // whitelisting).
    'redact_keys' => [
        'password',
        'password_confirmation',
        'token',
        'api_token',
        'access_token',
        'refresh_token',
        'secret',
        'api_key',
        'apikey',
        'authorization',
        'cookie',
        'set_cookie',
        'remember_token',
        'card',
        'card_number',
        'cvv',
        'cvc',
        'otp',
        'pin',
        'session',
        'email',
        'phone',
        'game_uid',
        'ip',
        'ip_address',
        'ip_hash',
        'subnet_hash',
        'device',
        'device_id',
        'device_hash',
        'fingerprint',
        'user_agent',
        'screenshot',
        'evidence',
        'document',
        'photo',
        'trx_id',
        'idempotency_key',
    ],

    // Longest individual string value kept inside a payload.
    'max_value_chars' => 500,

    // Longest serialized JSON payload kept whole; larger payloads are
    // replaced with a truncated preview.
    'max_payload_chars' => 4000,

];
```

## File: ./config/auth.php

```
<?php

use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        // Phase 15 — bearer-token API authentication (Laravel Sanctum).
        // Only bearer tokens authenticate this guard here; session cookies
        // are NOT accepted as mobile credentials.
        'sanctum' => [
            'driver' => 'sanctum',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
```

## File: ./config/backup.php

```
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backups (Phase 16)
    |--------------------------------------------------------------------------
    |
    | Backups are written into the private filesystem (storage/app/private by
    | default) — never into public storage — and are chmod 0600. Each backup
    | is a timestamped directory containing a consistent database snapshot,
    | an optional copy of private user files, and a manifest with a SHA-256
    | checksum used for verification.
    |
    */

    // Filesystem disk the backups are written to ('local' = private storage).
    'disk' => env('BACKUP_DISK', 'local'),

    // Directory (relative to the disk root) holding backups.
    'path' => env('BACKUP_PATH', 'backups'),

    // How many completed backups to retain. Oldest are pruned first.
    'retention' => (int) env('BACKUP_RETENTION', 14),

    // Also snapshot storage/app/private (dispute evidence, identity docs,
    // avatars, exports). Never includes the backups directory itself.
    'include_private_files' => env('BACKUP_INCLUDE_PRIVATE_FILES', true),

    // Private files copied per backup.
    'private_dir' => storage_path('app/private'),

    // Checksum algorithm recorded in the manifest.
    'checksum' => 'sha256',

    // Run "PRAGMA integrity_check" against every SQLite snapshot after it is
    // written. A failing check aborts the backup (never silent success).
    'integrity_check' => env('BACKUP_INTEGRITY_CHECK', true),

    // Notify platform admins (Phase 11 NotificationService) when a backup
    // fails or fails verification.
    'notify_admins' => env('BACKUP_NOTIFY_ADMINS', true),
];
```

## File: ./config/broadcasting.php

```
<?php

return [
    'default' => env('BROADCAST_CONNECTION', 'log'),
    'connections' => [
        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST', '127.0.0.1'),
                'port' => env('REVERB_PORT', 8080),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],
            'client_options' => [],
        ],
        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com',
                'port' => env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
            'client_options' => [],
        ],
        'ably' => ['driver' => 'ably', 'key' => env('ABLY_KEY')],
        'log' => ['driver' => 'log'],
        'null' => ['driver' => 'null'],
        // G5: Redis pub/sub for multi-server Reverb
        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
        ],
    ],
];
```

## File: ./config/cache.php

```
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
```

## File: ./config/cors.php

```
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
```

## File: ./config/database.php

```
<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            // DB_SQLITE_PATH decouples the SQLite file from DB_DATABASE (which
            // is the PostgreSQL database name in production). When unset, the
            // legacy behaviour (DB_DATABASE, then the default file) applies.
            'database' => env('DB_SQLITE_PATH') ?: env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // Default search path (schema). Keep 'public' for the standard
            // production schema; override with DB_SEARCH_PATH when using a
            // per-environment schema.
            'search_path' => env('DB_SEARCH_PATH', 'public'),
            'schema' => env('DB_SCHEMA', 'public'),
            // TLS: 'prefer' (default) tries TLS then falls back; set
            // 'require' in production behind a managed PostgreSQL/load
            // balancer that terminates TLS.
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            // Identifies this app in pg_stat_activity (observability).
            'application_name' => env('DB_APP_NAME', 'ffarena'),
            // PgBouncer (transaction pooling) support: when set, these are
            // used for the initial "real" connection while pooler-aware
            // statements run against the pooled connection. Leave empty when
            // not using a pooler.
            'connect_via_database' => env('DB_CONNECT_VIA_DATABASE'),
            'connect_via_port' => env('DB_CONNECT_VIA_PORT'),
            'options' => [
                // Connection timeout in seconds. For pdo_pgsql this maps to
                // libpq's connect_timeout; it bounds how long PHP waits when
                // PostgreSQL is unreachable instead of hanging the worker.
                PDO::ATTR_TIMEOUT => env('DB_CONNECT_TIMEOUT', 5),
            ],
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];

```

## File: ./config/features.php

```
<?php
return [
    'tournament_format_round_robin' => env('FEATURE_ROUND_ROBIN', false),
    'tournament_format_double_elimination' => env('FEATURE_DOUBLE_ELIMINATION', false),
    'tournament_format_swiss' => env('FEATURE_SWISS', false),
    'tournament_format_group_stage' => env('FEATURE_GROUP_STAGE', false),
    'tournament_format_league' => env('FEATURE_LEAGUE', false),
    'tournament_format_ffa' => env('FEATURE_FFA', false),
    'tournament_format_multi_stage' => env('FEATURE_MULTI_STAGE', false),
    'tournament_format_hybrid' => env('FEATURE_HYBRID', false),
    'payment_provider_bkash' => env('FEATURE_PAYMENT_BKASH', true),
    'payment_provider_nagad' => env('FEATURE_PAYMENT_NAGAD', false),
    'payment_provider_rocket' => env('FEATURE_PAYMENT_ROCKET', false),
    'payout_provider_bkash' => env('FEATURE_PAYOUT_BKASH', false),
    'payout_provider_bank' => env('FEATURE_PAYOUT_BANK', true),
    'realtime_sse' => env('FEATURE_REALTIME_SSE', true),
    'realtime_reverb' => env('FEATURE_REALTIME_REVERB', false),
    'realtime_polling' => env('FEATURE_REALTIME_POLLING', true),
    'fraud_device_intelligence' => env('FEATURE_FRAUD_DEVICE', true),
    'fraud_ip_intelligence' => env('FEATURE_FRAUD_IP', true),
    'fraud_external_intelligence' => env('FEATURE_FRAUD_EXTERNAL', false),
    'fraud_identity_intelligence' => env('FEATURE_FRAUD_IDENTITY', true),
    'notification_sms' => env('FEATURE_NOTIFICATION_SMS', false),
    'notification_push' => env('FEATURE_NOTIFICATION_PUSH', true),
    'notification_email' => env('FEATURE_NOTIFICATION_EMAIL', true),
    'storage_s3' => env('FEATURE_STORAGE_S3', false),
    'storage_local' => env('FEATURE_STORAGE_LOCAL', true),
    'mobile_push' => env('FEATURE_MOBILE_PUSH', true),
    'scoring_custom_rules' => env('FEATURE_SCORING_CUSTOM', false),
    'scoring_tiebreaker' => env('FEATURE_SCORING_TIEBREAKER', true),
    'admin_beta' => env('FEATURE_ADMIN_BETA', false),
    'api_v2' => env('FEATURE_API_V2', false),
    'observability_prometheus' => env('FEATURE_PROMETHEUS', false),
];
```

## File: ./config/filesystems.php

```
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
```

## File: ./config/finance.php

```
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Financial settlement (Phase 09)
    |--------------------------------------------------------------------------
    |
    | Global financial configuration for prize distribution, payouts and
    | reconciliation. All monetary values are integer minor units (BDT
    | poisha) or basis points (1/100th of a percent) — never floats.
    |
    | Commission defaults to ZERO: no pre-existing commission business rule
    | exists in FF Arena, so no fee is silently introduced into existing or
    | new tournaments. Set PLATFORM_COMMISSION_* to enable a platform fee.
    |
    */

    'commission' => [
        // 'percentage' (basis points of net collections) or 'fixed' (poisha).
        'type' => env('PLATFORM_COMMISSION_TYPE', 'percentage'),

        // Percentage commission in basis points (10000 = 100%). Default 0.
        'percentage_bp' => (int) env('PLATFORM_COMMISSION_BP', 0),

        // Fixed commission in poisha (100 = ৳1.00). Default 0.
        'fixed_minor' => (int) env('PLATFORM_COMMISSION_FIXED_MINOR', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payouts
    |--------------------------------------------------------------------------
    |
    | The default payout provider for prize payouts.
    |  - 'wallet' : internal wallet credit (the only provider shipped today).
    |  - 'manual' : manually processed external payout (bank/bKash agent) —
    |               no external API is called and no success is faked.
    |
    */
    'default_payout_provider' => env('DEFAULT_PAYOUT_PROVIDER', 'wallet'),
];
```

## File: ./config/live.php

```
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Realtime / live updates (Phase 12)
    |--------------------------------------------------------------------------
    |
    | Near-real-time tournament visibility via lightweight polling and a
    | server-sent-events (SSE) stream. No external services, no websocket
    | infra, no client-side trust: event visibility is enforced server-side.
    |
    */

    // Client polling interval (milliseconds).
    'poll_interval_ms' => 10000,

    // SSE keep-alive configuration (seconds).
    'stream' => [
        'heartbeat' => 15,
        'max_duration' => 60,
    ],

    // Event types visible to everyone (including guests). Any type not
    // listed here is staff-only: an organizer of that tournament, a
    // moderator, or an admin.
    'public_types' => [
        'match.score_submitted',
        'match.completed',
        'match.disputed',
        'match.resolved',
        'match.started',
        'team.checked_in',
        'team.registered',
        'team.withdrawn',
    ],

];
```

## File: ./config/logging.php

```
<?php

use App\Support\Logging\DomainLogChannel;
use App\Support\Logging\RedactSensitiveDataProcessor;
use App\Support\Logging\RequestContextProcessor;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

/*
|--------------------------------------------------------------------------
| Phase 16 logging layout
|--------------------------------------------------------------------------
|
| Every file channel is defined on the "monolog" driver so it can carry the
| security processors on every line:
|
|   PsrLogMessageProcessor        — interpolates {placeholders} (Laravel default)
|   RequestContextProcessor       — attaches request_id / route / method / ids
|   RedactSensitiveDataProcessor  — scrubs secrets as a last-line defence
|
| Channel names `single` and `daily` are preserved, so existing
| LOG_CHANNEL/LOG_STACK values keep working unchanged. Dedicated channels
| (security, payments, webhooks, queue, audit, errors, metrics) each rotate
| daily into their own file — logs are separated by domain, not duplicated
| into every file.
*/

return [

    'default' => env('LOG_CHANNEL', 'stack'),

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => storage_path('logs/laravel.log'),
            ],
            'formatter' => LineFormatter::class,
            'processors' => [
                PsrLogMessageProcessor::class,
                RequestContextProcessor::class,
                RedactSensitiveDataProcessor::class,
            ],
        ],

        'daily' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => RotatingFileHandler::class,
            'handler_with' => [
                'filename' => storage_path('logs/laravel.log'),
                'maxFiles' => (int) env('LOG_DAILY_DAYS', 14),
            ],
            'formatter' => LineFormatter::class,
            'processors' => [
                PsrLogMessageProcessor::class,
                RequestContextProcessor::class,
                RedactSensitiveDataProcessor::class,
            ],
        ],

        // Structured JSON-lines stream for the metrics/observability stack.
        'json' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => storage_path('logs/ffarena.jsonl'),
            ],
            'formatter' => JsonFormatter::class,
            'formatter_with' => [JsonFormatter::BATCH_MODE_JSON, true, false, false],
            'processors' => [
                RequestContextProcessor::class,
                RedactSensitiveDataProcessor::class,
            ],
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', env('APP_NAME', 'Laravel')),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER') ?: LineFormatter::class,
            'processors' => [
                PsrLogMessageProcessor::class,
                RequestContextProcessor::class,
                RedactSensitiveDataProcessor::class,
            ],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

        /*
        |------------------------------------------------------------------
        | Phase 16 — domain-separated channels
        |------------------------------------------------------------------
        | Each writes to its own daily-rotated file and carries the
        | correlation + redaction processors. Callers use them explicitly
        | (e.g. Log::channel('security')->warning(...)).
        */
        'security' => DomainLogChannel::config('security'),
        'payments' => DomainLogChannel::config('payments'),
        'webhooks' => DomainLogChannel::config('webhooks'),
        'queue' => DomainLogChannel::config('queue'),
        'audit' => DomainLogChannel::config('audit'),
        'errors' => DomainLogChannel::config('errors'),
        'metrics' => DomainLogChannel::config('metrics'),
    ],

];
```

## File: ./config/mail.php

```
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
            'retry_after' => 60,
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'Laravel')),
    ],

];
```

## File: ./config/mobile.php

```
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mobile application (Phase 18)
    |--------------------------------------------------------------------------
    |
    | Server-side configuration for the native mobile client. Every value is
    | environment-driven; production credentials (FCM/APNs) are intentionally
    | absent by default so the app keeps working with push disabled honestly.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | App version policy
    |--------------------------------------------------------------------------
    |
    | The client compares its own version against min_supported_app_version
    | and may warn (but never hard-block) when out of date. latest_app_version
    | is informational only. update_required flips the server into a
    | mandatory-update state for all older clients (used only for breaking or
    | security-critical releases — never for every new release).
    |
    */
    'min_supported_app_version' => env('MOBILE_MIN_APP_VERSION', '1.0.0'),
    'latest_app_version' => env('MOBILE_LATEST_APP_VERSION', '1.0.0'),
    'update_required' => (bool) env('MOBILE_UPDATE_REQUIRED', false),

    /*
    |--------------------------------------------------------------------------
    | Maintenance mode (mobile)
    |--------------------------------------------------------------------------
    |
    | When set, the app meta endpoint advertises maintenance and the client
    | shows the message and blocks mutations. The server remains
    | authoritative; this flag is a communication channel, not an
    | enforcement mechanism.
    |
    */
    'maintenance' => (bool) env('MOBILE_MAINTENANCE_MODE', false),
    'maintenance_message' => (string) env('MOBILE_MAINTENANCE_MESSAGE', 'FF Arena is under maintenance. Please try again shortly.'),

    /*
    |--------------------------------------------------------------------------
    | Deep links
    |--------------------------------------------------------------------------
    */
    'deep_link_scheme' => env('MOBILE_DEEP_LINK_SCHEME', 'ffarena'),

    /*
    |--------------------------------------------------------------------------
    | Public URLs surfaced to the app (privacy policy, terms, support).
    |--------------------------------------------------------------------------
    */
    'support_url' => env('MOBILE_SUPPORT_URL', ''),
    'privacy_url' => env('MOBILE_PRIVACY_URL', ''),
    'terms_url' => env('MOBILE_TERMS_URL', ''),
    'release_notes_url' => env('MOBILE_RELEASE_NOTES_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Web / store links (App Links, Universal Links, update store page)
    |--------------------------------------------------------------------------
    |
    | web_base_url is the canonical public origin that hosts assetlinks.json
    | and apple-app-site-association for verified deep links. store_url is
    | where a user is sent when the app is too old (App Store / Play Store).
    |
    */
    'web_base_url' => env('MOBILE_WEB_BASE_URL', ''),
    'store_url' => env('MOBILE_STORE_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Push providers
    |--------------------------------------------------------------------------
    |
    | fcm_enabled / apns_enabled are honest capability flags the app reads to
    | decide whether to request a push token at all. They default to false;
    | nothing is faked when they are off. Credential values below are
    | server-side only and never shipped to the mobile app.
    |
    */
    'push' => [
        'fcm_enabled' => (bool) env('PUSH_FCM_ENABLED', false),
        'apns_enabled' => (bool) env('PUSH_APNS_ENABLED', false),
        'device_token_max_length' => 4096,

        // FCM HTTP v1 (service account). Exactly one of the key forms is
        // required; the JSON key file path is preferred in production.
        'fcm_project_id' => env('FCM_PROJECT_ID', ''),
        'fcm_client_email' => env('FCM_CLIENT_EMAIL', ''),
        'fcm_private_key' => env('FCM_PRIVATE_KEY', ''),
        'fcm_private_key_path' => env('FCM_PRIVATE_KEY_PATH', ''),

        // Direct APNs (token-based). Only used when apns_enabled is true.
        'apns_key_id' => env('APNS_KEY_ID', ''),
        'apns_team_id' => env('APNS_TEAM_ID', ''),
        'apns_bundle_id' => env('APNS_BUNDLE_ID', ''),
        'apns_private_key' => env('APNS_PRIVATE_KEY', ''),
        'apns_private_key_path' => env('APNS_PRIVATE_KEY_PATH', ''),
        'apns_sandbox' => (bool) env('APNS_SANDBOX', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Device registry
    |--------------------------------------------------------------------------
    */
    'devices' => [
        'token_hash_algo' => 'sha256',
        'max_devices_per_user' => 25,
    ],

];

```

## File: ./config/notifications.php

```
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Notifications (Phase 11)
    |--------------------------------------------------------------------------
    |
    | In-app notifications are always written (the primary, always-available
    | channel). Email is an optional, best-effort secondary channel: it is
    | disabled when NOTIFICATIONS_EMAIL=false, and a failure to deliver email
    | never fails the originating action.
    |
    */

    // Send a best-effort email alongside every in-app notification.
    'email_enabled' => (bool) env('NOTIFICATIONS_EMAIL', true),

    // Default page size for the notification inbox.
    'per_page' => 20,

];
```

