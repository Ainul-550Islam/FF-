<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 16 — operational heartbeats.
     *
     * A single row per operational source (scheduler, and queue workers if a
     * heartbeat command is later added) recording the last time it was seen
     * alive. Read by the readiness probe and the admin dashboard to detect a
     * stalled scheduler/worker without exposing infrastructure internals.
     */
    public function up(): void
    {
        Schema::create('operations_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->string('source', 64)->unique();
            $table->timestamp('last_beat_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operations_heartbeats');
    }
};
