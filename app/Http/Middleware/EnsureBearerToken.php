<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureBearerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $auth = $request->header('Authorization');

        if (! $auth || ! str_starts_with($auth, 'Bearer ')) {
            // No credential presented at all: this is the standard API
            // error envelope (error.code = 'unauthenticated'), matching what
            // the `auth:sanctum` guard emits for guests. The flat
            // {error: <code>} shapes below are the reserved contract for
            // MALFORMED/previously-issued credentials.
            // AUDIT FIX (2026-10-08, GAPS-24).
            return ApiResponse::error('unauthenticated', 'Authentication is required.', [], 401);
        }

        $token = substr($auth, 7);
        $token = trim($token);

        if (strlen($token) < 10) {
            return response()->json([
                'error' => 'invalid_token',
                'message' => 'Token too short, must be at least 10 characters',
            ], 401);
        }

        if (strlen($token) > 500) {
            return response()->json([
                'error' => 'invalid_token',
                'message' => 'Token too long',
            ], 401);
        }

        try {
            $personalAccessToken = PersonalAccessToken::findToken($token);

            if (! $personalAccessToken) {
                // AUDIT FIX (2026-10-07, FIX-05): REMOVED the "service token"
                // bypass. The old path called isServiceToken(), which accepted
                // ANY string shaped like a JWT (3 dot-separated parts) WITHOUT
                // verifying the HMAC signature against the service secret —
                // anyone could mint a fake "service" credential. Go/Rust
                // inter-service calls must use real Sanctum tokens issued to
                // a service user (or a dedicated HMAC guard on internal-only
                // routes), never this middleware. Fail closed:
                Log::warning('Bearer token not found', [
                    'redacted_token' => $this->redactToken($token),
                    'request_id' => $request->header('X-Request-ID', 'unknown'),
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'error' => 'invalid_token',
                    'message' => 'Token not found',
                ], 401);
            }

            if ($personalAccessToken->expires_at && $personalAccessToken->expires_at->isPast()) {
                return response()->json([
                    'error' => 'token_expired',
                    'message' => 'Token expired',
                ], 401);
            }

            // P2 (2026-10-07): global lifetime backstop — even a token with a
            // far-future expires_at dies `sanctum.expiration` minutes after
            // issuance. Checked here because this middleware resolves the user
            // before (and regardless of) the `auth:sanctum` guard.
            $maxLifetime = (int) config('sanctum.expiration', 0);

            if ($maxLifetime > 0 && $personalAccessToken->created_at !== null && $personalAccessToken->created_at->addMinutes($maxLifetime)->isPast()) {
                return response()->json([
                    'error' => 'token_expired',
                    'message' => 'Token exceeded the maximum lifetime',
                ], 401);
            }

            $tokenable = $personalAccessToken->tokenable;

            if (! $tokenable) {
                return response()->json([
                    'error' => 'invalid_token',
                    'message' => 'Token owner not found',
                ], 401);
            }

            // A deactivated, suspended, banned or deleted account cannot use a
            // bearer token: the credential is refused with the API's standard
            // error envelope (401 + error.code = account_inactive).
            if (method_exists($tokenable, 'inactiveReason') && ($reason = $tokenable->inactiveReason()) !== null) {
                Log::warning('Inactive account bearer attempt', [
                    'user_id' => $tokenable->id ?? 'unknown',
                    'reason' => $reason,
                    'request_id' => $request->header('X-Request-ID', 'unknown'),
                ]);

                return ApiResponse::error('account_inactive', 'This account is not active.', [], 401);
            }

            if (isset($tokenable->is_active) && ! $tokenable->is_active && ! method_exists($tokenable, 'inactiveReason')) {
                return ApiResponse::error('account_inactive', 'This account is not active.', [], 401);
            }

            // Resolve the user through Sanctum's guard so the authenticated
            // instance carries its access token (ability checks such as
            // `abilities:*` and token metadata rely on currentAccessToken()).
            // The validated tokenable remains the fallback for callers that
            // run outside the guard.
            $request->setUserResolver(function () use ($tokenable) {
                return auth('sanctum')->user() ?? $tokenable;
            });

            // Update last used at
            if (method_exists($personalAccessToken, 'forceFill')) {
                $personalAccessToken->forceFill([
                    'last_used_at' => now(),
                ])->save();
            }

            Log::info('Bearer token auth success', [
                'user_id' => $tokenable->id ?? 'unknown',
                'token_id' => $personalAccessToken->id,
                'request_id' => $request->header('X-Request-ID', 'unknown'),
                'redacted_token' => $this->redactToken($token),
            ]);

            return $next($request);
        } catch (\Exception $e) {
            Log::error('Bearer token validation error', [
                'error' => $e->getMessage(),
                'redacted_token' => $this->redactToken($token ?? ''),
                'request_id' => $request->header('X-Request-ID', 'unknown'),
            ]);

            return response()->json([
                'error' => 'invalid_token',
                'message' => 'Token validation failed',
            ], 401);
        }
    }

    protected function redactToken(string $token): string
    {
        if (strlen($token) <= 8) {
            return '***REDACTED***';
        }

        return substr($token, 0, 4).'***REDACTED***'.substr($token, -4);
    }
}
