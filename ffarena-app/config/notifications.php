<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Notifications (Phase 11)
    |--------------------------------------------------------------------------
    |
    | In-app notifications are always written (the primary, always-available
    | channel). Email is an optional, best-effort secondary channel: it is
    | disabled when NOTIFICATIONS_EMAIL=false, and a failure to deliver email
    | never fails the originating action.
    |
    */

    // Send a best-effort email alongside every in-app notification.
    'email_enabled' => (bool) env('NOTIFICATIONS_EMAIL', true),

    // Default page size for the notification inbox.
    'per_page' => 20,

];
