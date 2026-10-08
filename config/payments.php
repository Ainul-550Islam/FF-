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

    /*
    |--------------------------------------------------------------------------
    | Payout controls (GAP-10 A4)
    |--------------------------------------------------------------------------
    |
    | Defence in depth around admin money movement.
    |
    | `payout_dual_control_threshold_minor` is the smallest payout amount (in
    | minor units — poisha) for which maker-checker applies: the administrator
    | who approved a payout is not allowed to also disburse it. `0` (the safe
    | default) means every payout that went through the payout queue requires a
    | second administrator, whatever its size. Payouts that were created
    | already-approved as part of a batch prize-distribution approval are not
    | gated by this key — the distribution approval is a separate, separately
    | audited maker action (see PayoutService::queueMaker()).
    |
    | `payout_reference_max_length` bounds the reviewed external reference a
    | manual completion must carry, so an operator cannot paste an unbounded
    | blob into the audit trail. The payouts.provider_reference column stores
    | up to 255 characters (the 80-char limit applies to the payments table,
    | not payouts); the full reviewed value is always kept in the payout
    | event metadata as well.
    |
    */

    'payout_dual_control_threshold_minor' => (int) env('PAYOUT_DUAL_CONTROL_THRESHOLD_MINOR', 0),

    'payout_reference_max_length' => 255,

    /*
    |--------------------------------------------------------------------------
    | Ingress rate limits (GAP-10 A5)
    |--------------------------------------------------------------------------
    |
    | Provider webhooks and hosted-gateway returns are machine traffic, and
    | both are signature-verified — but "signature-verified" is not "safe to
    | leave unbounded": an unsigned flood still costs a signature comparison,
    | a database read and an audit row per request, and a shared secret makes
    | a brute-force attempt cheap to launch. These ceilings are generous on
    | purpose (a provider can burst after its own outage) and finite, and both
    | are keyed by provider + client IP so one noisy source cannot consume
    | another's budget.
    |
    | Set through the environment because the right ceiling depends on the
    | provider's retry policy; `0` disables the limit, which production
    | deploys must refuse — no automated check exists yet (GAP-R7:
    | docs/RUNTIME_EVIDENCE_RUNBOOK.md), so verify by hand before promoting.
    |
    */

    'rate_limits' => [

        // Legacy Phase 08 endpoint: POST /webhooks/payments/{provider}
        'webhook' => (int) env('PAYMENT_WEBHOOK_RATE_LIMIT', 240),

        // Hosted-gateway payer return: GET/POST /payments/callback/{provider}
        'callback' => (int) env('PAYMENT_CALLBACK_RATE_LIMIT', 120),

    ],

    /*
    |--------------------------------------------------------------------------
    | Hosted-gateway return hardening (AUDIT FIX-03)
    |--------------------------------------------------------------------------
    |
    | `require_state` (default true): reject payer returns that do not carry
    | a valid HMAC `state` token minted for the payment. Set to false ONLY if
    | a provider is proven to strip query parameters from the redirect URL —
    | the bypass is logged loudly and server-to-server verification still
    | applies.
    |
    */
    'callback' => [
        'require_state' => (bool) env('PAYMENTS_CALLBACK_REQUIRE_STATE', true),
    ],

];
