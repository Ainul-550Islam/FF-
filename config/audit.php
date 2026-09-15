<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Central audit log (Phase 13)
    |--------------------------------------------------------------------------
    |
    | The central audit trail is append-only and stores only whitelisted,
    | display-safe data. Every key listed in `redact_keys` is replaced with
    | "[redacted]" by AuditLogService before anything is persisted, and any
    | JSON payload larger than `max_payload_chars` is truncated to a preview.
    |
    */

    // Keys that must never be persisted, even when a caller passes them.
    // Includes credentials, payment/webhook secrets, device/IP pseudonyms
    // and personal identifiers (defence in depth on top of caller
    // whitelisting).
    'redact_keys' => [
        'password',
        'password_confirmation',
        'token',
        'api_token',
        'access_token',
        'refresh_token',
        'secret',
        'api_key',
        'apikey',
        'authorization',
        'cookie',
        'set_cookie',
        'remember_token',
        'card',
        'card_number',
        'cvv',
        'cvc',
        'otp',
        'pin',
        'session',
        'email',
        'phone',
        'game_uid',
        'ip',
        'ip_address',
        'ip_hash',
        'subnet_hash',
        'device',
        'device_id',
        'device_hash',
        'fingerprint',
        'user_agent',
        'screenshot',
        'evidence',
        'document',
        'photo',
        'trx_id',
        'idempotency_key',
    ],

    // Longest individual string value kept inside a payload.
    'max_value_chars' => 500,

    // Longest serialized JSON payload kept whole; larger payloads are
    // replaced with a truncated preview.
    'max_payload_chars' => 4000,

];
