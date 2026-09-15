<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mobile application (Phase 18)
    |--------------------------------------------------------------------------
    |
    | Server-side configuration for the native mobile client. Every value is
    | environment-driven; production credentials (FCM/APNs) are intentionally
    | absent by default so the app keeps working with push disabled honestly.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | App version policy
    |--------------------------------------------------------------------------
    |
    | The client compares its own version against min_supported_app_version
    | and may warn (but never hard-block) when out of date. latest_app_version
    | is informational only. update_required flips the server into a
    | mandatory-update state for all older clients (used only for breaking or
    | security-critical releases — never for every new release).
    |
    */
    'min_supported_app_version' => env('MOBILE_MIN_APP_VERSION', '1.0.0'),
    'latest_app_version' => env('MOBILE_LATEST_APP_VERSION', '1.0.0'),
    'update_required' => (bool) env('MOBILE_UPDATE_REQUIRED', false),

    /*
    |--------------------------------------------------------------------------
    | Maintenance mode (mobile)
    |--------------------------------------------------------------------------
    |
    | When set, the app meta endpoint advertises maintenance and the client
    | shows the message and blocks mutations. The server remains
    | authoritative; this flag is a communication channel, not an
    | enforcement mechanism.
    |
    */
    'maintenance' => (bool) env('MOBILE_MAINTENANCE_MODE', false),
    'maintenance_message' => (string) env('MOBILE_MAINTENANCE_MESSAGE', 'FF Arena is under maintenance. Please try again shortly.'),

    /*
    |--------------------------------------------------------------------------
    | Deep links
    |--------------------------------------------------------------------------
    */
    'deep_link_scheme' => env('MOBILE_DEEP_LINK_SCHEME', 'ffarena'),

    /*
    |--------------------------------------------------------------------------
    | Public URLs surfaced to the app (privacy policy, terms, support).
    |--------------------------------------------------------------------------
    */
    'support_url' => env('MOBILE_SUPPORT_URL', ''),
    'privacy_url' => env('MOBILE_PRIVACY_URL', ''),
    'terms_url' => env('MOBILE_TERMS_URL', ''),
    'release_notes_url' => env('MOBILE_RELEASE_NOTES_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Web / store links (App Links, Universal Links, update store page)
    |--------------------------------------------------------------------------
    |
    | web_base_url is the canonical public origin that hosts assetlinks.json
    | and apple-app-site-association for verified deep links. store_url is
    | where a user is sent when the app is too old (App Store / Play Store).
    |
    */
    'web_base_url' => env('MOBILE_WEB_BASE_URL', ''),
    'store_url' => env('MOBILE_STORE_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Push providers
    |--------------------------------------------------------------------------
    |
    | fcm_enabled / apns_enabled are honest capability flags the app reads to
    | decide whether to request a push token at all. They default to false;
    | nothing is faked when they are off. Credential values below are
    | server-side only and never shipped to the mobile app.
    |
    */
    'push' => [
        'fcm_enabled' => (bool) env('PUSH_FCM_ENABLED', false),
        'apns_enabled' => (bool) env('PUSH_APNS_ENABLED', false),
        'device_token_max_length' => 4096,

        // FCM HTTP v1 (service account). Exactly one of the key forms is
        // required; the JSON key file path is preferred in production.
        'fcm_project_id' => env('FCM_PROJECT_ID', ''),
        'fcm_client_email' => env('FCM_CLIENT_EMAIL', ''),
        'fcm_private_key' => env('FCM_PRIVATE_KEY', ''),
        'fcm_private_key_path' => env('FCM_PRIVATE_KEY_PATH', ''),

        // Direct APNs (token-based). Only used when apns_enabled is true.
        'apns_key_id' => env('APNS_KEY_ID', ''),
        'apns_team_id' => env('APNS_TEAM_ID', ''),
        'apns_bundle_id' => env('APNS_BUNDLE_ID', ''),
        'apns_private_key' => env('APNS_PRIVATE_KEY', ''),
        'apns_private_key_path' => env('APNS_PRIVATE_KEY_PATH', ''),
        'apns_sandbox' => (bool) env('APNS_SANDBOX', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Device registry
    |--------------------------------------------------------------------------
    */
    'devices' => [
        'token_hash_algo' => 'sha256',
        'max_devices_per_user' => 25,
    ],

];
