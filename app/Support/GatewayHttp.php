<?php

namespace App\Support;

use DomainException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Phase 20/G2 — shared outbound HTTP foundation for payment-gateway API
 * clients (bKash, Nagad, SSLCommerz, …).
 *
 * Every gateway client routes through here so the platform shares one
 * transport policy:
 *
 *   - explicit connect + request timeouts — a hanging gateway must not hang a
 *     worker;
 *   - retries for idempotent GET requests only — a POST that times out may
 *     already have been executed server-side and must never be auto-repeated
 *     (callers reconcile via the gateway's query/status API instead);
 *   - JSON decoding with secret-free, log-safe error messages.
 */
class GatewayHttp
{
    /**
     * Base HTTP client for gateway API calls.
     */
    public static function client(int $timeoutSeconds = 10): PendingRequest
    {
        return Http::timeout($timeoutSeconds)
            ->connectTimeout(5)
            ->acceptJson()
            ->retry(1, 250, fn (Throwable $e, PendingRequest $request) => self::retryable($request));
    }

    /**
     * Retry only transient transport failures on idempotent methods.
     */
    public static function retryable(PendingRequest $request): bool
    {
        return strtoupper($request->toPsrRequest()->getMethod()) === 'GET';
    }

    /**
     * Decode a gateway response. Throws a descriptive, secret-free
     * DomainException on transport errors, non-2xx statuses or invalid JSON.
     *
     * @return array<string, mixed>
     */
    public static function json(Response $response, string $context): array
    {
        if ($response->failed()) {
            throw new DomainException(sprintf(
                '%s failed with HTTP %d (%s).',
                $context,
                $response->status(),
                self::truncate((string) $response->body()),
            ));
        }

        $decoded = json_decode((string) $response->body(), true);

        if (! is_array($decoded)) {
            throw new DomainException("{$context} returned an invalid JSON response.");
        }

        return $decoded;
    }

    /**
     * Mask a secret so it can never leak into logs, exceptions or tests.
     */
    public static function mask(?string $secret): string
    {
        if ($secret === null || $secret === '') {
            return '';
        }

        if (strlen($secret) <= 4) {
            return '••••';
        }

        return substr($secret, 0, 2).'••••'.substr($secret, -2);
    }

    protected static function truncate(string $body, int $max = 300): string
    {
        $body = trim((string) preg_replace('/\s+/', ' ', $body));

        return mb_strlen($body) > $max ? mb_substr($body, 0, $max).'…' : $body;
    }
}
