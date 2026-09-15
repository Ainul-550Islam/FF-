<?php

namespace Tests\Feature\Api;

use App\Models\Team;
use App\Models\Tournament;

/**
 * Phase 15 — tournament discovery, registration, check-in, waitlist and
 * authorization.
 */
class ApiTournamentsTest extends ApiTestCase
{
    public function test_public_tournaments_are_listed_and_filtered(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $open = $this->makeTournament($org, 'open', ['name' => 'Alpha Cup']);
        $this->makeTournament($org, 'live', ['name' => 'Beta Cup']);
        $draft = $this->makeTournament($org, 'draft', ['name' => 'Secret Draft']);

        $res = $this->getJson('/api/v1/tournaments');
        $res->assertStatus(200);

        $ids = collect($res->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($open->id));
        $this->assertFalse($ids->contains($draft->id));

        // Whitelisted status filter.
        $filtered = $this->getJson('/api/v1/tournaments?status=live');
        $this->assertSame(['live'], array_unique(array_column($filtered->json('data'), 'status')));

        // Unknown sort key falls back to the default — no SQL injection.
        $this->getJson('/api/v1/tournaments?sort=evil%27%3B%20DROP%20TABLE%20users%3B--&direction=asc')
            ->assertStatus(200);
    }

    public function test_tournament_show_excludes_non_public(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $open = $this->makeTournament($org, 'open');
        $draft = $this->makeTournament($org, 'draft');

        $this->getJson('/api/v1/tournaments/' . $open->slug)->assertStatus(200);
        $this->getJson('/api/v1/tournaments/' . $draft->slug)->assertStatus(404);
    }

    public function test_registration_derives_state_and_rejects_injected_fields(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $player = $this->user();
        $tournament = $this->makeTournament($org, 'open', ['team_slots' => 8, 'entry_fee' => 500]);

        // The client tries to inject status/slot/confirmation — all ignored;
        // the server derives pending + payment step.
        $res = $this->asUser($player, ['tournaments:register'])
            ->postJson('/api/v1/tournaments/' . $tournament->slug . '/registrations', [
                'name' => 'Injected',
                'captain_name' => 'Captain',
                'phone' => '01700000000',
                'game_uid' => 'UIDINJECT1',
                'status' => 'confirmed',
                'confirmation' => 'paid',
                'slot' => 7,
            ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.team.status', 'pending')
            ->assertJsonPath('data.waitlisted', false)
            ->assertJsonPath('data.next_step', 'payment');

        $this->assertSame(1, Team::where('captain_id', $player->id)->count());
    }

    public function test_registration_capacity_overflow_goes_to_waitlist(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $player = $this->user();
        $tournament = $this->makeTournament($org, 'open', ['team_slots' => 2]);

        $this->makeTeam($tournament, null, 'pending');
        $this->makeTeam($tournament, null, 'pending');

        $res = $this->asUser($player, ['tournaments:register'])
            ->postJson('/api/v1/tournaments/' . $tournament->slug . '/registrations', $this->registrationPayload('Overflow'));

        $res->assertStatus(201)
            ->assertJsonPath('data.waitlisted', true)
            ->assertJsonPath('data.waitlist_position', 1);
    }

    public function test_registration_refused_when_closed(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $player = $this->user();
        $tournament = $this->makeTournament($org, 'closed');

        $this->asUser($player, ['tournaments:register'])
            ->postJson('/api/v1/tournaments/' . $tournament->slug . '/registrations', $this->registrationPayload())
            ->assertStatus(409);
    }

    public function test_duplicate_registration_is_blocked(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $player = $this->user();
        $tournament = $this->makeTournament($org, 'open');

        $this->asUser($player, ['tournaments:register'])
            ->postJson('/api/v1/tournaments/' . $tournament->slug . '/registrations', $this->registrationPayload())
            ->assertStatus(201);

        $this->asUser($player, ['tournaments:register'])
            ->postJson('/api/v1/tournaments/' . $tournament->slug . '/registrations', $this->registrationPayload('Second', 'UIDAPI0002'))
            ->assertStatus(409);
    }

    public function test_guest_cannot_register_or_check_in(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $tournament = $this->makeTournament($org, 'open');

        $this->postJson('/api/v1/tournaments/' . $tournament->slug . '/registrations', $this->registrationPayload())
            ->assertStatus(401);

        $this->postJson('/api/v1/tournaments/' . $tournament->slug . '/check-in', ['team_id' => 1])
            ->assertStatus(401);
    }

    public function test_check_in_requires_captain_and_window(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $captain = $this->user();
        $other = $this->user();
        $tournament = $this->makeTournament($org, 'open', [
            'check_in_starts_at' => now()->subHour(),
            'check_in_ends_at' => now()->addHour(),
        ]);
        $team = $this->makeTeam($tournament, $captain, 'confirmed');

        // A non-captain cannot check the team in (IDOR / authorization).
        $this->asUser($other, ['tournaments:register'])
            ->postJson('/api/v1/tournaments/' . $tournament->slug . '/check-in', ['team_id' => $team->id])
            ->assertStatus(403);

        // The captain can.
        $this->asUser($captain, ['tournaments:register'])
            ->postJson('/api/v1/tournaments/' . $tournament->slug . '/check-in', ['team_id' => $team->id])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'checked_in');

        $this->assertNotNull($team->fresh()->checked_in_at);
    }

    public function test_waitlist_positions_are_visible_but_promotion_is_server_only(): void
    {
        $org = $this->user(['role' => 'organizer']);
        $player = $this->user();
        $tournament = $this->makeTournament($org, 'open', ['team_slots' => 1]);

        $this->makeTeam($tournament, null, 'pending');
        $this->asUser($player, ['tournaments:register'])
            ->postJson('/api/v1/tournaments/' . $tournament->slug . '/registrations', $this->registrationPayload())
            ->assertStatus(201);

        $res = $this->asUser($player, ['tournaments:read'])
            ->getJson('/api/v1/tournaments/' . $tournament->slug . '/waitlist');

        $res->assertStatus(200);
        $this->assertSame(1, $res->json('data.count'));

        // There is no client promotion endpoint — POST to a fake one is 404/405.
        $this->asUser($player, ['tournaments:register'])
            ->postJson('/api/v1/tournaments/' . $tournament->slug . '/waitlist', ['team_id' => 1])
            ->assertStatus(405);
    }
}
