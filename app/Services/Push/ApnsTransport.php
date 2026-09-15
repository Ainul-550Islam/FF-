<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Http;

/**
 * Phase 19 — Apple Push Notification service (token-based) transport.
 *
 * Production credential model (server-side only):
 *
 *   APNS_KEY_ID        -> the .p8 key id,
 *   APNS_TEAM_ID       -> the developer team id,
 *   APNS_BUNDLE_ID     -> the app bundle id (also the default apns-topic),
 *   APNS_PRIVATE_KEY   -> the .p8 private key (escaped \n), or
 *   APNS_PRIVATE_KEY_PATH -> path to the .p8 file
 *   APNS_SANDBOX       -> true to use the sandbox gateway
 *
 * FCM remains the preferred cross-platform path; this transport exists for
 * deployments that must talk to APNs directly. It is credential-driven and
 * reports `isConfigured() === false` without a key — never faked.
 */
final class ApnsTransport implements PushTransport
{
    private const ALGORITHM = 'ES256';

    public function provider(): string
    {
        return 'apns';
    }

    public function isConfigured(): bool
    {
        if (! (bool) config('mobile.push.apns_enabled', false)) {
            return false;
        }

        return $this->credentials() !== null;
    }

    public function send(PushMessage $message, string $token): PushResult
    {
        $credentials = $this->credentials();
        if ($credentials === null) {
            return PushResult::failed('apns_not_configured');
        }

        try {
            $jwt = $this->providerToken($credentials);
            if ($jwt === null) {
                return PushResult::failed('apns_auth_failed', true);
            }

            $host = $credentials['sandbox'] ? 'api.sandbox.push.apple.com' : 'api.push.apple.com';

            $response = Http::withHeaders([
                'authorization' => 'bearer '.$jwt,
                'apns-topic' => $credentials['topic'],
                'apns-push-type' => 'alert',
                'apns-priority' => $message->priority === 'high' ? '10' : '5',
            ])->withOptions(['version' => '2.0']) // APNs requires HTTP/2
                ->post('https://'.$host.'/3/device/'.$token, $this->payload($message));

            if ($response->successful()) {
                return PushResult::delivered();
            }

            $status = $response->status();
            $reason = (string) ($response->json('reason') ?? 'unknown');

            if ($status === 410 || in_array($reason, ['BadDeviceToken', 'DeviceTokenNotForTopic', 'Unregistered'], true)) {
                return PushResult::invalidToken($reason);
            }

            if ($status === 429 || $status >= 500) {
                return PushResult::failed('apns_unavailable', true);
            }

            return PushResult::failed('apns_rejected: '.$reason);
        } catch (\Throwable $e) {
            // Token and payload are deliberately absent from the report.
            report($e);

            return PushResult::failed('apns_transport_error', true);
        }
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @return array{key_id:string,team_id:string,topic:string,private_key:string,sandbox:bool}|null
     */
    private function credentials(): ?array
    {
        $keyId = trim((string) config('mobile.push.apns_key_id', ''));
        $teamId = trim((string) config('mobile.push.apns_team_id', ''));
        $bundleId = trim((string) config('mobile.push.apns_bundle_id', ''));
        $privateKey = $this->privateKey();

        if ($keyId === '' || $teamId === '' || $bundleId === '' || $privateKey === null) {
            return null;
        }

        return [
            'key_id' => $keyId,
            'team_id' => $teamId,
            'topic' => $bundleId,
            'private_key' => $privateKey,
            'sandbox' => (bool) config('mobile.push.apns_sandbox', false),
        ];
    }

    private function privateKey(): ?string
    {
        $path = trim((string) config('mobile.push.apns_private_key_path', ''));
        if ($path !== '' && is_readable($path)) {
            $contents = @file_get_contents($path);

            return $contents === false ? null : $contents;
        }

        $inline = trim((string) config('mobile.push.apns_private_key', ''));
        if ($inline !== '') {
            return str_replace('\\n', "\n", $inline);
        }

        return null;
    }

    private function providerToken(array $credentials): ?string
    {
        $now = time();

        $header = PushJwt::encodeSegment(['alg' => self::ALGORITHM, 'kid' => $credentials['key_id']]);
        $claims = PushJwt::encodeSegment([
            'iss' => $credentials['team_id'],
            'iat' => $now,
            'exp' => $now + 3600,
        ]);

        $unsigned = $header.'.'.$claims;
        $signature = PushJwt::signEs256($unsigned, $credentials['private_key']);

        if ($signature === '') {
            return null;
        }

        return $unsigned.'.'.$signature;
    }

    private function payload(PushMessage $message): array
    {
        return [
            'aps' => [
                'alert' => [
                    'title' => $message->title,
                    'body' => $message->body,
                ],
                'sound' => 'default',
            ],
            'ffarena' => $message->data,
        ];
    }
}
