<?php

namespace Tests\Feature\Coverage;

use App\Models\Tournament;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Feature\Api\ApiTestCase;

/**
 * G3 — API v1 critical-endpoint coverage.
 *
 * Walks the most important public + authenticated API surfaces (app meta,
 * tournament discovery/detail, leaderboard, me, wallet, notifications,
 * registration) so coverage runs execute the API controllers/resources even
 * under a targeted subset. All assertions are real contract checks.
 */
class ApiCoverageTest extends ApiTestCase
{
    public function test_public_discovery_surface_is_covered(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 50]);
        $slug = $tournament->slug;

        $this->getJson('/api/v1/app/meta')->assertOk()->assertJsonPath('data.app.name', 'FF Arena');

        $this->getJson('/api/v1/tournaments')->assertOk();
        $this->getJson('/api/v1/tournaments/'.$slug)->assertOk();
        $this->getJson('/api/v1/tournaments/'.$slug.'/leaderboard')->assertOk();
        $this->getJson('/api/v1/leaderboards/'.$slug)->assertOk();
    }

    public function test_authenticated_me_and_wallet_surface_is_covered(): void
    {
        $user = $this->user();

        $this->asUser($user, ['profile:read'])->getJson('/api/v1/me')->assertOk();

        $this->asUser($user, ['wallet:read'])->getJson('/api/v1/me/wallet')->assertOk()->assertJsonPath('data.currency', 'BDT');

        $this->asUser($user, ['wallet:read'])->getJson('/api/v1/me/wallet/ledger')->assertOk();

        $this->asUser($user, ['notifications:read'])->getJson('/api/v1/me/notifications')->assertOk();

        $this->asUser($user, ['wallet:read'])->getJson('/api/v1/payments/methods')->assertOk();
    }

    public function test_registration_surface_is_covered(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 0]);

        $player = $this->user();

        $res = $this->asUser($player, ['tournaments:register'])
            ->postJson('/api/v1/tournaments/'.$tournament->slug.'/registrations', [
                'name' => 'ApiCov Squad',
                'captain_name' => $player->name,
                'phone' => '01700000000',
                'game_uid' => 'UIDAPICOV',
                'members' => [],
            ]);

        $res->assertStatus(201)->assertJsonPath('data.next_step', 'payment');
        $res->assertJsonPath('data.waitlisted', false);

        // The registered team appears in the authenticated teams listing.
        $this->asUser($player, ['teams:read'])
            ->getJson('/api/v1/me/teams')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_auth_surface_is_covered(): void
    {
        $this->user(['email' => 'cov-login@example.com', 'password' => bcrypt('secret123')]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'cov-login@example.com',
            'password' => 'secret123',
        ])->assertOk();
    }

    public function test_unknown_tournament_is_404(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $this->makeTournament($org, 'open');

        $this->getJson('/api/v1/tournaments/999999')->assertStatus(404);
    }
}
