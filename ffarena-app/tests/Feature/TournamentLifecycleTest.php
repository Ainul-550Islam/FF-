<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Payment;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 02 — Tournament lifecycle + registration state machine.
 * Covers every lifecycle rule from the Phase 02 specification.
 */
class TournamentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Helpers (sensitive fields set explicitly, mirroring production)
    // ------------------------------------------------------------------

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function makeTournament(User $organizer, string $status = 'open', array $overrides = []): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = $overrides['name'] ?? 'Lifecycle Tournament';
        $t->slug = $overrides['slug'] ?? ('lifecycle-' . Str::random(8));
        $t->game_mode = $overrides['game_mode'] ?? 'squad';
        $t->map = $overrides['map'] ?? 'Bermuda';
        $t->entry_fee = $overrides['entry_fee'] ?? 100;
        $t->prize_pool = $overrides['prize_pool'] ?? 5000;
        $t->team_slots = $overrides['team_slots'] ?? 8;
        $t->team_size = $overrides['team_size'] ?? 4;
        $t->rules = $overrides['rules'] ?? null;
        $t->starts_at = $overrides['starts_at'] ?? now()->addDay();
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function makeTeam(Tournament $tournament, ?User $captain = null, string $status = 'pending'): Team
    {
        $t = new Team();
        $t->tournament_id = $tournament->id;
        $t->captain_id = $captain?->id;
        $t->name = 'Team ' . Str::random(6);
        $t->captain_name = $captain?->name ?? 'Captain';
        $t->phone = '01700000000';
        $t->game_uid = 'UID' . rand(100000, 999999);
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function makeMatch(Tournament $tournament, Team $t1, ?Team $t2 = null, string $status = 'pending'): GameMatch
    {
        $m = new GameMatch();
        $m->tournament_id = $tournament->id;
        $m->round = 1;
        $m->match_no = 1;
        $m->team1_id = $t1->id;
        $m->team2_id = $t2?->id;
        $m->status = $status;
        $m->save();

        return $m;
    }

    protected function validRegistrationPayload(string $name = 'Test Squad'): array
    {
        return [
            'name' => $name,
            'captain_name' => 'Captain',
            'phone' => '01700000000',
            'game_uid' => 'UID123456',
            'members' => [],
        ];
    }

    // ------------------------------------------------------------------
    // 1. Who may change tournament state
    // ------------------------------------------------------------------

    public function test_guest_cannot_change_tournament_state(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'draft');

        $this->post(route('tournaments.publish', $tournament))
            ->assertRedirect(route('login'));

        $this->assertSame('draft', $tournament->fresh()->status);
    }

    public function test_player_cannot_change_tournament_state(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'draft');

        $this->actingAs($player)->post(route('tournaments.publish', $tournament))->assertStatus(403);

        $this->assertSame('draft', $tournament->fresh()->status);
    }

    public function test_organizer_can_publish_own_draft_tournament(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'draft');

        $this->actingAs($org)->post(route('tournaments.publish', $tournament))
            ->assertSessionHas('success');

        $this->assertSame('open', $tournament->fresh()->status);
    }

    public function test_organizer_cannot_publish_another_organizers_tournament(): void
    {
        $orgA = $this->makeUser('organizer');
        $orgB = $this->makeUser('organizer');
        $tournament = $this->makeTournament($orgA, 'draft');

        $this->actingAs($orgB)->post(route('tournaments.publish', $tournament))->assertStatus(403);

        $this->assertSame('draft', $tournament->fresh()->status);
    }

    public function test_admin_can_manage_lifecycle(): void
    {
        $admin = $this->makeUser('admin');
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'draft');

        $this->actingAs($admin)->post(route('tournaments.publish', $tournament))->assertSessionHas('success');
        $this->assertSame('open', $tournament->fresh()->status);

        $this->actingAs($admin)->post(route('tournaments.cancel', $tournament))->assertSessionHas('success');
        $this->assertSame('cancelled', $tournament->fresh()->status);
    }

    // ------------------------------------------------------------------
    // 2. Transition validity
    // ------------------------------------------------------------------

    public function test_invalid_transition_is_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'draft');

        // draft → finished is not a legal edge.
        $this->actingAs($org)->post(route('tournaments.complete', $tournament))
            ->assertSessionHas('error');

        $this->assertSame('draft', $tournament->fresh()->status);
    }

    public function test_completed_tournament_cannot_return_to_registration(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'finished');

        // finished → open is illegal.
        $this->actingAs($org)->post(route('tournaments.publish', $tournament))->assertSessionHas('error');
        $this->assertSame('finished', $tournament->fresh()->status);

        // Registration is refused.
        $this->actingAs($player)->post(route('teams.store', $tournament), $this->validRegistrationPayload())
            ->assertSessionHas('error');
        $this->assertSame(0, Team::where('tournament_id', $tournament->id)->count());
    }

    public function test_cancelled_tournament_cannot_reopen(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'cancelled');

        // cancelled → open is illegal.
        $this->actingAs($org)->post(route('tournaments.publish', $tournament))->assertSessionHas('error');
        $this->assertSame('cancelled', $tournament->fresh()->status);

        $this->actingAs($player)->post(route('teams.store', $tournament), $this->validRegistrationPayload())
            ->assertSessionHas('error');
        $this->assertSame(0, Team::where('tournament_id', $tournament->id)->count());
    }

    public function test_organizer_can_complete_live_tournament(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'live');
        $t1 = $this->makeTeam($tournament, null, 'confirmed');
        $t2 = $this->makeTeam($tournament, null, 'confirmed');
        $this->makeMatch($tournament, $t1, $t2, 'completed');

        $this->actingAs($org)->post(route('tournaments.complete', $tournament))
            ->assertSessionHas('success');

        $this->assertSame('finished', $tournament->fresh()->status);
    }

    public function test_complete_blocked_when_matches_still_pending(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'live');
        $t1 = $this->makeTeam($tournament, null, 'confirmed');
        $t2 = $this->makeTeam($tournament, null, 'confirmed');
        $this->makeMatch($tournament, $t1, $t2, 'pending');

        $this->actingAs($org)->post(route('tournaments.complete', $tournament))
            ->assertSessionHas('error');

        $this->assertSame('live', $tournament->fresh()->status);
    }

    public function test_publish_blocked_when_start_time_is_in_the_past(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'draft', ['starts_at' => now()->subHour()]);

        $this->actingAs($org)->post(route('tournaments.publish', $tournament))
            ->assertSessionHas('error');

        $this->assertSame('draft', $tournament->fresh()->status);
    }

    public function test_unauthorized_user_cannot_manipulate_registration_state(): void
    {
        $orgA = $this->makeUser('organizer');
        $orgB = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($orgA, 'open');

        $this->actingAs($orgB)->post(route('tournaments.close', $tournament))->assertStatus(403);
        $this->assertSame('open', $tournament->fresh()->status);

        $this->actingAs($player)->post(route('tournaments.cancel', $tournament))->assertStatus(403);
        $this->assertSame('open', $tournament->fresh()->status);
    }

    // ------------------------------------------------------------------
    // 3. Registration rules
    // ------------------------------------------------------------------

    public function test_registration_blocked_when_closed(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'closed');

        $this->actingAs($player)->post(route('teams.store', $tournament), $this->validRegistrationPayload())
            ->assertSessionHas('error');

        $this->assertSame(0, Team::where('tournament_id', $tournament->id)->count());
    }

    public function test_registration_blocked_when_completed(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'finished');

        $this->actingAs($player)->post(route('teams.store', $tournament), $this->validRegistrationPayload())
            ->assertSessionHas('error');

        $this->assertSame(0, Team::where('tournament_id', $tournament->id)->count());
    }

    public function test_registration_blocked_when_cancelled(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'cancelled');

        $this->actingAs($player)->post(route('teams.store', $tournament), $this->validRegistrationPayload())
            ->assertSessionHas('error');

        $this->assertSame(0, Team::where('tournament_id', $tournament->id)->count());
    }

    public function test_registration_blocked_after_tournament_start(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', ['starts_at' => now()->subHour()]);

        $this->actingAs($player)->post(route('teams.store', $tournament), $this->validRegistrationPayload())
            ->assertSessionHas('error');

        $this->assertSame(0, Team::where('tournament_id', $tournament->id)->count());
    }

    public function test_tournament_capacity_is_respected(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', ['team_slots' => 8]);

        // Fill all 8 slots with pending teams (pending teams occupy slots too).
        for ($i = 0; $i < 8; $i++) {
            $this->makeTeam($tournament, null, 'pending');
        }

        // Phase 04: the 9th registration goes to the WAITLIST instead of
        // being rejected, but it must NOT occupy a competitive slot.
        $this->actingAs($player)->post(route('teams.store', $tournament), $this->validRegistrationPayload())
            ->assertRedirect(route('tournaments.show', $tournament));

        // Exactly 8 teams occupy slots (pending/confirmed)…
        $this->assertSame(
            8,
            Team::where('tournament_id', $tournament->id)->whereIn('status', ['pending', 'confirmed'])->count()
        );

        // …and exactly 1 team sits on the waitlist.
        $this->assertSame(
            1,
            Team::where('tournament_id', $tournament->id)->where('status', 'waitlisted')->count()
        );

        $this->assertSame(0, $tournament->fresh()->slotsLeft());
    }

    public function test_duplicate_team_registration_is_blocked(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');

        $this->actingAs($captain)->post(route('teams.store', $tournament), $this->validRegistrationPayload('First Team'))
            ->assertRedirect();

        $this->actingAs($captain)->post(route('teams.store', $tournament), $this->validRegistrationPayload('First Team'))
            ->assertSessionHas('error');

        $this->assertSame(1, Team::where('tournament_id', $tournament->id)->where('captain_id', $captain->id)->count());
    }

    public function test_same_captain_cannot_register_two_teams(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');

        $this->actingAs($captain)->post(route('teams.store', $tournament), $this->validRegistrationPayload('Team A'))
            ->assertRedirect();

        $this->actingAs($captain)->post(route('teams.store', $tournament), $this->validRegistrationPayload('Team B'))
            ->assertSessionHas('error');

        $this->assertSame(1, Team::where('tournament_id', $tournament->id)->where('captain_id', $captain->id)->count());
    }

    public function test_database_enforces_one_team_per_captain(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');

        $this->makeTeam($tournament, $captain, 'pending');

        $duplicate = new Team();
        $duplicate->tournament_id = $tournament->id;
        $duplicate->captain_id = $captain->id;
        $duplicate->name = 'Duplicate Team';
        $duplicate->captain_name = $captain->name;
        $duplicate->phone = '01700000000';
        $duplicate->game_uid = 'UID999';
        $duplicate->status = 'pending';

        $threw = false;
        try {
            $duplicate->save();
        } catch (QueryException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Expected the teams_tournament_captain_unique constraint to reject a duplicate captain.');
    }

    public function test_authorized_captain_can_register_a_valid_team(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');

        $this->actingAs($captain)->post(route('teams.store', $tournament), $this->validRegistrationPayload('Champions'))
            ->assertRedirect(route('payment.show', [$tournament, Team::where('name', 'Champions')->firstOrFail()]));

        $team = Team::where('name', 'Champions')->firstOrFail();
        $this->assertSame('pending', $team->status);
        $this->assertSame($captain->id, $team->captain_id);
    }

    // ------------------------------------------------------------------
    // 4. Cross-tournament integrity
    // ------------------------------------------------------------------

    public function test_foreign_team_cannot_withdraw_from_other_tournament(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournamentA = $this->makeTournament($org, 'open');
        $tournamentB = $this->makeTournament($org, 'open');
        $teamA = $this->makeTeam($tournamentA, $captain, 'pending');

        $this->actingAs($captain)->post(route('teams.withdraw', [$tournamentB, $teamA]))
            ->assertStatus(404);

        $this->assertSame('pending', $teamA->fresh()->status);
    }

    // ------------------------------------------------------------------
    // 5. Withdrawal
    // ------------------------------------------------------------------

    public function test_captain_can_withdraw_and_slot_is_released(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');

        $this->actingAs($captain)->post(route('teams.store', $tournament), $this->validRegistrationPayload('Withdraw Me'))
            ->assertRedirect();

        $team = Team::where('name', 'Withdraw Me')->firstOrFail();

        $this->actingAs($captain)->post(route('teams.withdraw', [$tournament, $team]))
            ->assertSessionHas('success');

        $this->assertSame('withdrawn', $team->fresh()->status);
        $this->assertNull($team->fresh()->captain_id);
        $this->assertSame($tournament->team_slots, $tournament->fresh()->slotsLeft());

        // The captain may register again after withdrawing.
        $this->actingAs($captain)->post(route('teams.store', $tournament), $this->validRegistrationPayload('Back Again'))
            ->assertRedirect();
        $this->assertSame(1, Team::where('tournament_id', $tournament->id)->where('captain_id', $captain->id)->where('status', 'pending')->count());
    }

    public function test_cannot_withdraw_after_tournament_is_live(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'live');
        $team = $this->makeTeam($tournament, $captain, 'confirmed');

        $this->actingAs($captain)->post(route('teams.withdraw', [$tournament, $team]))
            ->assertSessionHas('error');

        $this->assertSame('confirmed', $team->fresh()->status);
    }

    // ------------------------------------------------------------------
    // 6. Existing workflows preserved (regression)
    // ------------------------------------------------------------------

    public function test_existing_payment_workflow_remains_functional(): void
    {
        $admin = $this->makeUser('admin');
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 100]);

        $this->actingAs($captain)->post(route('teams.store', $tournament), $this->validRegistrationPayload('Paying Team'))
            ->assertRedirect();

        $team = Team::where('name', 'Paying Team')->firstOrFail();

        $this->actingAs($captain)->post(route('payment.verify', [$tournament, $team]), [
            'bkash_number' => '01700000000',
            'trx_id' => 'BTRX123',
        ])->assertRedirect();

        $payment = Payment::where('team_id', $team->id)->firstOrFail();
        $this->assertSame('pending', $payment->status);

        $this->actingAs($admin)->post(route('admin.payments.verify', $payment))->assertRedirect();
        $this->assertSame('verified', $payment->fresh()->status);
        $this->assertSame('confirmed', $team->fresh()->status);
    }

    public function test_payment_blocked_when_registration_is_closed(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'closed', ['entry_fee' => 100]);
        $team = $this->makeTeam($tournament, $captain, 'pending');

        $this->actingAs($captain)->post(route('payment.verify', [$tournament, $team]), [
            'bkash_number' => '01700000000',
            'trx_id' => 'BTRX123',
        ])->assertSessionHas('error');

        $this->assertSame(0, Payment::where('team_id', $team->id)->count());
    }

    public function test_existing_bracket_workflow_remains_functional(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'open');

        for ($i = 0; $i < 8; $i++) {
            $this->makeTeam($tournament, null, 'confirmed');
        }

        $this->actingAs($org)->post(route('tournaments.bracket', $tournament))->assertSessionHas('success');

        $this->assertSame('live', $tournament->fresh()->status);
        $this->assertSame(7, GameMatch::where('tournament_id', $tournament->id)->count());
    }

    public function test_existing_leaderboard_remains_functional(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'live', ['entry_fee' => 0]);

        $teamA = $this->makeTeam($tournament, $captain, 'confirmed');
        $teamB = $this->makeTeam($tournament, null, 'confirmed');
        $match = $this->makeMatch($tournament, $teamA, $teamB, 'live');

        $this->actingAs($captain)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $teamA->id,
            'kills' => 6,
            'placement' => 1,
        ])->assertRedirect();

        $this->actingAs($captain)->get(route('leaderboard.show', $tournament))
            ->assertOk()
            ->assertSee($teamA->name);
    }
}
