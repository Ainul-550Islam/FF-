<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public API (Phase 15)
    |--------------------------------------------------------------------------
    |
    | Central, server-side configuration for the /api/v1 platform: token
    | scopes, token lifetimes, idempotency, pagination and per-route rate
    | limits. Clients can never self-declare any of this — every value is
    | enforced server-side.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Token scopes
    |--------------------------------------------------------------------------
    |
    | The closed vocabulary of grantable abilities. `admin` is reserved and
    | can never be granted to a normal API application; it is only assigned
    | to tokens created by platform staff. Financial mutation scopes are
    | deliberately separate from read scopes.
    |
    */
    'scopes' => [
        // Account / profile
        'profile:read',
        'profile:write',

        // Tournaments
        'tournaments:read',
        'tournaments:register',

        // Teams / roster
        'teams:read',
        'teams:write',
        'roster:read',
        'roster:write',

        // Matches / scores
        'matches:read',
        'scores:read',
        'scores:submit',

        // Leaderboards
        'leaderboard:read',

        // Notifications
        'notifications:read',
        'notifications:write',

        // Financial (read)
        'wallet:read',
        'payments:read',
        'payouts:read',

        // Financial (mutations — explicitly restricted)
        'payments:create',

        // Support / disputes
        'support:read',
        'support:write',
        'disputes:read',
        'disputes:write',
    ],

    /*
    |--------------------------------------------------------------------------
    | Reserved / staff-only scopes
    |--------------------------------------------------------------------------
    |
    | `admin` is the only scope that unlocks the /api/v1/admin/* surface. It
    | is never offered to, and never accepted from, a normal client.
    |
    */
    'staff_scopes' => [
        'admin',
    ],

    /*
    |--------------------------------------------------------------------------
    | Token lifetime
    |--------------------------------------------------------------------------
    |
    | Default and maximum personal-access-token lifetimes. A client may ask
    | for a shorter life; the server clamps it to this maximum.
    |
    */
    'token' => [
        'default_days' => 30,
        'max_days' => 365,
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    |
    | Idempotency-Key handling for critical mutation endpoints. Keys expire
    | after `ttl_seconds`; a replayed request within that window returns the
    | stored response instead of executing again.
    |
    */
    'idempotency' => [
        'ttl_seconds' => 86400,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination limits
    |--------------------------------------------------------------------------
    */
    'pagination' => [
        'default_per_page' => 15,
        'max_per_page' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits (named limiters registered in AppServiceProvider)
    |--------------------------------------------------------------------------
    |
    | These keys mirror the RateLimiter::for() names used by the API routes.
    | Values are the max attempts per window; the window is encoded in the
    | limiter definition itself.
    |
    */
    'rate_limits' => [
        // The two READ-path limits are env-overridable so a synthetic
        // single-IP load-test environment can measure application latency
        // rather than the per-IP limiter (k6 runners share one IP; real
        // traffic is distributed). Defaults are the production values; load
        // tests that raise them MUST run against a dedicated test server —
        // see docs/LOAD_TEST_RUNBOOK.md.
        'api' => (int) env('API_RATE_LIMIT_API', 120),        // general authenticated, per minute
        'api_anon' => (int) env('API_RATE_LIMIT_API_ANON', 60), // anonymous discovery, per minute
        'api_auth' => 5,            // token issuance, per minute per IP
        'api_otp_request' => 1,     // OTP request, per minute per phone
        'api_otp_verify' => 5,      // OTP verify, per 5 minutes per phone
        'api_score' => 10,          // score submission, per minute per user
        'api_payment' => 5,         // payment creation, per minute per user
        'api_support' => 10,        // support writes, per minute per user
        'api_webhook' => 60,        // inbound webhooks, per minute per IP
    ],

];
