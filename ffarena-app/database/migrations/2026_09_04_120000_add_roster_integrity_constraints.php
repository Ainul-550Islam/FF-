<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Roster integrity constraints for Phase 03.
     *
     * 1. teams (tournament_id, game_uid) UNIQUE
     *    — prevents two teams in the SAME tournament sharing a captain UID.
     *
     * 2. team_members (team_id, game_uid) UNIQUE
     *    — prevents the same player UID appearing twice in ONE team.
     *
     * Both are compiled by Laravel's SQLite grammar as CREATE UNIQUE INDEX
     * (SQLite has no ALTER TABLE ADD CONSTRAINT). NULL game_uid values are
     * treated as distinct by SQLite/MySQL, so seeded teams without a UID
     * remain valid.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->unique(['tournament_id', 'game_uid'], 'teams_tournament_game_uid_unique');
        });

        Schema::table('team_members', function (Blueprint $table) {
            $table->unique(['team_id', 'game_uid'], 'team_members_team_uid_unique');
        });
    }

    public function down(): void
    {
        Schema::table('team_members', function (Blueprint $table) {
            $table->dropUnique('team_members_team_uid_unique');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropUnique('teams_tournament_game_uid_unique');
        });
    }
};
