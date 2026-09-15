<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 — in-app + email notifications.
 *
 * One row per recipient (notifications are personal, never broadcast rows).
 * `read_at` marks the in-app read state; `data` carries structured, non-
 * sensitive metadata (ids for deep-links). No Phase 01–10 table is touched.
 *
 * SQLite-compatible: foreign keys, indexes, and no destructive changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 40);                    // e.g. payment.verified
            $table->string('title', 160);
            $table->text('body');
            $table->string('link', 255)->nullable();       // in-app deep link
            $table->json('data')->nullable();              // structured ids (no secrets)
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index('user_id', 'notifications_user_index');
            $table->index(['user_id', 'read_at'], 'notifications_user_read_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
