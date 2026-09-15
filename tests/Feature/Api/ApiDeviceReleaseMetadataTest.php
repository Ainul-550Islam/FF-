<?php

namespace Tests\Feature\Api;

use App\Models\MobileDevice;

/**
 * Phase 19 — device release metadata + encrypted-at-rest token storage.
 *
 * Verifies that registration records the client app version and environment,
 * that the raw token is encrypted at rest (never stored as plaintext), and
 * that the environment field validates against the known channels.
 */
class ApiDeviceReleaseMetadataTest extends ApiTestCase
{
    public function test_registration_records_app_version_and_environment(): void
    {
        $user = $this->user();

        $this->asUser($user, ['notifications:read', 'notifications:write'])
            ->postJson('/api/v1/me/devices', [
                'platform' => 'android',
                'provider' => 'fcm',
                'token' => 'release-token-1',
                'app_version' => '1.2.3',
                'environment' => 'staging',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.app_version', '1.2.3')
            ->assertJsonPath('data.environment', 'staging');
    }

    public function test_raw_token_is_encrypted_at_rest(): void
    {
        $user = $this->user();

        $this->asUser($user, ['notifications:read', 'notifications:write'])
            ->postJson('/api/v1/me/devices', [
                'platform' => 'android',
                'provider' => 'fcm',
                'token' => 'super-secret-token',
            ])
            ->assertStatus(201);

        $device = MobileDevice::where('user_id', $user->id)->first();

        // The hash identity is stored…
        $this->assertSame(hash('sha256', 'super-secret-token'), $device->token_hash);

        // …but the raw token is never stored in plaintext anywhere.
        $raw = $device->getRawOriginal('encrypted_token');
        $this->assertNotNull($raw);
        $this->assertNotSame('super-secret-token', $raw);
        $this->assertStringNotContainsString('super-secret-token', $raw);

        // The encrypted cast decrypts transparently for server-side delivery.
        $this->assertSame('super-secret-token', $device->encrypted_token);

        // The API never echoes the token (or its ciphertext/hash).
        $this->asUser($user, ['notifications:read'])
            ->getJson('/api/v1/me/devices')
            ->assertOk()
            ->assertJsonMissing(['token_hash'])
            ->assertJsonMissing(['encrypted_token'])
            ->assertJsonMissing(['super-secret-token']);
    }

    public function test_invalid_environment_is_rejected(): void
    {
        $user = $this->user();

        $this->asUser($user, ['notifications:read', 'notifications:write'])
            ->postJson('/api/v1/me/devices', [
                'platform' => 'android',
                'provider' => 'fcm',
                'token' => 'token-env',
                'environment' => 'debug-local-whatever',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');
    }

    public function test_token_refresh_updates_release_metadata(): void
    {
        $user = $this->user();

        $first = $this->asUser($user, ['notifications:read', 'notifications:write'])
            ->postJson('/api/v1/me/devices', [
                'platform' => 'android',
                'provider' => 'fcm',
                'token' => 'rotating-token',
                'app_version' => '1.0.0',
                'environment' => 'production',
            ]);

        $this->asUser($user, ['notifications:read', 'notifications:write'])
            ->postJson('/api/v1/me/devices', [
                'platform' => 'android',
                'provider' => 'fcm',
                'token' => 'rotating-token',
                'app_version' => '1.1.0',
                'environment' => 'production',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.app_version', '1.1.0');
    }
}
