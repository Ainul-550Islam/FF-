<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Payment;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Authorized happy-path workflows — proves that legitimate actors can still
 * complete their full journey after the security hardening (nothing over-blocked).
 */
class AuthorizedWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function makeTournament(User $organizer, string $status = 'open', int $entryFee = 100): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = 'Workflow Tournament';
        $t->slug = 'workflow-' . Str::random(8);
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = $entryFee;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->rules = null;
        $t->starts_at = now()->addDay();
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function makeTeam(Tournament $tournament, ?User $captain = null, string $status = 'confirmed'): Team
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

    public function test_organizer_full_tournament_lifecycle(): void
    {
        $organizer = $this->makeUser('organizer');

        // 1. Create (draft)
        $this->actingAs($organizer)->post(route('tournaments.store'), [
            'name' => 'Squad Finals', 'game_mode' => 'squad', 'map' => 'Bermuda',
            'entry_fee' => 100, 'prize_pool' => 5000, 'team_slots' => 8,
            'team_size' => 4, 'rules' => 'No cheats', 'starts_at' => now()->addDays(2)->toDateTimeString(),
        ])->assertRedirect();

        $tournament = Tournament::where('name', 'Squad Finals')->firstOrFail();
        $this->assertSame('draft', $tournament->status);
        $this->assertSame($organizer->id, $tournament->organizer_id);

        // 2. Publish → open
        $this->actingAs($organizer)->post(route('tournaments.publish', $tournament))->assertRedirect();
        $this->assertSame('open', $tournament->fresh()->status);

        // 3. Close registration → closed
        $this->actingAs($organizer)->post(route('tournaments.close', $tournament))->assertRedirect();
        $this->assertSame('closed', $tournament->fresh()->status);
    }

    public function test_player_registration_payment_and_admin_verification_flow(): void
    {
        $admin = $this->makeUser('admin');
        $organizer = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer, 'open', 100);

        // Player registers a team
        $this->actingAs($player)->post(route('teams.store', $tournament), [
            'name' => 'Challengers', 'captain_name' => $player->name,
            'phone' => '01711111111', 'game_uid' => 'UID123456',
            'members' => [['player_name' => 'SquadMate', 'game_uid' => 'UID999']],
        ])->assertRedirect(route('payment.show', [$tournament, $team = Team::where('name', 'Challengers')->firstOrFail()]));

        $this->assertSame('pending', $team->status);
        $this->assertSame($player->id, $team->captain_id);
        $this->assertSame(1, $team->members()->count());

        // Player pays entry fee (bKash mock)
        $this->actingAs($player)->post(route('payment.verify', [$tournament, $team]), [
            'bkash_number' => '01711111111', 'trx_id' => 'BTRX12345',
        ])->assertRedirect();

        $payment = Payment::where('team_id', $team->id)->firstOrFail();
        $this->assertSame('pending', $payment->status);
        $this->assertSame(100.0, (float) $payment->amount); // amount from tournament, not client
        $this->assertSame('pending', $team->fresh()->status);

        // Admin verifies → team confirmed
        $this->actingAs($admin)->post(route('admin.payments.verify', $payment))->assertRedirect();
        $this->assertSame('verified', $payment->fresh()->status);
        $this->assertSame('confirmed', $team->fresh()->status);
    }

    public function test_organizer_generates_bracket_and_advances_winner(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer, 'open', 0);

        $t1 = $this->makeTeam($tournament, null);
        $t2 = $this->makeTeam($tournament, null);
        $t3 = $this->makeTeam($tournament, null);
        $t4 = $this->makeTeam($tournament, null);

        $this->actingAs($organizer)->post(route('tournaments.bracket', $tournament))->assertRedirect();

        $this->assertSame('live', $tournament->fresh()->status);
        $this->assertSame(3, GameMatch::where('tournament_id', $tournament->id)->count());

        $round1 = GameMatch::where('tournament_id', $tournament->id)->where('round', 1)->where('match_no', 1)->firstOrFail();
        $this->assertSame($t1->id, $round1->team1_id);
        $this->assertSame($t2->id, $round1->team2_id);

        // Organizer declares t1 winner → advances into the round-2 placeholder
        $this->actingAs($organizer)->post(route('matches.winner', [$tournament, $round1]), [
            'winner_team_id' => $t1->id,
        ])->assertRedirect();

        $this->assertSame($t1->id, $round1->fresh()->winner_team_id);
        $this->assertSame('completed', $round1->fresh()->status);

        $final = GameMatch::where('tournament_id', $tournament->id)->where('round', 2)->where('match_no', 1)->firstOrFail();
        $this->assertSame($t1->id, $final->team1_id);
    }

    public function test_captain_submits_score_and_leaderboard_reflects_it(): void
    {
        $organizer = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer, 'live', 0);

        $teamA = $this->makeTeam($tournament, $player);
        $teamB = $this->makeTeam($tournament, null);

        $match = new GameMatch();
        $match->tournament_id = $tournament->id;
        $match->round = 1;
        $match->match_no = 1;
        $match->team1_id = $teamA->id;
        $match->team2_id = $teamB->id;
        $match->status = 'live';
        $match->save();

        $this->actingAs($player)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $teamA->id, 'kills' => 6, 'placement' => 1,
        ])->assertRedirect();

        // 6 kills + placement 1 (12 pts) = 18 points
        $score = \App\Models\Score::where('match_id', $match->id)->where('team_id', $teamA->id)->firstOrFail();
        $this->assertSame(18, (int) $score->points);

        $this->actingAs($player)->get(route('leaderboard.show', $tournament))
            ->assertOk()
            ->assertSee($teamA->name);
    }
}
