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
