<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 15 — post-authentication token sanity checks.
 *
 * After `auth:sanctum` has resolved the bearer token, reject the request
 * when:
 *   - the token has expired,
 *   - the owning API client has been revoked,
 *   - the account is deactivated or pending deletion.
 *
 * No internal detail (token id, expiry, client) is leaked — the response is
 * always a generic 401.
 */
class EnsureTokenIsValid
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return ApiResponse::error('unauthenticated', 'Authentication is required.', [], 401);
        }

        // Deactivated / deleted accounts must not authenticate.
        if (! $user->isActive()) {
            return ApiResponse::error(
                'account_inactive',
                'This account is not active.',
                [],
                401
            );
        }

        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            // Expired tokens are rejected even if Sanctum has not pruned them.
            if ($token->expires_at !== null && $token->expires_at->isPast()) {
                $token->delete();

                return ApiResponse::error('token_expired', 'This token has expired.', [], 401);
            }

            // A token minted for a revoked application stops working.
            if ($token->api_client_id !== null && $token->client !== null && ! $token->client->isActive()) {
                return ApiResponse::error('token_revoked', 'This token belongs to a revoked application.', [], 401);
            }
        }

        return $next($request);
    }
}
