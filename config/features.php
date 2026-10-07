<?php

return [
    'tournament_format_round_robin' => env('FEATURE_ROUND_ROBIN', false),
    'tournament_format_double_elimination' => env('FEATURE_DOUBLE_ELIMINATION', false),
    'tournament_format_swiss' => env('FEATURE_SWISS', false),
    'tournament_format_group_stage' => env('FEATURE_GROUP_STAGE', false),
    'tournament_format_league' => env('FEATURE_LEAGUE', false),
    'tournament_format_ffa' => env('FEATURE_FFA', false),
    'tournament_format_multi_stage' => env('FEATURE_MULTI_STAGE', false),
    'tournament_format_hybrid' => env('FEATURE_HYBRID', false),
    'payment_provider_bkash' => env('FEATURE_PAYMENT_BKASH', true),
    'payment_provider_nagad' => env('FEATURE_PAYMENT_NAGAD', false),
    'payment_provider_rocket' => env('FEATURE_PAYMENT_ROCKET', false),
    'payout_provider_bkash' => env('FEATURE_PAYOUT_BKASH', false),
    'payout_provider_bank' => env('FEATURE_PAYOUT_BANK', true),
    'realtime_sse' => env('FEATURE_REALTIME_SSE', true),
    'realtime_reverb' => env('FEATURE_REALTIME_REVERB', false),
    'realtime_polling' => env('FEATURE_REALTIME_POLLING', true),
    'fraud_device_intelligence' => env('FEATURE_FRAUD_DEVICE', true),
    'fraud_ip_intelligence' => env('FEATURE_FRAUD_IP', true),
    'fraud_external_intelligence' => env('FEATURE_FRAUD_EXTERNAL', false),
    'fraud_identity_intelligence' => env('FEATURE_FRAUD_IDENTITY', true),
    'notification_sms' => env('FEATURE_NOTIFICATION_SMS', false),
    'notification_push' => env('FEATURE_NOTIFICATION_PUSH', true),
    'notification_email' => env('FEATURE_NOTIFICATION_EMAIL', true),
    'storage_s3' => env('FEATURE_STORAGE_S3', false),
    'storage_local' => env('FEATURE_STORAGE_LOCAL', true),
    'mobile_push' => env('FEATURE_MOBILE_PUSH', true),
    'scoring_custom_rules' => env('FEATURE_SCORING_CUSTOM', false),
    'scoring_tiebreaker' => env('FEATURE_SCORING_TIEBREAKER', true),
    'admin_beta' => env('FEATURE_ADMIN_BETA', false),
    'api_v2' => env('FEATURE_API_V2', false),
    'observability_prometheus' => env('FEATURE_PROMETHEUS', false),

    /*
    |--------------------------------------------------------------------------
    | Numbered simulation routes (GAP-10 A3, Option B)
    |--------------------------------------------------------------------------
    |
    | The template-generated `core`, `final*` and `stats` Gameberry families
    | (routes/gameberry_numbered.php, routes/api_gameberry_numbered.php) are
    | development-only simulations: near-identical clones, some of which move
    | virtual gold/gems. They are hidden behind this flag and are additionally
    | gated on the local/testing environment in bootstrap/app.php, so they can
    | never be reached in production even if the flag is set by mistake.
    |
    | Default: false (fail closed). Set GAMEBERRY_NUMBERED_SIMULATIONS=true in
    | a local/testing environment only. Full removal is tracked in
    | FILE_AUDIT.md / docs/GAP-10-FINDINGS-REGISTER.md (A3).
    |
    */
    'gameberry_numbered_simulations' => env('GAMEBERRY_NUMBERED_SIMULATIONS', false),
];
