<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Score;
use App\Models\ScoringRule;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\ScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 06 — scoring engine tests: calculation, configurable rules,
 * versioning/historical integrity, tie-breakers and leaderboard/standings.
 */
class ScoringEngineTest extends TestCase
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
        $t->name = $o['name'] ?? 'Scoring Tournament';
        $t->slug = $o['slug'] ?? ('scoring-'.Str::random(8));
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
    // Placement + kill scoring (legacy defaults preserved)
    // ------------------------------------------------------------------

    public function test_match_page_renders_with_scoring_breakdown(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->scoring()->submitScore($match, $teamA, 6, 1);

        $this->actingAs($org)
            ->get(route('matches.show', [$tournament, $match]))
            ->assertOk()
            ->assertSee('Submitted Scores')
            ->assertSee('Place Pts');
    }

    public function test_placement_one_default_is_twelve_points(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $score = $this->scoring()->submitScore($match, $teamA, 6, 1);

        $this->assertSame(6, (int) $score->kills);
        $this->assertSame(12, (int) $score->placement_points);
        $this->assertSame(6, (int) $score->kill_points);
        $this->assertSame(18, (int) $score->points);
    }

    public function test_placement_two_default_is_nine_points(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $score = $this->scoring()->submitScore($match, $teamA, 0, 2);

        $this->assertSame(9, (int) $score->placement_points);
        $this->assertSame(0, (int) $score->kill_points);
        $this->assertSame(9, (int) $score->points);
    }

    public function test_placement_three_default_is_seven_points(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $score = $this->scoring()->submitScore($match, $teamA, 0, 3);

        $this->assertSame(7, (int) $score->placement_points);
    }

    public function test_placement_eight_default_is_one_point(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $score = $this->scoring()->submitScore($match, $teamA, 0, 8);

        $this->assertSame(1, (int) $score->placement_points);
    }

    public function test_zero_kills_are_valid(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $score = $this->scoring()->submitScore($match, $teamA, 0, 1);

        $this->assertSame(0, (int) $score->kills);
        $this->assertSame(12, (int) $score->points);
    }

    public function test_multiple_kills_add_points(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $score = $this->scoring()->submitScore($match, $teamA, 10, 3);

        $this->assertSame(10, (int) $score->kill_points);
        $this->assertSame(17, (int) $score->points); // 10 + 7
    }

    // ------------------------------------------------------------------
    // Configurable rules
    // ------------------------------------------------------------------

    public function test_kill_points_are_configurable(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->scoring()->createVersion($tournament, [
            'name' => 'Double kill points',
            'kill_points' => 2,
            'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
            'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
        ]);

        $score = $this->scoring()->submitScore($match, $teamA, 5, 1);

        $this->assertSame(10, (int) $score->kill_points); // 5 * 2
        $this->assertSame(22, (int) $score->points);       // 10 + 12
    }

    public function test_placement_points_are_configurable(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $placement = ScoringRule::DEFAULT_PLACEMENT_POINTS;
        $placement[1] = 20;

        $this->scoring()->createVersion($tournament, [
            'name' => 'Big first place',
            'kill_points' => 1,
            'placement_points' => $placement,
            'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
        ]);

        $score = $this->scoring()->submitScore($match, $teamA, 0, 1);

        $this->assertSame(20, (int) $score->placement_points);
        $this->assertSame(20, (int) $score->points);
    }

    // ------------------------------------------------------------------
    // Bonuses & penalties
    // ------------------------------------------------------------------

    public function test_bonus_increases_total(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $score = $this->scoring()->submitScore($match, $teamA, 6, 1);
        $this->assertSame(18, (int) $score->points);

        $this->scoring()->addAdjustment($score, 'bonus', 5, 'Booyah bonus');

        $score->refresh();
        $this->assertSame(5, (int) $score->bonus_points);
        $this->assertSame(23, (int) $score->points);
    }

    public function test_penalty_decreases_total(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $score = $this->scoring()->submitScore($match, $teamA, 6, 1);

        $this->scoring()->addAdjustment($score, 'penalty', 3, 'Rules violation');

        $score->refresh();
        $this->assertSame(3, (int) $score->penalty_points);
        $this->assertSame(15, (int) $score->points);
    }

    public function test_total_is_never_negative(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        // Placement 12 worth 0, kills worth 0.
        $placement = array_fill(1, 12, 0);
        $this->scoring()->createVersion($tournament, [
            'name' => 'Zero points',
            'kill_points' => 0,
            'placement_points' => $placement,
            'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
        ]);

        $score = $this->scoring()->submitScore($match, $teamA, 0, 12);
        $this->assertSame(0, (int) $score->points);

        $this->scoring()->addAdjustment($score, 'penalty', 10, 'Penalty exceeding total');

        $score->refresh();
        $this->assertSame(0, (int) $score->points);
        $this->assertSame(10, (int) $score->penalty_points);
    }

    public function test_duplicate_placement_within_match_is_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->scoring()->submitScore($match, $teamA, 3, 1);

        $this->expectException(\DomainException::class);
        $this->scoring()->submitScore($match, $teamB, 4, 1);
    }

    // ------------------------------------------------------------------
    // Server-authoritative calculation
    // ------------------------------------------------------------------

    public function test_client_supplied_total_is_ignored(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->actingAs($org)->post(route('matches.score', [$tournament, $match]), [
            'team_id' => $teamA->id,
            'kills' => 6,
            'placement' => 1,
            'points' => 9999,          // ignored
            'placement_points' => 999, // ignored
        ])->assertRedirect();

        $score = Score::where('match_id', $match->id)->where('team_id', $teamA->id)->firstOrFail();
        $this->assertSame(18, (int) $score->points);
        $this->assertSame(12, (int) $score->placement_points);
    }

    // ------------------------------------------------------------------
    // Versioning / historical integrity
    // ------------------------------------------------------------------

    public function test_default_rule_set_is_created_lazily(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);

        $rule = $this->scoring()->currentRuleSet($tournament);

        $this->assertSame(1, (int) $rule->version);
        $this->assertTrue($rule->isCurrent());
        $this->assertSame(12, $rule->placementPointsFor(1));
        $this->assertSame(1, (int) $rule->kill_points);
    }

    public function test_new_version_becomes_current_and_old_keeps_history(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $v1 = $this->scoring()->currentRuleSet($tournament);

        $v2 = $this->scoring()->createVersion($tournament, [
            'name' => 'v2',
            'kill_points' => 2,
            'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
            'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
        ]);

        $this->assertSame(2, (int) $v2->version);
        $this->assertTrue($v2->fresh()->isCurrent());
        $this->assertFalse($v1->fresh()->isCurrent());
    }

    public function test_future_score_uses_current_version(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $v2 = $this->scoring()->createVersion($tournament, [
            'name' => 'v2',
            'kill_points' => 2,
            'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
            'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
        ]);

        $score = $this->scoring()->submitScore($match, $teamA, 5, 1);

        $this->assertSame($v2->id, $score->scoring_rules_id);
        $this->assertSame(10, (int) $score->kill_points);
    }

    public function test_historical_score_keeps_its_snapshot_after_rules_change(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $v1 = $this->scoring()->currentRuleSet($tournament);
        $score = $this->scoring()->submitScore($match, $teamA, 6, 1);
        $this->assertSame(18, (int) $score->points);

        // Change the rules for future matches.
        $this->scoring()->createVersion($tournament, [
            'name' => 'v2',
            'kill_points' => 5,
            'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
            'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
        ]);

        // Historical score is untouched and still references v1.
        $score->refresh();
        $this->assertSame($v1->id, $score->scoring_rules_id);
        $this->assertSame(18, (int) $score->points);
        $this->assertSame(6, (int) $score->kill_points);
    }

    public function test_activate_previous_version(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $v1 = $this->scoring()->currentRuleSet($tournament);

        $v2 = $this->scoring()->createVersion($tournament, [
            'name' => 'v2', 'kill_points' => 2,
            'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
            'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
        ]);
        $v3 = $this->scoring()->createVersion($tournament, [
            'name' => 'v3', 'kill_points' => 3,
            'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
            'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
        ]);

        $this->scoring()->activateVersion($tournament, $v1);

        $this->assertTrue($v1->fresh()->isCurrent());
        $this->assertFalse($v2->fresh()->isCurrent());
        $this->assertFalse($v3->fresh()->isCurrent());
    }

    public function test_versions_increment_sequentially(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);

        $this->assertSame(1, (int) $this->scoring()->currentRuleSet($tournament)->version);
        $this->assertSame(2, (int) $this->scoring()->createVersion($tournament, [
            'name' => 'a', 'kill_points' => 1,
            'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
            'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
        ])->version);
        $this->assertSame(3, (int) $this->scoring()->createVersion($tournament, [
            'name' => 'b', 'kill_points' => 1,
            'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
            'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
        ])->version);
    }

    // ------------------------------------------------------------------
    // Leaderboard / standings
    // ------------------------------------------------------------------

    public function test_leaderboard_aggregates_multiple_matches(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $teamC = $this->makeTeam($tournament, null);
        $teamD = $this->makeTeam($tournament, null);
        $m1 = $this->makeMatch($tournament, $teamA, $teamB);
        $m2 = $this->makeMatch($tournament, $teamA, $teamC);
        $this->makeMatch($tournament, $teamB, $teamD);

        $this->scoring()->submitScore($m1, $teamA, 3, 2); // 9 + 3 = 12
        $this->scoring()->submitScore($m2, $teamA, 5, 1); // 12 + 5 = 17

        $rows = $this->scoring()->standings($tournament);

        $rowA = $rows->firstWhere('team_id', $teamA->id);
        $this->assertNotNull($rowA);
        $this->assertSame(2, $rowA->matches_played);
        $this->assertSame(8, $rowA->kills);
        $this->assertSame(29, $rowA->points);
        $this->assertSame(1, $rowA->rank);
    }

    public function test_leaderboard_breaks_ties_by_configured_chain(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);

        // Kill points = 0, so two teams with the same placement tie on points,
        // placement points and kill points — only total kills differ.
        $this->scoring()->createVersion($tournament, [
            'name' => 'No kill points',
            'kill_points' => 0,
            'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
            'tie_breakers' => ['points', 'placement_points', 'kill_points', 'kills', 'best_placement'],
        ]);

        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $teamC = $this->makeTeam($tournament, null);
        $teamD = $this->makeTeam($tournament, null);
        $m1 = $this->makeMatch($tournament, $teamA, $teamC);
        $m2 = $this->makeMatch($tournament, $teamB, $teamD);

        $this->scoring()->submitScore($m1, $teamA, 5, 1); // 12 pts
        $this->scoring()->submitScore($m2, $teamB, 2, 1); // 12 pts

        $rows = $this->scoring()->standings($tournament);

        $this->assertSame(12, $rows[0]->points);
        $this->assertSame(12, $rows[1]->points);
        $this->assertSame($teamA->id, $rows[0]->team_id); // more kills
        $this->assertSame($teamB->id, $rows[1]->team_id);
    }

    public function test_identical_stats_rank_deterministically_by_team_id(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $teamC = $this->makeTeam($tournament, null);
        $teamD = $this->makeTeam($tournament, null);
        $m1 = $this->makeMatch($tournament, $teamA, $teamC);
        $m2 = $this->makeMatch($tournament, $teamB, $teamD);

        $this->scoring()->submitScore($m1, $teamA, 0, 1);
        $this->scoring()->submitScore($m2, $teamB, 0, 1);

        $rows = $this->scoring()->standings($tournament);

        $this->assertCount(2, $rows);
        // Identical inputs → identical ranking: lower team id first.
        $this->assertSame($teamA->id, $rows[0]->team_id);
        $this->assertSame($teamB->id, $rows[1]->team_id);
    }

    public function test_bye_match_scores_do_not_count(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $byeMatch = $this->makeMatch($tournament, $teamA, null, 'bye');
        $byeMatch->winner_team_id = $teamA->id;
        $byeMatch->save();

        $score = new Score();
        $score->match_id = $byeMatch->id;
        $score->team_id = $teamA->id;
        $score->kills = 5;
        $score->placement = 1;
        $score->placement_points = 12;
        $score->kill_points = 5;
        $score->points = 17;
        $score->status = 'pending';
        $score->save();

        $this->assertEmpty($this->scoring()->standings($tournament));
    }

    public function test_disputed_match_scores_excluded_until_resolved(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->scoring()->submitScore($match, $teamA, 2, 1);
        $this->assertCount(1, $this->scoring()->standings($tournament));

        $progression = app(\App\Services\MatchProgressionService::class);
        $progression->complete($match, $teamA);
        $progression->dispute($match);
        $this->assertEmpty($this->scoring()->standings($tournament));

        $progression->resolve($match, $teamA);
        $this->assertCount(1, $this->scoring()->standings($tournament));
    }

    public function test_cancelled_match_scores_do_not_count(): void
    {
        $org = $this->makeUser('organizer');
        $tournament = $this->makeTournament($org);
        $teamA = $this->makeTeam($tournament, null);
        $teamB = $this->makeTeam($tournament, null);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $this->scoring()->submitScore($match, $teamA, 2, 1);
        $this->assertCount(1, $this->scoring()->standings($tournament));

        $match->status = 'cancelled';
        $match->save();

        $this->assertEmpty($this->scoring()->standings($tournament));
    }
}
