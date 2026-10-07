<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Gameberry session + anti-cheat policy (GAP-10 A8, tracker row 018/056)
    |--------------------------------------------------------------------------
    |
    | Thresholds for the Gameberry-side anti-cheat evaluation and for the
    | stale-session reconciliation command. All values are conservative
    | defaults: they are derived from observable, deterministic facts (action
    | timings, counts, ordering), never from heuristics that could accuse a
    | legitimate player. A flag is an observation handed to the human review
    | queue — it never moves money and never restricts an account by itself.
    |
    */

    'anti_cheat' => [
        // Evaluation is always computed; when disabled, callers receive an
        // "allow" decision and nothing is escalated to the incident queue.
        'enabled' => env('GAMEBERRY_ANTI_CHEAT_ENABLED', true),

        // Minimum plausible gap between two player actions, in milliseconds.
        // Anything faster is physically impossible to input by hand.
        'min_action_interval_ms' => (int) env('GAMEBERRY_MIN_ACTION_INTERVAL_MS', 120),

        // More actions than this inside any 60-second window is not humanly
        // plausible for this game's input model.
        'max_actions_per_minute' => (int) env('GAMEBERRY_MAX_ACTIONS_PER_MINUTE', 120),

        // Absolute ceiling for a single session.
        'max_actions_per_session' => (int) env('GAMEBERRY_MAX_ACTIONS_PER_SESSION', 2000),

        // A session longer than this is treated as an abandoned/never-closed
        // session rather than a real one.
        'max_session_minutes' => (int) env('GAMEBERRY_MAX_SESSION_MINUTES', 90),

        // Weighted score at which a session is flagged for review.
        'flag_score' => (int) env('GAMEBERRY_ANTI_CHEAT_FLAG_SCORE', 3),

        // Weighted score at which the action is refused outright.
        'block_score' => (int) env('GAMEBERRY_ANTI_CHEAT_BLOCK_SCORE', 6),
    ],

    'sessions' => [
        // A session still open after this many minutes is stale: the client
        // disconnected or the app crashed. `ffarena:gameberry:reconcile-sessions`
        // closes it without moving money.
        'stale_minutes' => (int) env('GAMEBERRY_SESSION_STALE_MINUTES', 180),

        // Upper bound on the sessions one reconciliation run will touch.
        'reconcile_batch' => (int) env('GAMEBERRY_SESSION_RECONCILE_BATCH', 200),
    ],

    'settlements' => [
        // Upper bound on the settlements one reconciliation run will dispatch.
        'reconcile_batch' => (int) env('GAMEBERRY_SETTLEMENT_RECONCILE_BATCH', 50),

        // Hard ceiling for a single run, so a bad argument on the command line
        // cannot dispatch an unbounded number of jobs.
        'reconcile_batch_max' => (int) env('GAMEBERRY_SETTLEMENT_RECONCILE_BATCH_MAX', 500),
    ],

];
