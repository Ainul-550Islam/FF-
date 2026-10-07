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
    | Business signature (GAP-10 A5)
    |--------------------------------------------------------------------------
    |
    | The inbound layer above verifies the PROVIDER's transport signature. The
    | payment state machine then re-verifies the same body with a second,
    | independent secret — the "business" signature — so the two checks cannot
    | be defeated by compromising one secret, and so a gateway that signs with
    | a key we do not control still cannot reach `PaymentService`.
    |
    | Until GAP-10 that second layer used one shared secret
    | (`services.payments.webhook_secret`) with no timestamp. It now accepts a
    | per-provider secret and an optional signed timestamp with a tolerance
    | window, while staying backwards compatible: a provider that never sends a
    | timestamp keeps working through the legacy raw-body scheme, and replay
    | protection for those senders rests on the event-id idempotency in the
    | ingress layer.
    |
    | A provider with no entry here falls back to the Phase 08 shared secret, so
    | an existing deployment does not have to change anything to keep working.
    |
    */
    'business' => [
        // Per-provider secrets for the business layer. Never committed: set
        // them through the environment (or a secret manager).
        'providers' => [
            'bkash' => env('PAYMENT_BUSINESS_BKASH_SECRET'),
            'nagad' => env('PAYMENT_BUSINESS_NAGAD_SECRET'),
            'rocket' => env('PAYMENT_BUSINESS_ROCKET_SECRET'),
            'sslcommerz' => env('PAYMENT_BUSINESS_SSLCOMMERZ_SECRET'),
            'card' => env('PAYMENT_BUSINESS_CARD_SECRET'),
        ],

        // Maximum age of a timestamped business signature (seconds). A supplied
        // timestamp that is older (or in the future beyond this window) is
        // refused; an absent timestamp keeps the legacy behaviour.
        'timestamp_tolerance' => (int) env('PAYMENT_BUSINESS_TIMESTAMP_TOLERANCE', 300),
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
