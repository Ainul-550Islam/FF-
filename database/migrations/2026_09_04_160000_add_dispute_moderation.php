<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 07 — dispute + evidence + moderation + audit trail.
     *
     * disputes          : a participant/staff-opened contest of a match result,
     *                     with a controlled state machine.
     * dispute_evidence  : immutable evidence records attached to a dispute.
     *                     Files are stored on the private `local` disk and
     *                     served only through an authorized controller.
     * moderation_events : append-only audit trail for dispute/moderation
     *                     actions and result corrections.
     *
     * matches           : gains `completed_at` (dispute-window anchor).
     * tournaments       : gains `dispute_window_hours` (participant dispute
     *                     window; 0 disables participant disputes; staff
     *                     always bypass).
     */
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category');
            $table->text('description');
            $table->string('status')->default('open'); // open|under_review|resolved|rejected|cancelled
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolution_winner_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('tournament_id', 'disputes_tournament_index');
            $table->index('match_id', 'disputes_match_index');
            $table->index('status', 'disputes_status_index');
            $table->index('assigned_to', 'disputes_assigned_index');
        });

        Schema::create('dispute_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->constrained('disputes')->cascadeOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type'); // image|video|document|text
            $table->string('path')->nullable(); // private disk path (null for text)
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('dispute_id', 'dispute_evidence_dispute_index');
        });

        Schema::create('moderation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event');
            $table->foreignId('dispute_id')->nullable()->constrained('disputes')->nullOnDelete();
            $table->foreignId('match_id')->nullable()->constrained('matches')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('dispute_id', 'moderation_events_dispute_index');
            $table->index('match_id', 'moderation_events_match_index');
            $table->index('event', 'moderation_events_event_index');
        });

        Schema::table('matches', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('scheduled_at');
        });

        Schema::table('tournaments', function (Blueprint $table) {
            $table->unsignedInteger('dispute_window_hours')->default(24)->after('format');
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn('dispute_window_hours');
        });

        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('completed_at');
        });

        Schema::dropIfExists('moderation_events');
        Schema::dropIfExists('dispute_evidence');
        Schema::dropIfExists('disputes');
    }
};
