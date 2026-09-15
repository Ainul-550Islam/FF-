<?php

namespace Tests\Feature\Api;

use App\Models\User;

/**
 * Phase 15 — token scope enforcement and admin-scope denial.
 */
class ApiTokenScopesTest extends ApiTestCase
{
    public function test_profile_read_scope_gates_me_endpoint(): void
    {
        $user = $this->user();

        $this->asUser($user, ['profile:read'])->getJson('/api/v1/me')->assertStatus(200);
    }

    public function test_missing_scope_is_rejected(): void
    {
        $user = $this->user();

        // A token with only notifications:read cannot read the profile.
        $this->asUser($user, ['notifications:read'])->getJson('/api/v1/me')->assertStatus(403);

        // A token with no profile:write cannot update the profile.
        $this->asUser($user, ['profile:read'])
            ->putJson('/api/v1/me/profile', ['name' => 'X'])
            ->assertStatus(403);
    }

    public function test_financial_mutations_require_payments_create_scope(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $player = $this->user();
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 500]);
        $team = $this->makeTeam($tournament, $player);

        $this->asUser($player, ['wallet:read'])
            ->postJson('/api/v1/payments', ['team_id' => $team->id, 'provider' => 'bkash'])
            ->assertStatus(403);

        $this->asUser($player, ['payments:create'])
            ->postJson('/api/v1/payments', ['team_id' => $team->id, 'provider' => 'bkash'])
            ->assertStatus(201);
    }

    public function test_admin_scope_cannot_be_self_granted(): void
    {
        $user = $this->user();

        // Requesting the admin scope in a normal token issue is silently
        // stripped — the resulting token must not carry it.
        $res = $this->asUser($user, ['profile:write'])
            ->postJson('/api/v1/me/tokens', [
                'name' => 'evil',
                'scopes' => ['admin', 'profile:read'],
            ]);

        $res->assertStatus(201);
        $this->assertNotContains('admin', $res->json('data.token_info.abilities'));

        // And the admin webhook surface is unreachable with that token.
        $this->authForget();
        $this->withToken($res->json('data.token'))->getJson('/api/v1/admin/webhooks/endpoints')->assertStatus(403);
    }

    public function test_admin_token_reaches_admin_surface(): void
    {
        $admin = $this->admin();
        $token = $this->tokenFor($admin, ['admin']);

        $this->authForget();
        $this->withToken($token)->getJson('/api/v1/admin/webhooks/endpoints')->assertStatus(200);
    }

    public function test_guest_cannot_hit_protected_endpoints(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401);
        $this->getJson('/api/v1/me/wallet')->assertStatus(401);
        $this->getJson('/api/v1/me/notifications')->assertStatus(401);
    }

    public function test_expired_token_is_rejected(): void
    {
        $user = $this->user();
        $token = $user->createToken('exp', ['profile:read'], now()->subMinute())->plainTextToken;

        $this->authForget();
        $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_revoked_client_disables_linked_tokens(): void
    {
        $user = $this->user();

        // Create a client + token through the API.
        $res = $this->asUser($user, ['profile:write'])
            ->postJson('/api/v1/me/clients', [
                'name' => 'My App',
                'scopes' => ['profile:read', 'profile:write'],
            ]);

        $res->assertStatus(201);
        $clientId = $res->json('data.client.id');
        $token = $res->json('data.token');

        // Revoke the client.
        $this->authForget();
        $this->withToken($token)->deleteJson('/api/v1/me/clients/' . $clientId)->assertStatus(204);

        // The linked token stops authenticating.
        $this->authForget();
        $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401);
    }
}
