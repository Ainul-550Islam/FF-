<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Account ecosystem (Phase 14)
    |--------------------------------------------------------------------------
    |
    | Central, server-side configuration for the account/auth/profile system:
    | email verification, phone OTP, profile/username rules, session and
    | account-lifecycle policy. Everything here is enforced server-side —
    | clients can never self-declare verification or account state.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Email verification
    |--------------------------------------------------------------------------
    |
    | Verification URLs are server-generated (temporary signed routes) and
    | expire after `verify_link_minutes`. `resend_cooldown_seconds` throttles
    | verification-mail resends.
    |
    | `require_verified_email_for` lists action contexts (e.g. 'payment',
    | 'payout', 'registration') that additionally demand a verified email.
    | It defaults to an empty list so Phase 01–13 flows keep working exactly
    | as before; operators opt in per context.
    |
    */
    'verification' => [
        'verify_link_minutes' => 60,
        'resend_cooldown_seconds' => 60,
        'require_verified_email_for' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Phone OTP
    |--------------------------------------------------------------------------
    */
    'otp' => [
        // Validity of a code once issued.
        'expires_seconds' => 300,
        // Minimum delay between code requests for the same phone.
        'resend_cooldown_seconds' => 60,
        // Failed verify attempts allowed before the challenge is consumed.
        'max_attempts' => 5,
        // Code length (digits).
        'length' => 6,
    ],

    /*
    |--------------------------------------------------------------------------
    | Username / profile rules
    |--------------------------------------------------------------------------
    */
    'username' => [
        'min_length' => 3,
        'max_length' => 20,
        // Characters allowed in a username.
        'allowed_regex' => '/^[a-zA-Z0-9._-]+$/',
        // Names that are never available.
        'reserved' => [
            'admin', 'administrator', 'root', 'system', 'support', 'staff',
            'moderator', 'ffarena', 'official', 'bot',
        ],
        // Minimum days between username changes (rate limiting).
        'change_cooldown_days' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Privacy presets
    |--------------------------------------------------------------------------
    */
    'privacy' => [
        'public',      // anyone may see the public profile
        'registered',  // only signed-in users
        'private',     // only the owner (and staff)
    ],

    /*
    |--------------------------------------------------------------------------
    | Account lifecycle
    |--------------------------------------------------------------------------
    */
    'lifecycle' => [
        // Whether deletion is destructive. `false` keeps a tombstone
        // (anonymized) record because financial/audit history is immutable.
        'hard_delete' => false,
    ],

];
