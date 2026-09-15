<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 05 — bracket structure.
     *
     * tournaments:
     *   - format        : single_elim | double_elim (only implemented formats
     *                     may ever be selected)
     *   - bracket_size  : the bracket size used at generation (audit/display)
     *
     * matches:
     *   - bracket               : winners | losers | grand_final
     *   - next_match_id         : explicit winner destination
     *   - next_slot             : 1 (team1) or 2 (team2) in the destination
     *   - loser_next_match_id   : explicit loser destination (double elimination)
     *   - loser_slot            : 1 or 2 in the loser destination
     *
     * This replaces the old `ceil(match_no / 2)` arithmetic advancement with
     * an explicit dependency graph.
     */
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->string('format')->default('single_elim')->after('status');
            $table->unsignedInteger('bracket_size')->nullable()->after('format');
        });

        Schema::table('matches', function (Blueprint $table) {
            $table->string('bracket')->default('winners')->after('status');
            $table->foreignId('next_match_id')->nullable()->after('bracket')->constrained('matches')->nullOnDelete();
            $table->unsignedTinyInteger('next_slot')->nullable()->after('next_match_id');
            $table->foreignId('loser_next_match_id')->nullable()->after('next_slot')->constrained('matches')->nullOnDelete();
            $table->unsignedTinyInteger('loser_slot')->nullable()->after('loser_next_match_id');
            $table->index('next_match_id', 'matches_next_match_index');
            $table->index('loser_next_match_id', 'matches_loser_next_match_index');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropIndex('matches_next_match_index');
            $table->dropIndex('matches_loser_next_match_index');
            $table->dropConstrainedForeignId('loser_next_match_id');
            $table->dropColumn('loser_slot');
            $table->dropConstrainedForeignId('next_match_id');
            $table->dropColumn('next_slot');
            $table->dropColumn('bracket');
        });

        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn(['format', 'bracket_size']);
        });
    }
};
