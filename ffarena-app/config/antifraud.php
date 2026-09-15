<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Anti-fraud / trust & safety (Phase 10)
    |--------------------------------------------------------------------------
    |
    | Server-side configuration for the defensive fraud & anti-cheat layer.
    | Every threshold below is a risk *signal*, never an automatic conviction:
    | strong signals raise risk and (at most) require manual review. Nothing
    | here ever permanently bans an account on its own — restrictions are
    | granular, auditable and always revocable by an admin.
    |
    */

    'risk' => [
        // Deterministic level bands by score.
        'thresholds' => [
            'medium' => 30,
            'high' => 60,
            'critical' => 90,
        ],

        // Default action per risk level. Actions:
        //   allow         — proceed silently
        //   flag          — proceed + record an informational audit signal
        //   require_review— proceed + flag the account for manual review
        //   restrict      — block the action (server-enforced)
        'actions' => [
            'low' => 'allow',
            'medium' => 'flag',
            'high' => 'require_review',
            'critical' => 'restrict',
        ],

        // Score contribution per event severity (score is capped at 100).
        'severity_scores' => [
            'info' => 0,
            'low' => 5,
            'medium' => 15,
            'high' => 30,
            'critical' => 50,
        ],
    ],

    'device' => [
        // Distinct accounts on one device before a shared-device signal.
        'max_accounts_shared' => 4,
        // Distinct accounts on one device before a strong signal.
        'strong_accounts_shared' => 8,
    ],

    'ip' => [
        // Shared networks (NAT, carriers, cafés) legitimately host many
        // users — a high tolerance avoids false positives.
        'max_accounts_shared' => 20,
    ],

    'payment' => [
        // Failed payments on an account before a repeat-failure signal.
        'failed_attempts_threshold' => 3,
        // Payment intents created by an account before a churn signal.
        'attempts_threshold' => 10,
    ],

    'registration' => [
        // Team registrations by one account before a volume signal.
        'max_teams' => 5,
    ],

    'payout' => [
        // Actions by recipient risk level when a payout is processed.
        // 'require_review' and 'restrict' both hold the payout pending an
        // authorized override; the difference is only severity of the flag.
        'actions' => [
            'low' => 'allow',
            'medium' => 'allow',
            'high' => 'require_review',
            'critical' => 'restrict',
        ],
        // Completed payouts by one recipient before a repeat-win signal.
        'repeat_wins_threshold' => 3,
    ],

    'dispute' => [
        // Disputes opened by one account before an abuse signal.
        'repeat_threshold' => 3,
    ],

    'withdrawal' => [
        // Team withdrawals by one account before a churn signal.
        'repeat_threshold' => 3,
    ],

    'ban_evasion' => [
        // The minimum account-link strength that triggers a ban-evasion
        // review signal for a previously restricted account.
        'min_strength' => 'strong',
        // Automated action on a strong ban-evasion match. Never a
        // permanent auto-ban: require_review is the strongest default.
        'auto_action' => 'require_review',
    ],

    'anomaly' => [
        // Kills in a single match above this are statistically anomalous.
        'max_kills_per_match' => 60,
        // Identical (kills, placement) submissions by one team before a
        // repeated-pattern anomaly.
        'repeat_pattern_threshold' => 3,
    ],
];
