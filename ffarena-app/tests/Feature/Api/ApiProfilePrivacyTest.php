<?php

namespace Tests\Feature\Api;

/**
 * Phase 15 — profile privacy: /me, public profiles, private-profile
 * redaction, and sensitive-field leakage.
 */
class ApiProfilePrivacyTest extends ApiTestCase
{
    public function test_me_returns_own_profile_without_sensitive_fields(): void
    {
        $user = $this->user(['email' => 'me@example.com', 'phone' => '+8801712345678']);

        $res = $this->asUser($user, ['profile:read'])->getJson('/api/v1/me');

        $res->assertStatus(200)
            ->assertJsonPath('data.email', 'me@example.com')
            ->assertJsonPath('data.username', $user->username);

        $data = $res->json('data');
        foreach (['password', 'phone', 'remember_token', 'risk_score', 'risk_level'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data, "leaked key: {$forbidden}");
        }

        $body = json_encode($data);
        foreach (['ip_address', 'device_hash', 'fraud'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "leaked field: {$forbidden}");
        }
    }

    public function test_me_profile_update(): void
    {
        $user = $this->user();

        $res = $this->asUser($user, ['profile:write'])
            ->putJson('/api/v1/me/profile', [
                'name' => 'New Name',
                'bio' => 'Hello world',
                'privacy' => 'private',
            ]);

        $res->assertStatus(200)
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.privacy', 'private');

        $this->assertSame('New Name', $user->fresh()->name);
        $this->assertSame('private', $user->fresh()->privacy);
    }

    public function test_private_profile_is_never_exposed_by_id(): void
    {
        $target = $this->user(['privacy' => 'private', 'bio' => 'Secret bio', 'email' => 'private@example.com']);
        $viewer = $this->user();

        $res = $this->asUser($viewer, ['profile:read'])->getJson('/api/v1/players/' . $target->id);

        $res->assertStatus(200)
            ->assertJsonPath('data.visible', false)
            ->assertJsonPath('data.name', $target->name);

        $this->assertNull($res->json('data.bio') ?? null);
        $this->assertNull($res->json('data.email') ?? null);
    }

    public function test_public_profile_exposes_public_fields_only(): void
    {
        $target = $this->user(['privacy' => 'public', 'bio' => 'Public bio', 'email' => 'pub@example.com', 'phone' => '+8801712345678']);
        $viewer = $this->user();

        $res = $this->asUser($viewer, ['profile:read'])->getJson('/api/v1/players/' . $target->id);

        $res->assertStatus(200)->assertJsonPath('data.visible', true);

        $body = json_encode($res->json('data'));
        $this->assertStringNotContainsString('pub@example.com', $body, 'email leaked on public profile');
        $this->assertStringNotContainsString('phone', $body, 'phone leaked on public profile');
    }

    public function test_profile_update_rejects_invalid_privacy(): void
    {
        $user = $this->user();

        $this->asUser($user, ['profile:write'])
            ->putJson('/api/v1/me/profile', ['privacy' => 'everyone-on-earth'])
            ->assertStatus(422);
    }
}
