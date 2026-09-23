<?php

use App\Models\MarketingLead;

/**
 * Phase 20 — marketing / acquisition infrastructure configuration.
 *
 * Everything here is opt-in by default: no third-party tracker is configured
 * and nothing loads until (a) credentials exist in the environment and
 * (b) the visitor granted consent for that category. First-party attribution
 * (UTM capture on our own domain) is the only always-on element because it is
 * required to measure the funnel at all; disable it with
 * MARKETING_FIRST_PARTY_ENABLED=false.
 */
return [

    // -----------------------------------------------------------------------
    // First-party acquisition measurement (attribution + conversion events).
    // -----------------------------------------------------------------------
    'first_party' => [
        'enabled' => env('MARKETING_FIRST_PARTY_ENABLED', true),

        // Anonymous visitor cookie — pseudonymous, first-party only.
        'cookie' => env('MARKETING_ANONYMOUS_COOKIE', 'ff_aid'),
        'cookie_minutes' => 60 * 24 * 365,
    ],

    // -----------------------------------------------------------------------
    // Conversion event pipeline.
    // -----------------------------------------------------------------------
    'events' => [
        'enabled' => env('MARKETING_EVENTS_ENABLED', true),

        // Client-postable event names (the §40 taxonomy). Anything else is
        // rejected with 422 — the funnel is a contract, not a free-form log.
        'allowed' => [
            'page_view', 'landing_view', 'campaign_view',
            'register_start', 'register_complete', 'login_complete',
            'organizer_cta_click', 'organizer_signup_complete',
            'player_cta_click', 'player_signup_complete',
            'tournament_list_view', 'tournament_view',
            'tournament_register_start', 'tournament_register_complete',
            'payment_start', 'payment_success', 'payment_failed',
            'share_click', 'share_complete', 'copy_link',
            'referral_click', 'referral_signup', 'referral_conversion',
            'app_download_click', 'store_click',
        ],

        // Maximum properties payload size per event.
        'max_properties' => 20,
    ],

    // -----------------------------------------------------------------------
    // Consent (marketing tracking decision — P0 before enabling ad tags).
    // -----------------------------------------------------------------------
    'consent' => [
        'enabled' => env('MARKETING_CONSENT_ENABLED', true),
        'cookie' => env('MARKETING_CONSENT_COOKIE', 'ff_consent'),
        'policy_version' => env('MARKETING_CONSENT_VERSION', '2026-09'),
        'cookie_minutes' => 60 * 24 * 180,
    ],

    // -----------------------------------------------------------------------
    // Third-party trackers. Empty = not configured = never emitted, even
    // with consent granted.
    // -----------------------------------------------------------------------
    'tracking' => [
        'ga4_id' => env('MARKETING_GA4_ID'),
        'gtm_id' => env('MARKETING_GTM_ID'),
        'meta_pixel_id' => env('MARKETING_META_PIXEL_ID'),
        'tiktok_pixel_id' => env('MARKETING_TIKTOK_PIXEL_ID'),
    ],

    // -----------------------------------------------------------------------
    // Social share cards.
    // -----------------------------------------------------------------------
    'og' => [
        // Default branded 1200x630 share card used by every page that does
        // not set its own og:image (public/img/og-default.png).
        'default_image' => env('MARKETING_OG_DEFAULT_IMAGE', 'img/og-default.png'),
        'default_image_alt' => 'FF Arena — Bangladesh Free Fire tournament platform',
        'twitter_site' => env('MARKETING_TWITTER_SITE'),
    ],

    // -----------------------------------------------------------------------
    // Leads / lifecycle.
    // -----------------------------------------------------------------------
    'contact_email' => env('MARKETING_CONTACT_EMAIL', 'support@ffarena.test'),

    'leads' => [
        'types' => [
            MarketingLead::TYPE_NEWSLETTER,
            MarketingLead::TYPE_ORGANIZER,
            MarketingLead::TYPE_CONTACT,
            MarketingLead::TYPE_PARTNER,
        ],
    ],

    // -----------------------------------------------------------------------
    // Phase 21 — affiliate program.
    // -----------------------------------------------------------------------
    'affiliate' => [
        // Length of auto-generated affiliate codes (A-Z0-9).
        'code_length' => 8,
    ],

    // -----------------------------------------------------------------------
    // Phase 21 — blog / SEO content engine.
    // -----------------------------------------------------------------------
    'blog' => [
        'per_page' => 9,
    ],

    // -----------------------------------------------------------------------
    // Phase 21 — push re-engagement.
    // -----------------------------------------------------------------------
    'push' => [
        // Revoke a subscription after this many consecutive delivery failures.
        'revoke_after_failures' => 5,
    ],
];
