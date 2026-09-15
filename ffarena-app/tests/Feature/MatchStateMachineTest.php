<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 05 — match state machine + bracket security tests.
 *
 * Covers the controlled pending/ready/live/completed/disputed/bye/cancelled
 * transitions, immutability of completed results without a privileged
 * dispute→resolve correction, idempotent advancement, and the authorization
 * boundaries around winner/result manipulation.
 */
class MatchStateMachineTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function makeTournament(User $organizer, string $status = 'live', array $o = []): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = $o['name'] ?? 'Match Tournament';
        $t->slug = $o['slug'] ?? ('match-'.Str::random(8));
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = 0;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->rules = null;
        $t->starts_at = now()->addDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function makeTeam(Tournament $tournament, ?User $captain = null, string $status = 'confirmed'): Team
    {
        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain?->id;
        $team->name = 'Team '.Str::random(6);
        $team->captain_name = $captain?->name ?? 'Captain';
        $team->phone = '01700000000';
        $team->game_uid = 'UID'.strtoupper(Str::random(8));
        $team->status = $status;
        $team->save();

        return $team;
    }

    protected function makeMatch(
        Tournament $tournament,
        Team $t1,
        ?Team $t2 = null,
        string $status = 'ready',
    ): GameMatch {
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

    // ------------------------------------------------------------------
    // Winner integrity
    // ------------------------------------------------------------------

    public function test_winner_must_be_a_participant(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournament, null);
        $t2 = $this->makeTeam($tournament, null);
        $outsider = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $t1, $t2, 'ready');

        $this->actingAs($org)->post(route('matches.winner', [$tournament, $match]), [
            'winner_team_id' => $outsider->id,
        ])->assertStatus(403);

        $this->assertNull($match->fresh()->winner_team_id);
        $this->assertSame('ready', $match->fresh()->status);
    }

    public function test_winner_from_other_tournament_is_forbidden(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $otherTournament = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournament, null);
        $t2 = $this->makeTeam($tournament, null);
        $foreignTeam = $this->makeTeam($otherTournament, null);
        $match = $this->makeMatch($tournament, $t1, $t2, 'ready');

        $this->actingAs($org)->post(route('matches.winner', [$tournament, $match]), [
            'winner_team_id' => $foreignTeam->id,
        ])->assertStatus(403);

        $this->assertNull($match->fresh()->winner_team_id);
    }

    public function test_match_winner_route_guards_against_cross_tournament_match(): void
    {
        $org = $this->makeUser('organizer');
        $tournamentA = $this->makeTournament($org);
        $tournamentB = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournamentA, null);
        $t2 = $this->makeTeam($tournamentA, null);
        $matchA = $this->makeMatch($tournamentA, $t1, $t2, 'ready');

        $this->actingAs($org)->post(route('matches.winner', [$tournamentB, $matchA]), [
            'winner_team_id' => $t1->id,
        ])->assertStatus(404);

        $this->assertNull($matchA->fresh()->winner_team_id);
    }

    public function test_player_cannot_set_winner(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournament, $player);
        $t2 = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $t1, $t2, 'ready');

        $this->actingAs($player)->post(route('matches.winner', [$tournament, $match]), [
            'winner_team_id' => $t1->id,
        ])->assertStatus(403);

        $this->assertNull($match->fresh()->winner_team_id);
    }

    public function test_other_organizer_cannot_set_winner(): void
    {
        $orgA = $this->makeUser('organizer');
        $orgB = $this->makeUser('organizer');
        $tournament = $this->makeTournament($orgA);
        $t1 = $this->makeTeam($tournament, null);
        $t2 = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $t1, $t2, 'ready');

        $this->actingAs($orgB)->post(route('matches.winner', [$tournament, $match]), [
            'winner_team_id' => $t1->id,
        ])->assertStatus(403);

        $this->assertNull($match->fresh()->winner_team_id);
    }

    // ------------------------------------------------------------------
    // State machine transitions
    // ------------------------------------------------------------------

    public function test_pending_match_cannot_be_completed(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournament, null);
        $t2 = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $t1, $t2, 'pending');

        $this->actingAs($org)->post(route('matches.winner', [$tournament, $match]), [
            'winner_team_id' => $t1->id,
        ])->assertSessionHas('error');

        $this->assertNull($match->fresh()->winner_team_id);
        $this->assertSame('pending', $match->fresh()->status);
    }

    public function test_bye_match_cannot_be_completed(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $t1, null, 'bye');
        $match->winner_team_id = $t1->id;
        $match->save();

        $this->actingAs($org)->post(route('matches.winner', [$tournament, $match]), [
            'winner_team_id' => $t1->id,
        ])->assertSessionHas('error');

        $this->assertSame('bye', $match->fresh()->status);
        $this->assertSame($t1->id, $match->fresh()->winner_team_id);
    }

    public function test_completing_again_with_same_winner_is_idempotent(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournament, null);
        $t2 = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $t1, $t2, 'ready');

        // A downstream destination to prove no duplicate advancement occurs.
        $next = new GameMatch();
        $next->tournament_id = $tournament->id;
        $next->round = 2;
        $next->match_no = 1;
        $next->status = 'pending';
        $next->save();
        $match->next_match_id = $next->id;
        $match->next_slot = 1;
        $match->save();

        $this->actingAs($org)->post(route('matches.winner', [$tournament, $match]), [
            'winner_team_id' => $t1->id,
        ])->assertRedirect();
        $this->assertSame($t1->id, $next->fresh()->team1_id);

        // Second completion with the same winner: no-op, no double advance.
        $this->actingAs($org)->post(route('matches.winner', [$tournament, $match]), [
            'winner_team_id' => $t1->id,
        ])->assertRedirect();

        $this->assertSame('completed', $match->fresh()->status);
        $this->assertSame($t1->id, $match->fresh()->winner_team_id);
        $this->assertSame($t1->id, $next->fresh()->team1_id);
        $this->assertNull($next->fresh()->team2_id);
    }

    public function test_completed_match_result_cannot_be_changed_without_dispute(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournament, null);
        $t2 = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $t1, $t2, 'ready');

        $this->actingAs($org)->post(route('matches.winner', [$tournament, $match]), [
            'winner_team_id' => $t1->id,
        ])->assertRedirect();
        $this->assertSame($t1->id, $match->fresh()->winner_team_id);

        // Attempt to silently change the completed result.
        $this->actingAs($org)->post(route('matches.winner', [$tournament, $match]), [
            'winner_team_id' => $t2->id,
        ])->assertSessionHas('error');

        $this->assertSame('completed', $match->fresh()->status);
        $this->assertSame($t1->id, $match->fresh()->winner_team_id);
    }

    public function test_only_completed_matches_can_be_disputed(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournament, null);
        $t2 = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $t1, $t2, 'live');

        $this->actingAs($org)->post(route('matches.dispute', [$tournament, $match]))->assertSessionHas('error');

        $this->assertSame('live', $match->fresh()->status);
    }

    public function test_only_disputed_matches_can_be_resolved(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournament, null);
        $t2 = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $t1, $t2, 'completed');
        $match->winner_team_id = $t1->id;
        $match->save();

        $this->actingAs($org)->post(route('matches.resolve', [$tournament, $match]), [
            'winner_team_id' => $t2->id,
        ])->assertSessionHas('error');

        $this->assertSame('completed', $match->fresh()->status);
        $this->assertSame($t1->id, $match->fresh()->winner_team_id);
    }

    // ------------------------------------------------------------------
    // Dispute → resolve correction flow
    // ------------------------------------------------------------------

    public function test_dispute_blocks_advancement_and_resolve_corrects_it(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournament, null);
        $t2 = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $t1, $t2, 'ready');

        $next = new GameMatch();
        $next->tournament_id = $tournament->id;
        $next->round = 2;
        $next->match_no = 1;
        $next->status = 'pending';
        $next->save();
        $match->next_match_id = $next->id;
        $match->next_slot = 1;
        $match->save();

        // Complete with t1 → t1 advanced to slot 1.
        $this->actingAs($org)->post(route('matches.winner', [$tournament, $match]), [
            'winner_team_id' => $t1->id,
        ])->assertRedirect();
        $this->assertSame($t1->id, $next->fresh()->team1_id);

        // Dispute.
        $this->actingAs($org)->post(route('matches.dispute', [$tournament, $match]))->assertSessionHas('success');
        $this->assertSame('disputed', $match->fresh()->status);

        // While disputed, no further advancement and no direct completion.
        $this->actingAs($org)->post(route('matches.winner', [$tournament, $match]), [
            'winner_team_id' => $t2->id,
        ])->assertSessionHas('error');
        $this->assertSame('disputed', $match->fresh()->status);
        $this->assertSame($t1->id, $next->fresh()->team1_id);

        // Resolve with a corrected winner → downstream slot replaced.
        $this->actingAs($org)->post(route('matches.resolve', [$tournament, $match]), [
            'winner_team_id' => $t2->id,
        ])->assertSessionHas('success');

        $this->assertSame('completed', $match->fresh()->status);
        $this->assertSame($t2->id, $match->fresh()->winner_team_id);
        $this->assertSame($t2->id, $next->fresh()->team1_id);
    }

    public function test_resolve_rejects_non_participant_winner(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournament, null);
        $t2 = $this->makeTeam($tournament, null);
        $outsider = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $t1, $t2, 'disputed');
        $match->winner_team_id = $t1->id;
        $match->save();

        $this->actingAs($org)->post(route('matches.resolve', [$tournament, $match]), [
            'winner_team_id' => $outsider->id,
        ])->assertStatus(403);

        $this->assertSame('disputed', $match->fresh()->status);
        $this->assertSame($t1->id, $match->fresh()->winner_team_id);
    }

    // ------------------------------------------------------------------
    // Dispute/resolve authorization
    // ------------------------------------------------------------------

    public function test_player_cannot_dispute_or_resolve(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $t1 = $this->makeTeam($tournament, $player);
        $t2 = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $t1, $t2, 'completed');
        $match->winner_team_id = $t1->id;
        $match->save();

        $this->actingAs($player)->post(route('matches.dispute', [$tournament, $match]))->assertStatus(403);
        $this->assertSame('completed', $match->fresh()->status);

        $match->status = 'disputed';
        $match->save();

        $this->actingAs($player)->post(route('matches.resolve', [$tournament, $match]), [
            'winner_team_id' => $t2->id,
        ])->assertStatus(403);

        $this->assertSame('disputed', $match->fresh()->status);
        $this->assertSame($t1->id, $match->fresh()->winner_team_id);
    }

    public function test_other_organizer_cannot_dispute(): void
    {
        $orgA = $this->makeUser('organizer');
        $orgB = $this->makeUser('organizer');
        $tournament = $this->makeTournament($orgA);
        $t1 = $this->makeTeam($tournament, null);
        $t2 = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $t1, $t2, 'completed');
        $match->winner_team_id = $t1->id;
        $match->save();

        $this->actingAs($orgB)->post(route('matches.dispute', [$tournament, $match]))->assertStatus(403);

        $this->assertSame('completed', $match->fresh()->status);
    }
}
