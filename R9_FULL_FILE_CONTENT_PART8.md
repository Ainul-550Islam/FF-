# R9 Full File Content Part 8 - Files 106-120

Total files in this part: 15

## File: ./config/observability.php

```
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Observability (Phase 16)
    |--------------------------------------------------------------------------
    |
    | Central configuration for production hardening: request correlation,
    | structured logging, metrics, error reporting, health checks and data
    | retention. Nothing here is authoritative for business logic — it only
    | controls how the application observes itself.
    |
    */

    'request_id' => [
        // Response header carrying the correlation id.
        'header' => env('REQUEST_ID_HEADER', 'X-Request-ID'),

        // Accept a caller-supplied id (gateways/load balancers) when it is
        // present and well-formed; otherwise generate a UUID v4.
        'accept_inbound' => env('REQUEST_ID_ACCEPT_INBOUND', true),

        // The only accepted inbound format: 8-64 chars of [A-Za-z0-9-].
        // Anything else (huge ids, control chars, spaces) is replaced.
        'pattern' => '/^[A-Za-z0-9\-]{8,64}$/',
    ],

    'metrics' => [
        // 'log' (default) or 'null'.
        'driver' => env('METRICS_DRIVER', 'log'),

        // The dedicated log channel (see config/logging.php).
        'channel' => env('METRICS_LOG_CHANNEL', 'metrics'),
    ],

    'error_reporting' => [
        // 'log' (default, always available) or 'sentry' (requires the SDK).
        'driver' => env('ERROR_REPORTING_DRIVER', 'log'),

        // Sentry DSN — never logged, never exposed in health responses.
        'dsn' => env('SENTRY_DSN'),
    ],

    'health' => [
        // A queue worker that hasn't heartbeated within this window is
        // reported as degraded on the readiness + admin diagnostics.
        'worker_stale_after_seconds' => (int) env('HEALTH_WORKER_STALE_SECONDS', 300),

        // A scheduler that hasn't heartbeated within this window is reported
        // as degraded.
        'scheduler_stale_after_seconds' => (int) env('HEALTH_SCHEDULER_STALE_SECONDS', 300),

        // TTL of the cache probe key used by the readiness check.
        'cache_probe_ttl_seconds' => (int) env('HEALTH_CACHE_PROBE_TTL', 30),
    ],

    'security_headers' => [
        'hsts_enable' => env('SECURITY_HSTS_ENABLE', true),
        'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
        'hsts_include_subdomains' => env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', false),

        // CSP is opt-in: enabling it blindly can break an existing app.
        'csp_enable' => env('SECURITY_CSP_ENABLE', false),
        'csp_policy' => env('SECURITY_CSP_POLICY', "default-src 'self'"),
    ],

    /*
    |--------------------------------------------------------------------------
    | Operational data retention (days)
    |--------------------------------------------------------------------------
    |
    | Windows for the scheduled cleanup jobs. Values are deliberately
    | conservative; cleanup only touches operational/temporary rows, never
    | immutable business or audit records.
    |
    */
    'retention' => [
        'otp_challenges_days' => (int) env('OBS_RETENTION_OTP_DAYS', 1),
        'idempotency_keys_days' => (int) env('OBS_RETENTION_IDEMPOTENCY_DAYS', 2),
        'notifications_days' => (int) env('OBS_RETENTION_NOTIFICATIONS_DAYS', 180),
        'webhook_deliveries_days' => (int) env('OBS_RETENTION_WEBHOOK_DELIVERIES_DAYS', 30),
        'webhook_events_days' => (int) env('OBS_RETENTION_WEBHOOK_EVENTS_DAYS', 90),
        'live_events_days' => (int) env('OBS_RETENTION_LIVE_EVENTS_DAYS', 30),
        'failed_jobs_days' => (int) env('OBS_RETENTION_FAILED_JOBS_DAYS', 30),
        'logs_days' => (int) env('LOG_DAILY_DAYS', 14),
    ],

];
```

## File: ./config/payments.php

```
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

```

## File: ./config/queue.php

```
<?php

return [

    'default' => env('QUEUE_CONNECTION', 'database'),

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALK_HOST', 'localhost'),
            'queue' => env('BEANSTALK_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALK_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'queue'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for' => null,
            'after_commit' => false,
        ],

    ],

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
```

## File: ./config/reverb.php

```
<?php

return [
    'default' => env('REVERB_SERVER', 'reverb'),
    'servers' => [
        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
            'port' => env('REVERB_SERVER_PORT', 8080),
            'hostname' => env('REVERB_HOST', '127.0.0.1'),
            'options' => ['tls' => []],
            'max_request_size' => env('REVERB_MAX_REQUEST_SIZE', 10000),
            'scaling' => [
                'enabled' => env('REVERB_SCALING_ENABLED', false),
                'channel' => env('REVERB_SCALING_CHANNEL', 'reverb'),
                'server' => ['url' => env('REVERB_SCALING_SERVER_URL'), 'host' => env('REVERB_SCALING_SERVER_HOST', '127.0.0.1'), 'port' => env('REVERB_SCALING_SERVER_PORT', 6379), 'username' => env('REVERB_SCALING_SERVER_USERNAME'), 'password' => env('REVERB_SCALING_SERVER_PASSWORD'), 'database' => env('REVERB_SCALING_SERVER_DB', 0)],
            ],
        ],
    ],
    'apps' => [
        'provider' => 'config',
        'apps' => [[
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => ['host' => env('REVERB_HOST'), 'port' => env('REVERB_PORT'), 'scheme' => env('REVERB_SCHEME', 'http'), 'useTLS' => env('REVERB_SCHEME', 'http') === 'https'],
            'allowed_origins' => ['*'],
            'ping_interval' => env('REVERB_APP_PING_INTERVAL', 60),
            'max_message_size' => env('REVERB_APP_MAX_MESSAGE_SIZE', 10000),
        ]],
    ],
];
```

## File: ./config/sanctum.php

```
<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort(),
        // Sanctum::currentRequestHost(),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    | Phase 15: FF Arena's /api/v1 is bearer-only. The session guard is NOT
    | listed here, so a session cookie can never authenticate an API request
    | as a mobile credential — the two modes stay strictly distinct. Browser
    | sessions continue to use the `web` guard directly.
    |
    */

    'guard' => [],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. This will override any values set in the token's
    | "expires_at" attribute, but first-party sessions are not affected.
    |
    */

    'expiration' => null,

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
```

## File: ./config/services.php

```
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Payments (Phase 08)
    |--------------------------------------------------------------------------
    */
    'payments' => [
        // Secret used to sign/verify provider webhook callbacks (HMAC-SHA256).
        'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET', 'ffarena-local-webhook-secret'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google OAuth / OpenID Connect (Phase 14)
    |--------------------------------------------------------------------------
    |
    | Server-side OAuth 2.0 / OIDC via Laravel Socialite. The client secret
    | is read from the environment only. When the credentials are absent the
    | provider reports "not configured" and no redirect is issued.
    |
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', env('APP_URL', 'http://localhost') . '/auth/google/callback'),
    ],

];

```

## File: ./config/services_go_rust.php

```
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
```

## File: ./config/session.php

```
<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Session Driver — Final Hardening
    |--------------------------------------------------------------------------
    | Local: database for zero-setup dev
    | Production: redis for distributed session coherence
    */

    'driver' => env('SESSION_DRIVER', 'database'),

    'lifetime' => (int) env('SESSION_LIFETIME', 120),

    'expire_on_close' => env('SESSION_EXPIRE_ON_CLOSE', false),

    /*
    |--------------------------------------------------------------------------
    | Session Encryption — Final Hardening
    |--------------------------------------------------------------------------
    | Encrypt session data at rest
    */

    'encrypt' => env('SESSION_ENCRYPT', false),

    'files' => storage_path('framework/sessions'),

    'connection' => env('SESSION_CONNECTION'),

    'table' => env('SESSION_TABLE', 'sessions'),

    'store' => env('SESSION_STORE'),

    'lottery' => [2, 100],

    'cookie' => env(
        'SESSION_COOKIE',
        Str::slug((string) env('APP_NAME', 'laravel')).'-session'
    ),

    'path' => env('SESSION_PATH', '/'),

    'domain' => env('SESSION_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | HTTPS Only Cookies — Final Hardening
    |--------------------------------------------------------------------------
    | Secure: only sent over HTTPS in production
    | Must be true for HTTPS in production
    */

    'secure' => env('SESSION_SECURE_COOKIE', null),

    /*
    |--------------------------------------------------------------------------
    | HTTP Only — Final Hardening
    |--------------------------------------------------------------------------
    | Prevent JavaScript access to cookie value — must be true in production
    */

    'http_only' => env('SESSION_HTTP_ONLY', true),

    /*
    |--------------------------------------------------------------------------
    | Same-Site Cookies — Final Hardening
    |--------------------------------------------------------------------------
    | Mitigate CSRF attacks — lax or strict in production
    | Supported: lax, strict, none, null
    */

    'same_site' => env('SESSION_SAME_SITE', 'lax'),

    'partitioned' => env('SESSION_PARTITIONED_COOKIE', false),

];
```

## File: ./config/trustedproxy.php

```
<?php

/**
 * Final Hardening — TLS / Trusted Proxies
 * Configurable via env, never hardcode production domain
 */
return [
    'proxies' => env('TRUSTED_PROXIES', null), // null, *, or comma-separated IPs
    'headers' => env('TRUSTED_PROXY_HEADERS', 'X_FORWARDED_FOR|X_FORWARDED_HOST|X_FORWARDED_PORT|X_FORWARDED_PROTO|X_FORWARDED_AWS_ELB'),
];
```

## File: ./config/webhooks.php

```
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Webhook platform (Phase 15)
    |--------------------------------------------------------------------------
    |
    | Inbound: signed provider callbacks are verified (HMAC + timestamp
    | tolerance + event-id idempotency) and logged before being handed to the
    | existing Phase 08 payment callback logic. Nothing about a webhook is
    | trusted until it validates against internal state.
    |
    | Outbound: approved third-party subscriptions receive signed, queued
    | deliveries with exponential backoff. A delivery failure never rolls
    | back any business state.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Inbound provider secrets
    |--------------------------------------------------------------------------
    |
    | Per-provider HMAC secrets used to verify inbound webhook signatures.
    | The legacy Phase 08 `services.payments.webhook_secret` remains the
    | authoritative secret for /webhooks/payments/*; these entries extend the
    | platform to the new /api/v1/webhooks/inbound/* surface.
    |
    */
    'inbound' => [
        // Per-provider HMAC secrets. When a provider has no dedicated secret,
        // the Phase 08 `services.payments.webhook_secret` is used, so inbound
        // payment events share the same trust root as /webhooks/payments/*.
        'providers' => [
            'bkash' => env('WEBHOOK_BKASH_SECRET'),
            'nagad' => env('WEBHOOK_NAGAD_SECRET'),
            'rocket' => env('WEBHOOK_ROCKET_SECRET'),
            'sslcommerz' => env('WEBHOOK_SSLCOMMERZ_SECRET'),
            'card' => env('WEBHOOK_CARD_SECRET'),
        ],

        // Maximum age of a signed request (seconds). Older requests are
        // rejected as replays.
        'timestamp_tolerance' => 300,

        // Maximum raw body size accepted from a provider (bytes).
        'max_payload_bytes' => 65536,
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbound delivery
    |--------------------------------------------------------------------------
    */
    'outbound' => [
        // Retry schedule (seconds). Attempt N uses backoff[N-1].
        'backoff' => [10, 60, 300, 1800, 3600, 10800],

        // Disable an endpoint after this many consecutive failures.
        'disable_after_failures' => 6,

        // HTTP timeout for a delivery attempt (seconds).
        'timeout' => 10,

        // Tolerated clock skew when verifying X-FFArena-Timestamp on the
        // receiving side (used by our own verification helper + docs).
        'timestamp_tolerance' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbound event vocabulary
    |--------------------------------------------------------------------------
    |
    | Events the platform may emit. Only events in this list are dispatchable;
    | subscribers may only select events from this list.
    |
    */
    'events' => [
        'tournament.created',
        'tournament.started',
        'tournament.completed',
        'team.registered',
        'team.withdrawn',
        'team.checked_in',
        'match.started',
        'match.completed',
        'match.score_submitted',
        'match.disputed',
        'match.resolved',
        'payment.created',
        'payment.succeeded',
        'payment.failed',
        'refund.completed',
        'payout.processing',
        'payout.completed',
        'payout.failed',
        'dispute.opened',
        'dispute.resolved',
        'support.ticket.created',
        'support.ticket.updated',
    ],

];
```

## File: ./database/.gitignore

```
*.sqlite*
```

## File: ./database/database.sqlite

```
[binary file: ./database/database.sqlite]
```

## File: ./database/factories/TournamentFactory.php

```
<?php
namespace Database\Factories;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
class TournamentFactory extends Factory
{
    public function definition(): array{$name=fake()->words(3,true); return ['name'=>$name,'slug'=>Str::slug($name).'-'.Str::random(6),'status'=>'open','entry_fee_minor'=>1000,'prize_pool_minor'=>10000,'max_teams'=>16,'starts_at'=>now()->addDay(),'ends_at'=>now()->addDays(2),'metadata'=>[]];}
}
```

## File: ./database/factories/UserFactory.php

```
<?php
namespace Database\Factories;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
class UserFactory extends Factory
{
    public function definition(): array{return ['name'=>fake()->name(),'email'=>fake()->unique()->safeEmail(),'email_verified_at'=>now(),'password'=>bcrypt('password'),'remember_token'=>Str::random(10),'is_admin'=>false,'is_staff'=>false,'is_active'=>true];}
    public function admin(): static{return $this->state(fn()=>['is_admin'=>true,'is_staff'=>true]);}
}
```

## File: ./database/factories/WalletFactory.php

```
<?php
namespace Database\Factories;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
class WalletFactory extends Factory{public function definition(): array{return ['user_id'=>User::factory(),'currency'=>'BDT','balance_minor'=>0,'is_locked'=>false];}}
```

