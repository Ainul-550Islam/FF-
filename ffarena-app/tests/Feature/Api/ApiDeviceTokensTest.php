<?php

namespace Tests\Feature\Api;

use App\Models\MobileDevice;
use App\Models\User;

/**
 * Phase 18 — mobile push-device registration.
 *
 * Verifies owner-only access, hashed (never raw) token storage, the per-user
 * cap and the honest 422 for invalid platforms/providers.
 */
class ApiDeviceTokensTest extends ApiTestCase
{
    public function test_user_can_register_and_list_own_devices(): void
    {
        $user = $this->user();

        $res = $this->asUser($user, ['notifications:read', 'notifications:write'])
            ->postJson('/api/v1/me/devices', [
                'platform' => 'android',
                'provider' => 'fcm',
                'token' => 'raw-fcm-token-ABC123',
                'device_label' => 'Pixel 9',
            ]);

        $res->assertStatus(201)->assertJsonPath('data.platform', 'android');

        $this->assertDatabaseHas('mobile_device_tokens', [
            'user_id' => $user->id,
            'token_hash' => hash('sha256', 'raw-fcm-token-ABC123'),
            'is_active' => true,
        ]);

        // The raw token must never be persisted.
        $this->assertDatabaseMissing('mobile_device_tokens', ['token_hash' => 'raw-fcm-token-ABC123']);

        $this->asUser($user, ['notifications:read', 'notifications:write'])
            ->getJson('/api/v1/me/devices')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.platform', 'android')
            ->assertJsonMissing(['token_hash']);
    }

    public function test_same_token_refreshes_instead_of_duplicating(): void
    {
        $user = $this->user();

        $first = $this->asUser($user, ['notifications:read', 'notifications:write'])
            ->postJson('/api/v1/me/devices', ['platform' => 'android', 'provider' => 'fcm', 'token' => 'same-token']);

        $second = $this->asUser($user, ['notifications:read', 'notifications:write'])
            ->postJson('/api/v1/me/devices', ['platform' => 'android', 'provider' => 'fcm', 'token' => 'same-token']);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, MobileDevice::where('user_id', $user->id)->count());
    }

    public function test_user_cannot_see_or_delete_another_users_device(): void
    {
        $owner = $this->user();
        $other = $this->user();

        $created = $this->asUser($owner, ['notifications:read', 'notifications:write'])
            ->postJson('/api/v1/me/devices', ['platform' => 'ios', 'provider' => 'apns', 'token' => 'owner-token']);

        $id = $created->json('data.id');

        // Another user cannot list it.
        $this->asUser($other, ['notifications:read', 'notifications:write'])
            ->getJson('/api/v1/me/devices')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Another user cannot delete it.
        $this->asUser($other, ['notifications:read', 'notifications:write'])
            ->deleteJson('/api/v1/me/devices/'.$id)
            ->assertStatus(403);

        // The owner can.
        $this->asUser($owner, ['notifications:read', 'notifications:write'])
            ->deleteJson('/api/v1/me/devices/'.$id)
            ->assertStatus(204);

        $this->assertDatabaseMissing('mobile_device_tokens', ['id' => $id]);
    }

    public function test_invalid_platform_or_provider_is_rejected(): void
    {
        $user = $this->user();

        $this->asUser($user, ['notifications:read', 'notifications:write'])
            ->postJson('/api/v1/me/devices', ['platform' => 'web', 'provider' => 'fcm', 'token' => 't'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');

        $this->asUser($user, ['notifications:read', 'notifications:write'])
            ->postJson('/api/v1/me/devices', ['platform' => 'android', 'provider' => 'sms', 'token' => 't'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');
    }

    public function test_missing_token_is_rejected(): void
    {
        $user = $this->user();

        $this->asUser($user, ['notifications:read', 'notifications:write'])
            ->postJson('/api/v1/me/devices', ['platform' => 'android', 'provider' => 'fcm'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');
    }

    public function test_guests_cannot_register_devices(): void
    {
        $this->postJson('/api/v1/me/devices', ['platform' => 'android', 'provider' => 'fcm', 'token' => 't'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_missing_scope_is_forbidden(): void
    {
        $user = $this->user();

        // A token with no notification scopes cannot reach the endpoints.
        $this->asUser($user, ['profile:read'])
            ->postJson('/api/v1/me/devices', ['platform' => 'android', 'provider' => 'fcm', 'token' => 't'])
            ->assertStatus(403);
    }

    public function test_inactive_account_cannot_register_devices(): void
    {
        $user = $this->user(['account_status' => 'deactivated']);

        $this->asUser($user, ['notifications:read', 'notifications:write'])
            ->postJson('/api/v1/me/devices', ['platform' => 'android', 'provider' => 'fcm', 'token' => 't'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'account_inactive');
    }

    public function test_device_cap_deactivates_oldest_extras(): void
    {
        $user = $this->user();

        for ($i = 0; $i < 30; $i++) {
            $this->asUser($user, ['notifications:read', 'notifications:write'])
                ->postJson('/api/v1/me/devices', [
                    'platform' => 'android',
                    'provider' => 'fcm',
                    'token' => 'bulk-token-'.$i,
                ]);
        }

        $active = MobileDevice::where('user_id', $user->id)->where('is_active', true)->count();

        $this->assertLessThanOrEqual((int) config('mobile.devices.max_devices_per_user', 25), $active);
    }
}
