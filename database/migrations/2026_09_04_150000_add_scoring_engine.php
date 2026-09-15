<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 06 — Free Fire scoring engine.
     *
     * scoring_rules     : immutable, versioned rule-set snapshots per
     *                     tournament (placement points, kill points,
     *                     tie-breaker order).
     * score_adjustments : auditable bonus/penalty records tied to a score.
     * scores            : gains computed breakdown columns + a reference to
     *                     the rule snapshot that produced them.
     *
     * Historical integrity: every score stores its computed placement/kill/
     * bonus/penalty points and the scoring_rules_id snapshot it was computed
     * with, so later rule edits never rewrite historical results.
     */
    public function up(): void
    {
        Schema::create('scoring_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('name')->nullable();
            $table->unsignedInteger('kill_points')->default(1);
            $table->json('placement_points');
            $table->json('tie_breakers');
            $table->boolean('is_current')->default(true);
            $table->timestamps();

            $table->unique(['tournament_id', 'version'], 'scoring_rules_tournament_version_unique');
            $table->index('tournament_id', 'scoring_rules_tournament_index');
        });

        Schema::create('score_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('score_id')->constrained('scores')->cascadeOnDelete();
            $table->string('type'); // bonus | penalty
            $table->unsignedInteger('points');
            $table->string('reason');
            $table->timestamps();

            $table->index('score_id', 'score_adjustments_score_index');
        });

        Schema::table('scores', function (Blueprint $table) {
            $table->unsignedInteger('placement_points')->default(0);
            $table->unsignedInteger('kill_points')->default(0);
            $table->unsignedInteger('bonus_points')->default(0);
            $table->unsignedInteger('penalty_points')->default(0);
            $table->foreignId('scoring_rules_id')->nullable()->constrained('scoring_rules')->nullOnDelete();

            // A Free Fire placement is unique within a single match: two
            // teams can never finish in the same position.
            $table->unique(['match_id', 'placement'], 'scores_match_placement_unique');
        });

        $this->backfillLegacyData();
    }

    /**
     * Establish a default scoring version for every existing tournament and
     * backfill the computed breakdown for any existing score rows, so
     * historical results remain reproducible after the upgrade.
     *
     * The legacy behaviour (MatchController, Phases 01–05) was:
     *   placement points = [1=>12, 2=>9, 3=>7, 4=>5, 5=>4, 6=>3, 7=>2, 8=>1],
     *   any other placement => 1 point,
     *   kill points = 1 per kill,
     *   total = kills + placement points.
     */
    protected function backfillLegacyData(): void
    {
        $legacyPlacementPoints = [1 => 12, 2 => 9, 3 => 7, 4 => 5, 5 => 4, 6 => 3, 7 => 2, 8 => 1, 9 => 1, 10 => 1, 11 => 1, 12 => 1];
        $defaultTieBreakers = ['points', 'placement_points', 'kill_points', 'kills', 'best_placement'];

        $tournaments = DB::table('tournaments')->orderBy('id')->get();

        foreach ($tournaments as $tournament) {
            $ruleId = DB::table('scoring_rules')->insertGetId([
                'tournament_id' => $tournament->id,
                'version' => 1,
                'name' => 'Default Free Fire Rules',
                'kill_points' => 1,
                'placement_points' => json_encode($legacyPlacementPoints),
                'tie_breakers' => json_encode($defaultTieBreakers),
                'is_current' => true,
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]);

            $scores = DB::table('scores')
                ->join('matches', 'matches.id', '=', 'scores.match_id')
                ->where('matches.tournament_id', $tournament->id)
                ->select('scores.*')
                ->get();

            foreach ($scores as $score) {
                $placementPoints = $legacyPlacementPoints[$score->placement] ?? 1;
                $killPoints = (int) $score->kills * 1;

                DB::table('scores')->where('id', $score->id)->update([
                    'placement_points' => $placementPoints,
                    'kill_points' => $killPoints,
                    'bonus_points' => 0,
                    'penalty_points' => 0,
                    'scoring_rules_id' => $ruleId,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('scores', function (Blueprint $table) {
            $table->dropUnique('scores_match_placement_unique');
            $table->dropConstrainedForeignId('scoring_rules_id');
            $table->dropColumn(['placement_points', 'kill_points', 'bonus_points', 'penalty_points']);
        });

        Schema::dropIfExists('score_adjustments');
        Schema::dropIfExists('scoring_rules');
    }
};
