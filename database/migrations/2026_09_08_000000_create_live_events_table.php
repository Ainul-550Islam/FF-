<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 — realtime / live updates.
 *
 * An append-only, monotonic event log that powers near-real-time tournament
 * pages (leaderboard, bracket) and live feeds. The auto-incrementing id is
 * the client cursor (`since`). Rows carry non-sensitive, public payloads;
 * staff-only event types are filtered by LiveEventService, never by trusting
 * the client. No Phase 01–11 table is touched.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('live_events', function (Blueprint $table) {
            $table->id();                                    // global monotonic cursor
            $table->foreignId('tournament_id')->nullable()->constrained('tournaments')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 40);                      // e.g. match.score_submitted
            $table->json('payload')->nullable();             // non-sensitive display data only
            $table->timestamp('created_at')->nullable();

            $table->index('tournament_id', 'live_events_tournament_index');
            $table->index(['tournament_id', 'id'], 'live_events_tournament_cursor_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_events');
    }
};
