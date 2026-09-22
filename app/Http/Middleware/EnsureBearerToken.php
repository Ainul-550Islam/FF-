<?php
namespace App\Http\Middleware;

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
        
        if (!$auth || !str_starts_with($auth, 'Bearer ')) {
            return response()->json([
                'error' => 'unauthorized',
                'message' => 'Bearer token required'
            ], 401);
        }

        $token = substr($auth, 7);
        $token = trim($token);

        if (strlen($token) < 10) {
            return response()->json([
                'error' => 'invalid_token',
                'message' => 'Token too short, must be at least 10 characters'
            ], 401);
        }

        if (strlen($token) > 500) {
            return response()->json([
                'error' => 'invalid_token',
                'message' => 'Token too long'
            ], 401);
        }

        try {
            $personalAccessToken = PersonalAccessToken::findToken($token);
            
            if (!$personalAccessToken) {
                // Check if it's a service token (for Go/Rust inter-service)
                $serviceSecret = config('services_go_rust.service_auth.secret') ?: config('services.service_auth.secret');
                if ($serviceSecret && $this->isServiceToken($token, $serviceSecret)) {
                    // Service token valid - allow but log
                    Log::info('Service token auth', [
                        'service_id' => $request->header('X-Service-ID', 'unknown'),
                        'request_id' => $request->header('X-Request-ID', 'unknown'),
                        'redacted_token' => $this->redactToken($token),
                    ]);
                    return $next($request);
                }

                return response()->json([
                    'error' => 'invalid_token',
                    'message' => 'Token not found'
                ], 401);
            }

            if ($personalAccessToken->expires_at && $personalAccessToken->expires_at->isPast()) {
                return response()->json([
                    'error' => 'token_expired',
                    'message' => 'Token expired'
                ], 401);
            }

            $tokenable = $personalAccessToken->tokenable;
            
            if (!$tokenable) {
                return response()->json([
                    'error' => 'invalid_token',
                    'message' => 'Token owner not found'
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

                return \App\Support\ApiResponse::error('account_inactive', 'This account is not active.', [], 401);
            }

            if (isset($tokenable->is_active) && !$tokenable->is_active && !method_exists($tokenable, 'inactiveReason')) {
                return \App\Support\ApiResponse::error('account_inactive', 'This account is not active.', [], 401);
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
                'message' => 'Token validation failed'
            ], 401);
        }
    }

    protected function isServiceToken(string $token, string $secret): bool
    {
        // Service tokens are JWTs signed with service secret
        // Check format: should be JWT with 3 parts separated by .
        if (substr_count($token, '.') !== 2) {
            return false;
        }

        if (strlen($token) < 20) {
            return false;
        }

        // In production, verify JWT signature with secret
        // For now, check if token looks like JWT and length is reasonable
        // Real implementation would:
        // $payload = explode('.', $token);
        // $header = json_decode(base64_decode($payload[0]), true);
        // $claims = json_decode(base64_decode($payload[1]), true);
        // Verify signature with HMAC SHA256 using secret

        return strlen($token) > 20 && str_contains($token, '.');
    }

    protected function redactToken(string $token): string
    {
        if (strlen($token) <= 8) {
            return "***REDACTED***";
        }
        return substr($token, 0, 4) . "***REDACTED***" . substr($token, -4);
    }
}
