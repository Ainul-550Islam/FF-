<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13 — central audit log + support/ticket system.
 *
 * audit_logs is an append-only admin/security trail (no updated_at column;
 * the model refuses updates/deletes). support_tickets / support_messages /
 * support_internal_notes back the support queue. No Phase 01–12 table is
 * altered here.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 60);
            $table->string('entity_type', 40)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->foreignId('tournament_id')->nullable()->constrained('tournaments')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('metadata')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->string('source', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('action', 'audit_logs_action_index');
            $table->index('entity_type', 'audit_logs_entity_type_index');
            $table->index('entity_id', 'audit_logs_entity_id_index');
            $table->index('tournament_id', 'audit_logs_tournament_index');
            $table->index('target_user_id', 'audit_logs_target_index');
            $table->index('request_id', 'audit_logs_request_index');
            $table->index('created_at', 'audit_logs_created_index');
        });

        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('tournament_id')->nullable()->constrained('tournaments')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('match_id')->nullable()->constrained('matches')->nullOnDelete();
            $table->string('subject', 255);
            $table->string('category', 30)->default('general');
            $table->string('priority', 12)->default('normal');
            $table->string('status', 20)->default('open');
            $table->unsignedInteger('reopened_count')->default(0);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index('user_id', 'support_tickets_user_index');
            $table->index('assigned_to', 'support_tickets_assigned_index');
            $table->index('status', 'support_tickets_status_index');
            $table->index('category', 'support_tickets_category_index');
            $table->index('priority', 'support_tickets_priority_index');
        });

        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->nullable();

            $table->index('ticket_id', 'support_messages_ticket_index');
        });

        Schema::create('support_internal_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->nullable();

            $table->index('ticket_id', 'support_internal_notes_ticket_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_internal_notes');
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('audit_logs');
    }
};
