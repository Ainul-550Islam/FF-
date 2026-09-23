<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Payment;
use App\Models\Score;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Helpers (sensitive fields are set explicitly, mirroring production)
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
        $t->name = $overrides['name'] ?? 'Test Tournament';
        $t->slug = $overrides['slug'] ?? ('test-tournament-'.Str::random(8));
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

    protected function makeTeam(Tournament $tournament, ?User $captain = null, string $status = 'confirmed'): Team
    {
        $t = new Team();
        $t->tournament_id = $tournament->id;
        $t->captain_id = $captain?->id;
        $t->name = 'Team '.Str::random(6);
        $t->captain_name = $captain?->name ?? 'Captain';
        $t->phone = '01700000000';
        $t->game_uid = 'UID'.rand(100000, 999999);
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function makeMatch(Tournament $tournament, Team $t1, ?Team $t2 = null, string $status = 'live'): GameMatch
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

    protected function makePayment(Tournament $tournament, Team $team, string $status = 'pending'): Payment
    {
        $p = new Payment();
        $p->tournament_id = $tournament->id;
        $p->team_id = $team->id;
        $p->amount = $tournament->entry_fee;
        $p->method = 'bkash';
        $p->trx_id = 'TRX'.Str::upper(Str::random(8));
        $p->status = $status;
        $p->save();

        return $p;
    }

    // ------------------------------------------------------------------
    // 1. Unauthenticated access
    // ------------------------------------------------------------------

    public function test_guest_cannot_create_tournament(): void
    {
        $this->post(route('tournaments.store'), [
            'name' => 'X', 'game_mode' => 'squad', 'map' => 'Bermuda',
            'entry_fee' => 100, 'prize_pool' => 5000, 'team_slots' => 8,
            'team_size' => 4, 'rules' => '', 'starts_at' => now()->toDateTimeString(),
        ])->assertRedirect(route('login'));
    }

    public function test_guest_cannot_submit_score(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'live');
        $t1 = $this->makeTeam($tournament, null);
        $t2 = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $t1, $t2);

        $this->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $t1->id, 'kills' => 3, 'placement' => 1,
        ])->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------
    // 2. Privilege escalation
    // ------------------------------------------------------------------

    public function test_player_cannot_access_admin_dashboard(): void
    {
        $player = $this->makeUser('player');

        $this->actingAs($player)->get(route('admin.dashboard'))->assertStatus(403);
    }

    public function test_player_cannot_create_tournament(): void
    {
        $player = $this->makeUser('player');

        $this->actingAs($player)->post(route('tournaments.store'), [
            'name' => 'X', 'game_mode' => 'squad', 'map' => 'Bermuda',
            'entry_fee' => 100, 'prize_pool' => 5000, 'team_slots' => 8,
            'team_size' => 4, 'rules' => '', 'starts_at' => now()->toDateTimeString(),
        ])->assertStatus(403);

        $this->assertDatabaseMissing('tournaments', ['name' => 'X']);
    }

    // ------------------------------------------------------------------
    // 3. Tournament ownership
    // ------------------------------------------------------------------

    public function test_user_a_cannot_update_user_b_tournament(): void
    {
        $orgA = $this->makeUser('organizer');
        $orgB = $this->makeUser('organizer');
        $tournament = $this->makeTournament($orgA, 'draft');

        $this->actingAs($orgB)->put(route('tournaments.update', $tournament), [
            'name' => 'Hacked', 'game_mode' => 'squad', 'map' => 'Bermuda',
            'entry_fee' => 100, 'prize_pool' => 5000, 'team_slots' => 8,
            'team_size' => 4, 'rules' => '', 'starts_at' => now()->toDateTimeString(),
        ])->assertStatus(403);

        $this->assertNotSame('Hacked', $tournament->fresh()->name);
    }

    public function test_organizer_can_update_own_tournament(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'draft');

        $this->actingAs($org)->put(route('tournaments.update', $tournament), [
            'name' => 'Updated Name', 'game_mode' => 'squad', 'map' => 'Bermuda',
            'entry_fee' => 200, 'prize_pool' => 8000, 'team_slots' => 8,
            'team_size' => 4, 'rules' => '', 'starts_at' => now()->addDay()->toDateTimeString(),
        ])->assertRedirect(route('tournaments.show', $tournament));

        $this->assertSame('Updated Name', $tournament->fresh()->name);
    }

    // ------------------------------------------------------------------
    // 4. Cross-tournament IDOR
    // ------------------------------------------------------------------

    public function test_team_from_other_tournament_cannot_be_used_for_payment(): void
    {
        $org = $this->makeUser('organizer');
        $tournamentA = $this->makeTournament($org, 'open');
        $tournamentB = $this->makeTournament($org, 'open');
        $captain = $this->makeUser('player');
        $teamA = $this->makeTeam($tournamentA, $captain);

        $this->actingAs($captain)
            ->get(route('payment.show', [$tournamentB, $teamA]))
            ->assertStatus(404);
    }

    public function test_match_from_other_tournament_cannot_be_scored(): void
    {
        $org = $this->makeUser('organizer');
        $tournamentA = $this->makeTournament($org, 'live');
        $tournamentB = $this->makeTournament($org, 'live');
        $t1 = $this->makeTeam($tournamentA, null);
        $t2 = $this->makeTeam($tournamentA, null);
        $matchA = $this->makeMatch($tournamentA, $t1, $t2);

        $this->actingAs($org)->post(route('matches.score', [$tournamentB, $matchA]), [
            'team_id' => $t1->id, 'kills' => 3, 'placement' => 1,
        ])->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // 5. Team ownership
    // ------------------------------------------------------------------

    public function test_user_cannot_submit_score_for_team_they_do_not_control(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'live');
        $captainA = $this->makeUser('player');
        $captainB = $this->makeUser('player');
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament, $captainB);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        // Captain B tries to submit a score for team A.
        $this->actingAs($captainB)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $teamA->id, 'kills' => 3, 'placement' => 1,
        ])->assertStatus(403);

        $this->assertDatabaseMissing('scores', ['team_id' => $teamA->id]);
    }

    public function test_user_cannot_register_two_teams_in_same_tournament(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'open');
        $captain = $this->makeUser('player');

        $payload = [
            'name' => 'Team One', 'captain_name' => 'Cap', 'phone' => '01700000000',
            'game_uid' => 'UID1', 'members' => [],
        ];

        $this->actingAs($captain)->post(route('teams.store', $tournament), $payload);
        $this->actingAs($captain)->post(route('teams.store', $tournament), $payload)
            ->assertSessionHas('error');

        $this->assertSame(1, Team::where('tournament_id', $tournament->id)->where('captain_id', $captain->id)->count());
    }

    // ------------------------------------------------------------------
    // 6. Score submission integrity
    // ------------------------------------------------------------------

    public function test_authorized_user_cannot_submit_score_for_non_participant_team(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'live');
        $t1 = $this->makeTeam($tournament, null);
        $t2 = $this->makeTeam($tournament, null);
        $outsider = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $t1, $t2);

        $this->actingAs($org)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $outsider->id, 'kills' => 3, 'placement' => 1,
        ])->assertStatus(403);

        $this->assertDatabaseMissing('scores', ['team_id' => $outsider->id]);
    }

    public function test_captain_can_submit_score_for_own_team(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'live');
        $captain = $this->makeUser('player');
        $teamA = $this->makeTeam($tournament, $captain);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->actingAs($captain)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $teamA->id, 'kills' => 5, 'placement' => 2,
        ])->assertStatus(302);

        $this->assertDatabaseHas('scores', ['match_id' => $match->id, 'team_id' => $teamA->id, 'kills' => 5]);
    }

    public function test_duplicate_score_submission_is_blocked(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'live');
        $captain = $this->makeUser('player');
        $teamA = $this->makeTeam($tournament, $captain);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->actingAs($captain)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $teamA->id, 'kills' => 5, 'placement' => 2,
        ])->assertStatus(302);

        $this->actingAs($captain)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $teamA->id, 'kills' => 9, 'placement' => 1,
        ])->assertStatus(403);

        $this->assertSame(1, Score::where('match_id', $match->id)->where('team_id', $teamA->id)->count());
    }

    public function test_negative_kills_are_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'live');
        $captain = $this->makeUser('player');
        $teamA = $this->makeTeam($tournament, $captain);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->actingAs($captain)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $teamA->id, 'kills' => -5, 'placement' => 2,
        ])->assertSessionHasErrors('kills');
    }

    // ------------------------------------------------------------------
    // 7. Winner security
    // ------------------------------------------------------------------

    public function test_organizer_cannot_declare_unrelated_team_as_winner(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'live');
        $t1 = $this->makeTeam($tournament, null);
        $t2 = $this->makeTeam($tournament, null);
        $outsider = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $t1, $t2);

        $this->actingAs($org)->post(route('matches.winner', [$tournament, $match]), [
            'winner_team_id' => $outsider->id,
        ])->assertStatus(403);

        $this->assertNull($match->fresh()->winner_team_id);
    }

    public function test_player_cannot_finalize_match(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'live');
        $player = $this->makeUser('player');
        $t1 = $this->makeTeam($tournament, $player);
        $t2 = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $t1, $t2);

        $this->actingAs($player)->post(route('matches.winner', [$tournament, $match]), [
            'winner_team_id' => $t1->id,
        ])->assertStatus(403);

        $this->assertNull($match->fresh()->winner_team_id);
    }

    // ------------------------------------------------------------------
    // 8. Payment security
    // ------------------------------------------------------------------

    public function test_user_cannot_access_another_users_payment(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'open');
        $captainA = $this->makeUser('player');
        $captainB = $this->makeUser('player');
        $teamA = $this->makeTeam($tournament, $captainA, 'pending');
        $payment = $this->makePayment($tournament, $teamA);

        $this->actingAs($captainB)
            ->get(route('payment.pending', [$tournament, $teamA, $payment]))
            ->assertStatus(403);

        $this->actingAs($captainB)
            ->get(route('payment.show', [$tournament, $teamA]))
            ->assertStatus(403);
    }

    public function test_non_admin_cannot_verify_payment(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'open');
        $captain = $this->makeUser('player');
        $team = $this->makeTeam($tournament, $captain, 'pending');
        $payment = $this->makePayment($tournament, $team);

        $this->actingAs($org)->post(route('admin.payments.verify', $payment))->assertStatus(403);

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('pending', $team->fresh()->status);
    }

    public function test_admin_can_verify_payment(): void
    {
        $admin = $this->makeUser('admin');
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org, 'open');
        $captain = $this->makeUser('player');
        $team = $this->makeTeam($tournament, $captain, 'pending');
        $payment = $this->makePayment($tournament, $team);

        $this->actingAs($admin)->post(route('admin.payments.verify', $payment))->assertStatus(302);

        $this->assertSame('verified', $payment->fresh()->status);
        $this->assertSame('confirmed', $team->fresh()->status);
    }

    // ------------------------------------------------------------------
    // 9. Mass assignment protection
    // ------------------------------------------------------------------

    public function test_role_cannot_be_mass_assigned_to_admin(): void
    {
        $this->post(route('register'), [
            'name' => 'Hacker', 'username' => 'hacker1', 'email' => 'hacker@example.com',
            'phone' => null, 'game_uid' => null, 'role' => 'admin',
            'password' => 'password', 'password_confirmation' => 'password',
        ])->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'hacker@example.com']);
    }

    public function test_tournament_status_and_organizer_cannot_be_mass_assigned(): void
    {
        $orgA = $this->makeUser('organizer');
        $orgB = $this->makeUser('organizer');
        $tournament = $this->makeTournament($orgA, 'draft');

        $this->actingAs($orgA)->put(route('tournaments.update', $tournament), [
            'name' => 'New Name', 'game_mode' => 'squad', 'map' => 'Bermuda',
            'entry_fee' => 100, 'prize_pool' => 5000, 'team_slots' => 8,
            'team_size' => 4, 'rules' => '', 'starts_at' => now()->addDay()->toDateTimeString(),
            'status' => 'finished', 'organizer_id' => $orgB->id, 'slug' => 'hacked-slug',
        ])->assertRedirect(route('tournaments.show', $tournament));

        $tournament->refresh();
        $this->assertSame('draft', $tournament->status);
        $this->assertSame($orgA->id, $tournament->organizer_id);
        $this->assertNotSame('hacked-slug', $tournament->slug);
        $this->assertSame('New Name', $tournament->name);
    }
}
