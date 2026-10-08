<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AUDIT FIX (2026-10-07, FIX-15): normalize the legacy Gameberry response
 * envelope to the platform-standard API contract.
 *
 * The ~20 Gameberry API controllers return a bespoke shape:
 *   success → {"success": true,  "data": ..., <extras...>}
 *   failure → {"success": false, "error": "plain string message"}
 *
 * while the rest of /api/v1 (and the mobile client + OpenAPI spec) uses:
 *   success → {"data": ..., "meta": {...}}
 *   failure → {"error": {"code": "...", "message": "...", "details": {...}}}
 *
 * Consequences of the mismatch: the mobile `ApiException.fromBody()` parser
 * receives `error` as a string instead of an object, so EVERY Gameberry
 * failure degrades to `code: unknown` and session-terminating signals are
 * lost. Error contracts are also untestable per-endpoint.
 *
 * Rather than rewriting 20+ controllers by hand (high regression risk), this
 * terminating middleware translates the legacy shape at the boundary:
 *  - `{"success": true, "data": X, ...extras}` → `{"data": X, "meta": extras}`
 *  - `{"success": false, "error": "msg"}`      → `{"error": {"code", "message"}}`
 * Responses that already use the standard envelope pass through untouched,
 * and HTTP status codes are always preserved.
 */
class NormalizeGameberryEnvelope
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse) {
            return $response;
        }

        $payload = json_decode((string) $response->getContent(), true);

        if (! is_array($payload) || ! array_key_exists('success', $payload)) {
            return $response;
        }

        $status = $response->getStatusCode();

        if ($payload['success'] === true) {
            $data = $payload['data'] ?? null;
            unset($payload['success'], $payload['data']);

            $normalized = ['data' => $data];

            if ($payload !== []) {
                $normalized['meta'] = $payload;
            }

            return response()->json($normalized, $status, $response->headers->all());
        }

        $message = $payload['error'] ?? 'Request failed.';
        $message = is_string($message) && $message !== '' ? $message : 'Request failed.';

        return response()->json([
            'error' => [
                'code' => $this->codeFor($status),
                'message' => $message,
            ],
        ], $status, $response->headers->all());
    }

    protected function codeFor(int $status): string
    {
        return match (true) {
            $status === 401 => 'unauthenticated',
            $status === 403 => 'forbidden',
            $status === 404 => 'not_found',
            $status === 409 => 'conflict',
            $status === 422 => 'validation_error',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'server_error',
            default => 'request_failed',
        };
    }
}
