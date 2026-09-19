<?php

/**
 * Final Hardening — TLS / Trusted Proxies
 * Configurable via env, never hardcode production domain
 */
return [
    'proxies' => env('TRUSTED_PROXIES', null), // null, *, or comma-separated IPs
    'headers' => env('TRUSTED_PROXY_HEADERS', 'X_FORWARDED_FOR|X_FORWARDED_HOST|X_FORWARDED_PORT|X_FORWARDED_PROTO|X_FORWARDED_AWS_ELB'),
];
