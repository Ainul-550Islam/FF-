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
    | Payments (Phase 08 — AUDIT FIX 2026-10-08, GAPS-01)
    |--------------------------------------------------------------------------
    |
    | `webhook_secret` signs/verifies provider webhook callbacks
    | (HMAC-SHA256 over the raw body) and is the trust root for payment
    | settlement. It deliberately has NO committed default: the previous
    | fallback ('ffarena-local-webhook-secret') was published in this
    | repository, which let anyone who read the source forge a signed
    | webhook against any deployment that never rotated the value.
    |
    | Unset now means *disabled*:
    |   - PaymentService::verifySignature() fails closed on an empty secret.
    |   - WebhookIngressService refuses all inbound webhooks and logs a loud
    |     configuration warning.
    |   - HealthService::productionIssues() reports it as a critical finding.
    |
    | Generate a per-environment value with, e.g.:
    |   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    | and set it as PAYMENT_WEBHOOK_SECRET. Local development may pin any
    | string; a CHANGE_ME_* placeholder is accepted only outside production.
    |
    | `callback_secret` (AUDIT FIX, GAPS-01b) is the dedicated key for the
    | hosted-gateway redirect `state` token (PaymentCallbackState). It was
    | documented in .env.example and read by PaymentCallbackState but never
    | wired into config, so the documented override could not actually be
    | used. It now falls back through: callback secret → webhook secret →
    | APP_KEY-derived secret (still server-local, still unique per install).
    |
    */
    'payments' => [
        'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET'),
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
        'redirect' => env('GOOGLE_REDIRECT_URI', env('APP_URL', 'http://localhost').'/auth/google/callback'),
    ],

];
