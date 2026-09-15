<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enforces the one-team-per-captain rule at the database level.
     *
     * A captain may register at most one team per tournament. Because
     * captain_id is nullable (seeded demo teams have no captain), SQLite
     * treats NULLs as distinct, so multiple null-captain rows per
     * tournament remain allowed — exactly the behaviour we want.
     *
     * SQLite does not support ALTER TABLE ADD CONSTRAINT, but Laravel's
     * SQLite grammar compiles a unique index on an existing table as
     * CREATE UNIQUE INDEX, which enforces the same rule.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->unique(['tournament_id', 'captain_id'], 'teams_tournament_captain_unique');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropUnique('teams_tournament_captain_unique');
        });
    }
};
