<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 04 — check-in + waitlist fields.
     *
     * tournaments:
     *   - check_in_starts_at / check_in_ends_at  (optional check-in window)
     *
     * teams:
     *   - checked_in_at  (check-in timestamp; NULL = not checked in)
     *   - checked_in_by  (who performed check-in, for audit)
     *   - waitlisted_at  (FIFO ordering key; NULL = not waitlisted)
     *   - index on (tournament_id, waitlisted_at) for fast FIFO promotion
     *
     * A team's waitlist / check-in state is kept separate from its
     * registration status (pending/confirmed) so no single column is
     * overloaded with unrelated meanings.
     */
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->timestamp('check_in_starts_at')->nullable()->after('starts_at');
            $table->timestamp('check_in_ends_at')->nullable()->after('check_in_starts_at');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->timestamp('checked_in_at')->nullable()->after('status');
            $table->foreignId('checked_in_by')->nullable()->after('checked_in_at')->constrained('users')->nullOnDelete();
            $table->timestamp('waitlisted_at')->nullable()->after('checked_in_by');
            $table->index(['tournament_id', 'waitlisted_at'], 'teams_tournament_waitlisted_index');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropIndex('teams_tournament_waitlisted_index');
            $table->dropConstrainedForeignId('checked_in_by');
            $table->dropColumn(['checked_in_at', 'waitlisted_at']);
        });

        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn(['check_in_starts_at', 'check_in_ends_at']);
        });
    }
};
