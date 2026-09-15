<?php

namespace Tests\Feature\Api;

/**
 * Phase 19 — per-category push notification preferences.
 *
 * Verifies defaults, partial updates, the mandatory always-on security
 * category, rejection of unknown categories, and scope enforcement.
 */
class ApiNotificationPreferencesTest extends ApiTestCase
{
    public function test_defaults_all_categories_on_including_security(): void
    {
        $user = $this->user();

        $res = $this->asUser($user, ['notifications:read'])
            ->getJson('/api/v1/me/notification-preferences');

        $res->assertOk()
            ->assertJsonPath('data.tournament', true)
            ->assertJsonPath('data.match', true)
            ->assertJsonPath('data.team', true)
            ->assertJsonPath('data.payment', true)
            ->assertJsonPath('data.payout', true)
            ->assertJsonPath('data.dispute', true)
            ->assertJsonPath('data.security', true)
            ->assertJsonPath('data.support', true);
    }

    public function test_user_can_toggle_a_category(): void
    {
        $user = $this->user();

        $this->asUser($user, ['notifications:write'])
            ->patchJson('/api/v1/me/notification-preferences', ['payment' => false])
            ->assertOk()
            ->assertJsonPath('data.payment', false)
            ->assertJsonPath('data.tournament', true);

        // Persisted.
        $this->asUser($user, ['notifications:read'])
            ->getJson('/api/v1/me/notification-preferences')
            ->assertJsonPath('data.payment', false);
    }

    public function test_partial_update_preserves_other_categories(): void
    {
        $user = $this->user();

        $this->asUser($user, ['notifications:write'])
            ->patchJson('/api/v1/me/notification-preferences', ['dispute' => false])
            ->assertOk();

        $this->asUser($user, ['notifications:read'])
            ->getJson('/api/v1/me/notification-preferences')
            ->assertOk()
            ->assertJsonPath('data.dispute', false)
            ->assertJsonPath('data.team', true)
            ->assertJsonPath('data.support', true);
    }

    public function test_security_category_cannot_be_disabled(): void
    {
        $user = $this->user();

        $this->asUser($user, ['notifications:write'])
            ->patchJson('/api/v1/me/notification-preferences', ['security' => false])
            ->assertOk()
            ->assertJsonPath('data.security', true);
    }

    public function test_unknown_category_is_rejected(): void
    {
        $user = $this->user();

        $this->asUser($user, ['notifications:write'])
            ->patchJson('/api/v1/me/notification-preferences', ['not_a_category' => true])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');
    }

    public function test_non_boolean_value_is_rejected(): void
    {
        $user = $this->user();

        $this->asUser($user, ['notifications:write'])
            ->patchJson('/api/v1/me/notification-preferences', ['payment' => 'yes'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');
    }

    public function test_requires_notification_scope(): void
    {
        $user = $this->user();

        $this->asUser($user, ['profile:read'])
            ->getJson('/api/v1/me/notification-preferences')
            ->assertStatus(403);

        $this->asUser($user, ['profile:read'])
            ->patchJson('/api/v1/me/notification-preferences', ['payment' => false])
            ->assertStatus(403);
    }

    public function test_guests_are_rejected(): void
    {
        $this->getJson('/api/v1/me/notification-preferences')
            ->assertStatus(401);

        $this->patchJson('/api/v1/me/notification-preferences', ['payment' => false])
            ->assertStatus(401);
    }
}
