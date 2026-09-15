<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Payments (Phase 08)
    |--------------------------------------------------------------------------
    */
    'payments' => [
        // Secret used to sign/verify provider webhook callbacks (HMAC-SHA256).
        'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET', 'ffarena-local-webhook-secret'),
        // Secret used to sign the hosted-gateway redirect `state` token
        // (HMAC-SHA256). Falls back to webhook_secret, then APP_KEY-derived.
        'callback_secret' => env('PAYMENT_CALLBACK_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google OAuth / OpenID Connect (Phase 14)
    |--------------------------------------------------------------------------
    |
    | Server-side OAuth 2.0 / OIDC via Laravel Socialite. The client secret
    | is read from the environment only. When the credentials are absent the
    | provider reports "not configured" and no redirect is issued.
    |
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', env('APP_URL', 'http://localhost') . '/auth/google/callback'),
    ],

];
