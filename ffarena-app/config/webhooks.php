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
