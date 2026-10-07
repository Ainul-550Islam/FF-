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
        // 'log' (default), 'null', or 'prometheus' — the last one aggregates
        // counters/gauges/timings in the shared cache so the /metrics scrape
        // endpoint (GAP-10 C) can render them in the Prometheus exposition
        // format. 'log' keeps working; it simply exposes no scrapable series.
        'driver' => env('METRICS_DRIVER', 'log'),

        // The dedicated log channel (see config/logging.php).
        'channel' => env('METRICS_LOG_CHANNEL', 'metrics'),

        // GAP-10 C — scrape endpoint. Both must be satisfied: the feature flag
        // AND a token. Without a token the route answers 404, so a monitoring
        // endpoint can never be exposed unauthenticated by omission.
        'enabled' => env('FEATURE_PROMETHEUS', false),
        'scrape_token' => env('METRICS_SCRAPE_TOKEN'),

        // How long a series stays visible without new samples (seconds).
        'ttl_seconds' => (int) env('METRICS_TTL_SECONDS', 7200),
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

        // GAP-10 A6: first rollout should be report-only. When true (the
        // default whenever CSP is enabled) the policy is announced through
        // Content-Security-Policy-Report-Only, so violations are reported but
        // nothing is blocked. Set SECURITY_CSP_REPORT_ONLY=false only after
        // the reports are clean, to start enforcing.
        'csp_report_only' => env('SECURITY_CSP_REPORT_ONLY', true),

        // Optional collector for violation reports. When set, report-uri (and
        // report-to) are appended to the policy so browsers send their reports
        // somewhere; leave empty to only surface violations in the console.
        'csp_report_uri' => env('SECURITY_CSP_REPORT_URI'),

        // GAP-10 A6: policy matched to this app's real usage — Laravel Blade
        // views that inline JSON-LD <script> blocks, a small amount of inline
        // style, Vite-bundled (or locally published) CSS/JS, no third-party
        // CDNs and no external fonts. 'unsafe-inline' is limited to script and
        // style sources; everything else stays locked to 'self'.
        'csp_policy' => env('SECURITY_CSP_POLICY', implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'self'",
            "form-action 'self'",
            "script-src 'self' 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "media-src 'self' data: blob:",
            "connect-src 'self'",
            "manifest-src 'self'",
        ])),
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
