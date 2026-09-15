<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Http;

/**
 * Phase 19 — Firebase Cloud Messaging (HTTP v1) transport.
 *
 * Production credential model (server-side only — the Flutter app ships NO
 * Firebase secret):
 *
 *   FCM_SERVICE_ACCOUNT_PATH  -> path to the service-account JSON file, or
 *   FCM_PROJECT_ID            -> the GCP project id,
 *   FCM_CLIENT_EMAIL          -> service account client_email,
 *   FCM_PRIVATE_KEY           -> service account private key (escaped \n)
 *
 * When no credentials are present, `isConfigured()` is false and the
 * dispatcher skips this transport — delivery is never faked. Device tokens
 * are passed in-memory only; they are never logged, never persisted here and
 * never echoed back to clients.
 */
final class FcmTransport implements PushTransport
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** Token-issuer cache to avoid re-fetching OAuth per device. */
    private ?string $cachedAccessToken = null;

    private int $cachedAccessTokenExpiresAt = 0;

    public function provider(): string
    {
        return 'fcm';
    }

    public function isConfigured(): bool
    {
        if (! (bool) config('mobile.push.fcm_enabled', false)) {
            return false;
        }

        return $this->credentials() !== null;
    }

    public function send(PushMessage $message, string $token): PushResult
    {
        $credentials = $this->credentials();
        if ($credentials === null) {
            return PushResult::failed('fcm_not_configured');
        }

        try {
            $accessToken = $this->accessToken($credentials);
            if ($accessToken === null) {
                return PushResult::failed('fcm_oauth_failed', true);
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$accessToken,
                'Content-Type' => 'application/json',
            ])->post(
                'https://fcm.googleapis.com/v1/projects/'.$credentials['project_id'].'/messages:send',
                ['message' => $this->messageBody($message, $token)],
            );

            if ($response->successful()) {
                return PushResult::delivered();
            }

            return $this->mapFailure($response->status(), $response->json());
        } catch (\Throwable $e) {
            // The token and payload are deliberately absent from the report.
            report($e);

            return PushResult::failed('fcm_transport_error', true);
        }
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @return array{project_id:string,client_email:string,private_key:string}|null
     */
    private function credentials(): ?array
    {
        $projectId = trim((string) config('mobile.push.fcm_project_id', ''));
        $clientEmail = trim((string) config('mobile.push.fcm_client_email', ''));
        $privateKey = $this->privateKey();

        if ($projectId === '' || $clientEmail === '' || $privateKey === null) {
            return null;
        }

        return [
            'project_id' => $projectId,
            'client_email' => $clientEmail,
            'private_key' => $privateKey,
        ];
    }

    private function privateKey(): ?string
    {
        $path = trim((string) config('mobile.push.fcm_private_key_path', ''));
        if ($path !== '' && is_readable($path)) {
            $contents = @file_get_contents($path);

            return $contents === false ? null : $contents;
        }

        $inline = trim((string) config('mobile.push.fcm_private_key', ''));
        if ($inline !== '') {
            return str_replace('\\n', "\n", $inline);
        }

        return null;
    }

    private function accessToken(array $credentials): ?string
    {
        $now = time();

        if ($this->cachedAccessToken !== null && $this->cachedAccessTokenExpiresAt > $now + 60) {
            return $this->cachedAccessToken;
        }

        $header = PushJwt::encodeSegment(['alg' => 'RS256', 'typ' => 'JWT']);
        $claims = PushJwt::encodeSegment([
            'iss' => $credentials['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ]);

        $unsigned = $header.'.'.$claims;
        $signature = PushJwt::signRs256($unsigned, $credentials['private_key']);
        if ($signature === '') {
            return null;
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $unsigned.'.'.$signature,
        ]);

        if (! $response->successful()) {
            return null;
        }

        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            return null;
        }

        $this->cachedAccessToken = $token;
        $this->cachedAccessTokenExpiresAt = $now + 3600;

        return $token;
    }

    private function messageBody(PushMessage $message, string $token): array
    {
        return [
            'token' => $token,
            'notification' => [
                'title' => $message->title,
                'body' => $message->body,
            ],
            'data' => $this->stringify($message->data),
            'android' => [
                'priority' => $message->priority === 'high' ? 'HIGH' : 'NORMAL',
            ],
            'apns' => [
                'headers' => [
                    'apns-priority' => $message->priority === 'high' ? '10' : '5',
                ],
                'payload' => [
                    'aps' => ['sound' => 'default'],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function stringify(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $out[(string) $key] = is_scalar($value) || $value === null
                ? (string) $value
                : (string) json_encode($value);
        }

        return $out;
    }

    private function mapFailure(int $status, ?array $json): PushResult
    {
        $errorCode = $this->extractErrorCode($json);

        // A token the provider no longer knows is dead — deactivate it.
        if (in_array($errorCode, ['UNREGISTERED', 'NOT_FOUND', 'INVALID_ARGUMENT'], true)) {
            return PushResult::invalidToken(strtolower($errorCode ?? 'unknown'));
        }

        if ($status === 401 || $status === 403) {
            return PushResult::failed('fcm_authentication_failed', true);
        }

        if ($status === 429 || $status >= 500) {
            return PushResult::failed('fcm_unavailable', true);
        }

        return PushResult::failed('fcm_rejected');
    }

    private function extractErrorCode(?array $json): ?string
    {
        if (! is_array($json)) {
            return null;
        }

        $details = $json['error']['details'] ?? null;
        if (is_array($details)) {
            foreach ($details as $detail) {
                if (is_array($detail) && isset($detail['errorCode'])) {
                    return (string) $detail['errorCode'];
                }
            }
        }

        $status = $json['error']['status'] ?? null;

        return is_string($status) ? $status : null;
    }
}
