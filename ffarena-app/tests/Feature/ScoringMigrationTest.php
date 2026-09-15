<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 06 — schema/backfill verification for the scoring engine migration.
 */
class ScoringMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function makeTournament(User $organizer): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = 'Schema Tournament';
        $t->slug = 'schema-'.Str::random(8);
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = 0;
        $t->prize_pool = 1000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->starts_at = now()->addDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = 'live';
        $t->save();

        return $t;
    }

    protected function makeTeam(Tournament $tournament): Team
    {
        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->name = 'Team '.Str::random(6);
        $team->captain_name = 'Captain';
        $team->phone = '01700000000';
        $team->game_uid = 'UID'.rand(100000, 999999);
        $team->status = 'confirmed';
        $team->save();

        return $team;
    }

    public function test_scoring_rules_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('scoring_rules'));
        foreach (['id', 'tournament_id', 'version', 'name', 'kill_points', 'placement_points', 'tie_breakers', 'is_current', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('scoring_rules', $column), "Missing scoring_rules.$column");
        }
    }

    public function test_score_adjustments_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('score_adjustments'));
        foreach (['id', 'score_id', 'type', 'points', 'reason', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('score_adjustments', $column), "Missing score_adjustments.$column");
        }
    }

    public function test_scores_table_has_computed_columns(): void
    {
        foreach (['placement_points', 'kill_points', 'bonus_points', 'penalty_points', 'scoring_rules_id'] as $column) {
            $this->assertTrue(Schema::hasColumn('scores', $column), "Missing scores.$column");
        }
    }

    public function test_unique_match_placement_constraint_enforced(): void
    {
        $org = User::factory()->create();
        $org->role = 'organizer';
        $org->save();
        $tournament = $this->makeTournament($org);

        $team1 = $this->makeTeam($tournament);
        $team2 = $this->makeTeam($tournament);

        $match = new GameMatch();
        $match->tournament_id = $tournament->id;
        $match->round = 1;
        $match->match_no = 1;
        $match->team1_id = $team1->id;
        $match->team2_id = $team2->id;
        $match->status = 'live';
        $match->save();

        DB::table('scores')->insert([
            'match_id' => $match->id,
            'team_id' => $team1->id,
            'kills' => 1,
            'placement' => 1,
            'points' => 13,
            'placement_points' => 12,
            'kill_points' => 1,
            'bonus_points' => 0,
            'penalty_points' => 0,
            'status' => 'pending',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('scores')->insert([
            'match_id' => $match->id,
            'team_id' => $team2->id,
            'kills' => 2,
            'placement' => 1,
            'points' => 11,
            'placement_points' => 9,
            'kill_points' => 2,
            'bonus_points' => 0,
            'penalty_points' => 0,
            'status' => 'pending',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    public function test_unique_match_team_constraint_still_enforced(): void
    {
        $org = User::factory()->create();
        $org->role = 'organizer';
        $org->save();
        $tournament = $this->makeTournament($org);

        $team1 = $this->makeTeam($tournament);
        $team2 = $this->makeTeam($tournament);

        $match = new GameMatch();
        $match->tournament_id = $tournament->id;
        $match->round = 1;
        $match->match_no = 1;
        $match->team1_id = $team1->id;
        $match->team2_id = $team2->id;
        $match->status = 'live';
        $match->save();

        DB::table('scores')->insert([
            'match_id' => $match->id,
            'team_id' => $team1->id,
            'kills' => 1,
            'placement' => 1,
            'points' => 13,
            'placement_points' => 12,
            'kill_points' => 1,
            'bonus_points' => 0,
            'penalty_points' => 0,
            'status' => 'pending',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('scores')->insert([
            'match_id' => $match->id,
            'team_id' => $team1->id,
            'kills' => 9,
            'placement' => 2,
            'points' => 18,
            'placement_points' => 9,
            'kill_points' => 9,
            'bonus_points' => 0,
            'penalty_points' => 0,
            'status' => 'pending',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }
}
