<?php

namespace Tests\Feature\Api;

use App\Models\GameMatch;
use App\Models\User;

/**
 * Phase 15 — security coverage: privilege escalation, SQL-injection
 * resistance, mass-assignment resistance, token leakage, and
 * sensitive-field redaction.
 */
class ApiSecurityTest extends ApiTestCase
{
    public function test_admin_endpoints_reject_non_admin_tokens(): void
    {
        $player = $this->user();
        $organizer = $this->user(['role' => 'organizer']);
        $moderator = $this->user(['role' => 'moderator']);

        foreach ([$player, $organizer, $moderator] as $user) {
            $this->asUser($user, ['*'])
                ->getJson('/api/v1/admin/webhooks/endpoints')
                ->assertStatus(403);
        }
    }

    public function test_sql_injection_in_sort_and_search_is_inert(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $this->makeTournament($org, 'open', ['name' => 'Normal Cup']);

        $this->getJson('/api/v1/tournaments?sort=created_at%3B%20DROP%20TABLE%20users%3B--')
            ->assertStatus(200);

        $this->getJson("/api/v1/tournaments?q='%20OR%201%3D1--")
            ->assertStatus(200);

        $this->getJson("/api/v1/tournaments?direction=desc%3B%20UPDATE%20users%20SET%20role%3D'admin'--")
            ->assertStatus(200);

        // The user table is intact.
        $this->assertGreaterThanOrEqual(1, User::count());
    }

    public function test_mass_assignment_cannot_escalate_role(): void
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'name' => 'Escalator',
            'username' => 'escalator',
            'email' => 'escalator@example.com',
            'role' => 'admin',
            'account_status' => 'active',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $res->assertStatus(422);
        $this->assertSame(0, User::where('role', 'admin')->where('email', 'escalator@example.com')->count());
    }

    public function test_profile_update_cannot_change_role_or_status(): void
    {
        $user = $this->user();

        $this->asUser($user, ['profile:write'])
            ->putJson('/api/v1/me/profile', [
                'name' => 'Still Me',
                'role' => 'admin',
                'account_status' => 'deleted',
                'email' => 'hijacked@example.com',
            ])
            ->assertStatus(200);

        $fresh = $user->fresh();
        $this->assertSame('player', $fresh->role);
        $this->assertSame('active', $fresh->account_status);
    }

    public function test_no_token_or_stack_trace_leaks_in_errors(): void
    {
        // Trigger an unexpected error and assert no internals leak.
        $res = $this->getJson('/api/v1/tournaments?sort='.urlencode("x'\""));
        $res->assertStatus(200);

        $notFound = $this->getJson('/api/v1/matches/999999');
        $this->assertSame('not_found', $notFound->json('error.code'));
        $body = (string) $notFound->getContent();
        $this->assertStringNotContainsString('Stack trace', $body);
        $this->assertStringNotContainsString('app/Http', $body);
        $this->assertStringNotContainsString('SQLSTATE', $body);
    }

    public function test_register_response_does_not_leak_password_hash(): void
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'name' => 'Hashless',
            'username' => 'hashless',
            'email' => 'hashless@example.com',
            'role' => 'player',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $this->assertStringNotContainsString('$2y$', (string) $res->getContent());
        $this->assertArrayNotHasKey('password', $res->json('data.user'));
    }

    public function test_token_list_never_returns_plaintext(): void
    {
        $user = $this->user();
        $plaintext = $this->tokenFor($user, ['profile:read']);

        $res = $this->asUser($user, ['profile:read'])->getJson('/api/v1/me/tokens');
        $body = (string) $res->getContent();

        $this->assertStringNotContainsString($plaintext, $body);
        $this->assertArrayNotHasKey('token', $res->json('data.0'));
        $this->assertArrayNotHasKey('plainTextToken', $res->json('data.0'));
    }

    public function test_sensitive_fields_are_redacted_across_surfaces(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $player = $this->user(['phone' => '+8801712345678']);
        $tournament = $this->makeTournament($org, 'live');
        $team = $this->makeTeam($tournament, $player, 'confirmed', 'UIDSAFE001');
        $this->makeMatch($tournament, $team, null, 'live');

        $surfaces = [
            $this->asUser($player, ['profile:read'])->getJson('/api/v1/me'),
            $this->asUser($player, ['teams:read'])->getJson('/api/v1/teams/'.$team->id),
            $this->asUser($player, ['tournaments:read'])->getJson('/api/v1/tournaments/'.$tournament->slug),
        ];

        foreach ($surfaces as $res) {
            $body = json_encode($res->json());
            foreach (['risk_score', 'risk_level', 'ip_address', 'device_hash', 'fingerprint', 'ledger_internal'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $body, "leaked: {$forbidden}");
            }
        }
    }

    protected function makeMatch($tournament, $team1, $team2, string $status): GameMatch
    {
        $match = new GameMatch();
        $match->tournament_id = $tournament->id;
        $match->team1_id = $team1?->id;
        $match->team2_id = $team2?->id;
        $match->round = 1;
        $match->match_no = 1;
        $match->bracket = 'winners';
        $match->status = $status;
        $match->save();

        return $match;
    }
}
