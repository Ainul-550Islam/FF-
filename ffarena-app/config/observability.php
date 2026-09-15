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
