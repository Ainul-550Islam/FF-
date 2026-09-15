<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 15 — keep the bearer-token mode strictly distinct from the
 * stateful/cookie mode.
 *
 * API requests MUST authenticate with a bearer token. A session cookie is
 * never accepted as an API credential: if no Authorization bearer token is
 * present the request is refused before the sanctum guard runs.
 */
class EnsureBearerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() === null) {
            return ApiResponse::error(
                'unauthenticated',
                'A bearer token is required.',
                [],
                401
            );
        }

        return $next($request);
    }
}
