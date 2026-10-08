<?php

namespace App\Http\Middleware;

use App\Services\Integration\ServiceAuthenticator;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * P2 (2026-10-07) — dedicated service-to-service HMAC guard for Go/Rust
 * inbound calls. Services authenticate with request signatures, never with
 * user bearer tokens (see EnsureBearerToken FIX-05).
 *
 * Header contract (mirrors ServiceAuthenticator::signRequest):
 *   X-Service-ID  one of the configured service ids
 *                 (services_go_rust.{go_payment,rust_security}.service_id)
 *   X-Timestamp   unix seconds; must be within
 *                 services_go_rust.service_auth.timestamp_tolerance_seconds
 *   X-Nonce       unique per request; replays are rejected via the cache
 *   X-Signature   hex HMAC-SHA256 over "METHOD:path:raw-body:timestamp:nonce"
 *                 keyed with the service's hmac_secret, where path is the
 *                 request path with a leading slash ("/api/v1/...").
 *
 * Fails closed: unknown service, missing/weak secret, stale timestamp,
 * replayed nonce or bad signature all answer 401 and never reach the route.
 * On success the service id is available as
 * $request->attributes->get('service_id').
 */
class EnsureServiceHmac
{
    public function handle(Request $request, Closure $next): Response
    {
        $serviceId = trim((string) $request->header('X-Service-ID', ''));

        $configKey = $this->configKeyFor($serviceId);

        if ($configKey === null) {
            return $this->reject($request, 'unknown_service', 'Unknown service id.');
        }

        $secret = (string) config("services_go_rust.{$configKey}.hmac_secret", '');

        if ($secret === '' || str_starts_with($secret, 'CHANGE_ME_')) {
            Log::warning('Service HMAC secret not configured', [
                'service_id' => $serviceId,
                'request_id' => $request->header('X-Request-ID', 'unknown'),
            ]);

            return $this->reject($request, 'service_not_configured', 'Service authentication is not configured.');
        }

        $timestampHeader = trim((string) $request->header('X-Timestamp', ''));
        $nonce = trim((string) $request->header('X-Nonce', ''));
        $signature = trim((string) $request->header('X-Signature', ''));

        if ($timestampHeader === '' || $nonce === '' || $signature === '') {
            return $this->reject($request, 'missing_signature', 'X-Timestamp, X-Nonce and X-Signature are required.');
        }

        if (! ctype_digit($timestampHeader)) {
            return $this->reject($request, 'invalid_timestamp', 'X-Timestamp must be unix seconds.');
        }

        $timestamp = (int) $timestampHeader;
        $tolerance = (int) config('services_go_rust.service_auth.timestamp_tolerance_seconds', 300);

        if ($tolerance < 1) {
            $tolerance = 300;
        }

        $path = '/'.ltrim($request->path(), '/');
        $body = (string) $request->getContent();

        if (! ServiceAuthenticator::verifyRequest($secret, $request->method(), $path, $body, $signature, $timestamp, $nonce, $tolerance)) {
            return $this->reject($request, 'invalid_signature', 'Service signature verification failed.');
        }

        // Replay protection: each nonce is usable once within the window.
        $nonceKey = 'service-hmac-nonce:'.hash('sha256', $serviceId.':'.$nonce);

        if (! Cache::add($nonceKey, 1, $tolerance)) {
            return $this->reject($request, 'replayed_request', 'This signed request was already used.');
        }

        $request->attributes->set('service_id', $serviceId);

        Log::info('Service HMAC auth success', [
            'service_id' => $serviceId,
            'request_id' => $request->header('X-Request-ID', 'unknown'),
        ]);

        return $next($request);
    }

    /**
     * Map a presented service id to its services_go_rust config section.
     */
    protected function configKeyFor(string $serviceId): ?string
    {
        if ($serviceId === '') {
            return null;
        }

        $known = [
            (string) config('services_go_rust.go_payment.service_id', 'payment-gateway-go') => 'go_payment',
            (string) config('services_go_rust.rust_security.service_id', 'security-rust') => 'rust_security',
        ];

        return $known[$serviceId] ?? null;
    }

    protected function reject(Request $request, string $code, string $message): Response
    {
        Log::warning('Service HMAC auth rejected', [
            'code' => $code,
            'service_id' => (string) $request->header('X-Service-ID', 'unknown'),
            'request_id' => $request->header('X-Request-ID', 'unknown'),
            'ip' => $request->ip(),
        ]);

        return response()->json(['error' => $code, 'message' => $message], 401);
    }
}
