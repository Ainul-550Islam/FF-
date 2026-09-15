<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enforces one score row per team per match at the database level.
     * This prevents duplicate/race-condition score submissions which would
     * otherwise allow unauthorized score replacement.
     */
    public function up(): void
    {
        Schema::table('scores', function (Blueprint $table) {
            $table->unique(['match_id', 'team_id'], 'scores_match_team_unique');
        });
    }

    public function down(): void
    {
        Schema::table('scores', function (Blueprint $table) {
            $table->dropUnique('scores_match_team_unique');
        });
    }
};
