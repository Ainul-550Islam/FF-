<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Phase 16 — per-request correlation context.
 *
 * A tiny process-lifetime holder populated by the request-correlation
 * middleware and read by the structured-logging processor, the error
 * reporter, and the metrics pipeline. Only ever contains safe values:
 * the correlation id, the HTTP method/path, and numeric user/token ids —
 * never secrets, raw IPs, or device fingerprints.
 *
 * In console contexts (queue workers, scheduler) the values are simply null
 * and the consumers degrade gracefully.
 */
final class RequestContext
{
    private static ?string $requestId = null;

    private static ?string $method = null;

    private static ?string $path = null;

    private static ?string $route = null;

    private static ?int $userId = null;

    private static ?int $tokenId = null;

    private static ?float $startedAt = null;

    public static function start(Request $request, string $requestId): void
    {
        self::$requestId = $requestId;
        self::$method = $request->getMethod();
        self::$path = $request->path();
        self::$route = null;
        self::$userId = null;
        self::$tokenId = null;
        self::$startedAt = microtime(true);
    }

    public static function requestId(): ?string
    {
        return self::$requestId;
    }

    public static function setRoute(?string $route): void
    {
        self::$route = $route;
    }

    public static function setAuth(?int $userId, ?int $tokenId): void
    {
        self::$userId = $userId;
        self::$tokenId = $tokenId;
    }

    public static function startedAt(): ?float
    {
        return self::$startedAt;
    }

    /**
     * Milliseconds elapsed since the request started (null in console).
     */
    public static function elapsedMs(): ?float
    {
        return self::$startedAt === null ? null : (microtime(true) - self::$startedAt) * 1000;
    }

    /**
     * The safe context snapshot attached to structured logs, error reports
     * and metric lines. Deliberately excludes anything sensitive.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(): array
    {
        $snapshot = [
            'request_id' => self::$requestId,
            'method' => self::$method,
            'path' => self::$path,
            'route' => self::$route,
        ];

        if (self::$userId !== null) {
            $snapshot['user_id'] = self::$userId;
        }

        if (self::$tokenId !== null) {
            $snapshot['token_id'] = self::$tokenId;
        }

        return $snapshot;
    }

    /**
     * Reset for the next request (used by the middleware and, defensively, by
     * tests that simulate several requests in one process).
     */
    public static function flush(): void
    {
        self::$requestId = null;
        self::$method = null;
        self::$path = null;
        self::$route = null;
        self::$userId = null;
        self::$tokenId = null;
        self::$startedAt = null;
    }
}
