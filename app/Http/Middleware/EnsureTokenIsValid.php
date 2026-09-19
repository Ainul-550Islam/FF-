<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureTokenIsValid
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json([
                'error' => 'token_required',
                'message' => 'Bearer token required'
            ], 401);
        }

        if (strlen($token) < 10) {
            return response()->json([
                'error' => 'token_invalid',
                'message' => 'Token too short'
            ], 401);
        }

        if (strlen($token) > 500) {
            return response()->json([
                'error' => 'token_invalid',
                'message' => 'Token too long'
            ], 401);
        }

        try {
            $personalAccessToken = PersonalAccessToken::findToken($token);

            if (!$personalAccessToken) {
                Log::warning('Token not found', [
                    'redacted_token' => $this->redactToken($token),
                    'request_id' => $request->header('X-Request-ID', 'unknown'),
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'error' => 'token_invalid',
                    'message' => 'Token not found'
                ], 401);
            }

            if ($personalAccessToken->expires_at && $personalAccessToken->expires_at->isPast()) {
                Log::info('Token expired', [
                    'token_id' => $personalAccessToken->id,
                    'expires_at' => $personalAccessToken->expires_at,
                    'request_id' => $request->header('X-Request-ID', 'unknown'),
                ]);

                return response()->json([
                    'error' => 'token_expired',
                    'message' => 'Token expired'
                ], 401);
            }

            $tokenable = $personalAccessToken->tokenable;

            if (!$tokenable) {
                return response()->json([
                    'error' => 'token_invalid',
                    'message' => 'Token owner not found'
                ], 401);
            }

            if (method_exists($tokenable, 'isActive') && !$tokenable->isActive()) {
                Log::warning('Inactive account attempt', [
                    'user_id' => $tokenable->id ?? 'unknown',
                    'request_id' => $request->header('X-Request-ID', 'unknown'),
                ]);

                return response()->json([
                    'error' => 'account_inactive',
                    'message' => 'Account is inactive'
                ], 403);
            }

            if (isset($tokenable->is_active) && !$tokenable->is_active) {
                return response()->json([
                    'error' => 'account_inactive',
                    'message' => 'Account is inactive'
                ], 403);
            }

            // Check token abilities if needed
            // Example: $personalAccessToken->can('payment:create')

            Log::info('Token validation success', [
                'user_id' => $tokenable->id ?? 'unknown',
                'token_id' => $personalAccessToken->id,
                'request_id' => $request->header('X-Request-ID', 'unknown'),
            ]);

            return $next($request);
        } catch (\Exception $e) {
            Log::error('Token validation error', [
                'error' => $e->getMessage(),
                'redacted_token' => $this->redactToken($token),
                'request_id' => $request->header('X-Request-ID', 'unknown'),
            ]);

            return response()->json([
                'error' => 'token_invalid',
                'message' => 'Token validation failed'
            ], 401);
        }
    }

    protected function redactToken(string $token): string
    {
        if (strlen($token) <= 8) {
            return "***REDACTED***";
        }
        return substr($token, 0, 4) . "***REDACTED***" . substr($token, -4);
    }
}
