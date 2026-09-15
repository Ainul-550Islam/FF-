<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Phase 15 — consistent API JSON envelopes.
 *
 * Success:
 *   { "data": ..., "meta": {...} }
 *
 * Errors (rendered by ApiExceptionHandler):
 *   { "error": { "code": "...", "message": "...", "details": {...} } }
 *
 * `data()` and `collection()` accept any JSON-serializable value (arrays,
 * ApiResources, paginator arrays). Raw Eloquent models are never returned.
 */
class ApiResponse
{
    public static function data(mixed $data, array $meta = [], int $status = 200): JsonResponse
    {
        $payload = ['data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    public static function created(mixed $data, array $meta = []): JsonResponse
    {
        return self::data($data, $meta, 201);
    }

    public static function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    public static function error(string $code, string $message, array $details = [], int $status = 400): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return response()->json(['error' => $error], $status);
    }
}
