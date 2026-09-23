<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Score;
use App\Models\ScoringRule;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\MatchProgressionService;
use App\Services\ScoringService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 06 — scoring security, validation and match-state integration.
 */
class ScoringSecurityTest extends TestCase
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
        $t->name = $o['name'] ?? 'Scoring Security Tournament';
        $t->slug = $o['slug'] ?? ('scoring-sec-'.Str::random(8));
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

    protected function scoring(): ScoringService
    {
        return app(ScoringService::class);
    }

    // ------------------------------------------------------------------
    // Rule management authorization
    // ------------------------------------------------------------------

    public function test_organizer_can_view_scoring_rules_page(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);

        $this->actingAs($org)
            ->get(route('tournaments.scoring.show', $tournament))
            ->assertOk()
            ->assertSee('Scoring Rules');
    }

    public function test_participant_cannot_view_scoring_rules(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org);

        $this->actingAs($player)
            ->get(route('tournaments.scoring.show', $tournament))
            ->assertStatus(403);
    }

    public function test_participant_cannot_create_scoring_version(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org);

        $this->actingAs($player)
            ->post(route('tournaments.scoring.store', $tournament), [
                'kill_points' => 1,
                'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
                'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
            ])
            ->assertStatus(403);

        $this->assertSame(0, $tournament->scoringRules()->count());
    }

    public function test_other_organizer_cannot_manage_another_tournaments_rules(): void
    {
        $orgA = $this->makeUser('organizer');
        $orgB = $this->makeUser('organizer');
        $tournament = $this->makeTournament($orgA);

        $this->actingAs($orgB)
            ->get(route('tournaments.scoring.show', $tournament))
            ->assertStatus(403);

        $this->actingAs($orgB)
            ->post(route('tournaments.scoring.store', $tournament), [
                'kill_points' => 1,
                'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
                'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
            ])
            ->assertStatus(403);
    }

    public function test_organizer_can_manage_own_scoring_rules(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);

        $this->actingAs($org)
            ->post(route('tournaments.scoring.store', $tournament), [
                'name' => 'My rules',
                'kill_points' => 2,
                'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
                'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
            ])
            ->assertSessionHas('success');

        $this->assertSame(1, $tournament->scoringRules()->count());
        $this->assertSame(2, (int) $this->scoring()->currentRuleSet($tournament)->kill_points);
    }

    public function test_admin_can_manage_scoring_rules(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $tournament = $this->makeTournament($org);

        $this->actingAs($admin)
            ->post(route('tournaments.scoring.store', $tournament), [
                'kill_points' => 3,
                'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
                'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
            ])
            ->assertSessionHas('success');

        $this->assertSame(3, (int) $this->scoring()->currentRuleSet($tournament)->kill_points);
    }

    public function test_client_cannot_inject_rule_version_or_tournament(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $otherOrg = $this->makeUser('organizer');
        $other = $this->makeTournament($otherOrg, 'live', ['name' => 'Other']);

        $this->actingAs($org)
            ->post(route('tournaments.scoring.store', $tournament), [
                'kill_points' => 1,
                'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
                'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
                'version' => 99,           // ignored
                'is_current' => false,     // ignored
                'tournament_id' => $other->id, // ignored
            ])
            ->assertSessionHas('success');

        $rule = $tournament->scoringRules()->first();
        $this->assertSame(1, (int) $rule->version);
        $this->assertSame($tournament->id, $rule->tournament_id);
        $this->assertTrue((bool) $rule->is_current);
    }

    // ------------------------------------------------------------------
    // Rule input validation
    // ------------------------------------------------------------------

    public function test_negative_kill_points_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);

        $this->actingAs($org)
            ->post(route('tournaments.scoring.store', $tournament), [
                'kill_points' => -1,
                'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
                'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
            ])
            ->assertSessionHasErrors('kill_points');
    }

    public function test_negative_placement_points_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);

        $placement = ScoringRule::DEFAULT_PLACEMENT_POINTS;
        $placement[1] = -3;

        $this->actingAs($org)
            ->post(route('tournaments.scoring.store', $tournament), [
                'kill_points' => 1,
                'placement_points' => $placement,
                'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
            ])
            ->assertSessionHasErrors('placement_points.1');
    }

    public function test_invalid_tie_breaker_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);

        $this->actingAs($org)
            ->post(route('tournaments.scoring.store', $tournament), [
                'kill_points' => 1,
                'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
                'tie_breakers' => ['nonsense'],
            ])
            ->assertSessionHasErrors('tie_breakers.0');
    }

    // ------------------------------------------------------------------
    // Score submission validation
    // ------------------------------------------------------------------

    public function test_negative_kills_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->actingAs($org)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $teamA->id, 'kills' => -5, 'placement' => 1,
        ])->assertSessionHasErrors('kills');
    }

    public function test_non_integer_kills_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->actingAs($org)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $teamA->id, 'kills' => 'abc', 'placement' => 1,
        ])->assertSessionHasErrors('kills');
    }

    public function test_placement_zero_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->actingAs($org)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $teamA->id, 'kills' => 0, 'placement' => 0,
        ])->assertSessionHasErrors('placement');
    }

    public function test_placement_above_max_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->actingAs($org)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $teamA->id, 'kills' => 0, 'placement' => 13,
        ])->assertSessionHasErrors('placement');
    }

    public function test_duplicate_score_submission_blocked(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->actingAs($org)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $teamA->id, 'kills' => 3, 'placement' => 2,
        ])->assertRedirect();

        $this->actingAs($org)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $teamA->id, 'kills' => 9, 'placement' => 1,
        ])->assertStatus(403);

        $this->assertSame(1, Score::where('match_id', $match->id)->where('team_id', $teamA->id)->count());
    }

    public function test_duplicate_placement_blocks_second_team(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->actingAs($org)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $teamA->id, 'kills' => 3, 'placement' => 1,
        ])->assertRedirect();

        $this->actingAs($org)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $teamB->id, 'kills' => 4, 'placement' => 1,
        ])->assertStatus(403);
    }

    public function test_database_unique_constraint_backstop(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->scoring()->submitScore($match, $teamA, 1, 1);

        $this->expectException(QueryException::class);
        // Bypass the service and hit the DB unique constraint directly.
        DB::table('scores')->insert([
            'match_id' => $match->id,
            'team_id' => $teamA->id,
            'kills' => 2,
            'placement' => 2,
            'points' => 0,
            'status' => 'pending',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    // ------------------------------------------------------------------
    // Match-state integration
    // ------------------------------------------------------------------

    public function test_score_submission_blocked_when_completed(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB, 'completed');
        $match->winner_team_id = $teamA->id;
        $match->save();

        $this->actingAs($org)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $teamA->id, 'kills' => 3, 'placement' => 1,
        ])->assertStatus(403);
    }

    public function test_score_submission_blocked_when_pending(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB, 'pending');

        $this->actingAs($org)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $teamA->id, 'kills' => 3, 'placement' => 1,
        ])->assertStatus(403);
    }

    public function test_score_submission_blocked_when_bye(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, null, 'bye');
        $match->winner_team_id = $teamA->id;
        $match->save();

        $this->actingAs($org)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $teamA->id, 'kills' => 3, 'placement' => 1,
        ])->assertStatus(403);
    }

    public function test_adjustment_blocked_after_match_finalized(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $score = $this->scoring()->submitScore($match, $teamA, 3, 1);
        $this->assertSame(15, (int) $score->points);

        app(MatchProgressionService::class)->complete($match, $teamA);

        $this->actingAs($org)->post(route('matches.adjustment', [$tournament, $match]), [
            'team_id' => $teamA->id, 'type' => 'bonus', 'points' => 5, 'reason' => 'late bonus',
        ])->assertSessionHas('error');

        $score->refresh();
        $this->assertSame(15, (int) $score->points);
        $this->assertSame(0, (int) $score->bonus_points);
    }

    public function test_player_cannot_add_adjustment(): void
    {
        $org = $this->makeUser('organizer');
        $player = $this->makeUser('player');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, $player);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->scoring()->submitScore($match, $teamA, 1, 1);

        $this->actingAs($player)->post(route('matches.adjustment', [$tournament, $match]), [
            'team_id' => $teamA->id, 'type' => 'bonus', 'points' => 5, 'reason' => 'hack',
        ])->assertStatus(403);
    }

    public function test_adjustment_requires_reason_and_valid_type(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->scoring()->submitScore($match, $teamA, 1, 1);

        $this->actingAs($org)->post(route('matches.adjustment', [$tournament, $match]), [
            'team_id' => $teamA->id, 'type' => 'bonus', 'points' => 5, 'reason' => '',
        ])->assertSessionHasErrors('reason');

        $this->actingAs($org)->post(route('matches.adjustment', [$tournament, $match]), [
            'team_id' => $teamA->id, 'type' => 'swiss', 'points' => 5, 'reason' => 'x',
        ])->assertSessionHasErrors('type');
    }

    public function test_cross_tournament_adjustment_forbidden(): void
    {
        $org = $this->makeUser('organizer');
        $tournamentA = $this->makeTournament($org);
        $tournamentB = $this->makeTournament($org, 'live', ['name' => 'Other', 'slug' => 'other-'.Str::random(6)]);
        $teamA = $this->makeTeam($tournamentA, null);
        $teamB = $this->makeTeam($tournamentA, null);
        $matchA = $this->makeMatch($tournamentA, $teamA, $teamB);

        $this->scoring()->submitScore($matchA, $teamA, 1, 1);

        $this->actingAs($org)->post(route('matches.adjustment', [$tournamentB, $matchA]), [
            'team_id' => $teamA->id, 'type' => 'bonus', 'points' => 5, 'reason' => 'x',
        ])->assertStatus(404);
    }
}
