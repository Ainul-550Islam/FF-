<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 20 — conversion event pipeline.
 *
 * Server-side conversion events (register_complete, payment_success, …) and
 * consented client-side acquisition events land here. Every row optionally
 * links to the attribution touch it can be credited to, so campaign revenue
 * reporting is a join, not a guess.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_events', function (Blueprint $table) {
            $table->id();
            $table->string('anonymous_id', 64)->nullable()->index();
            $table->foreignId('user_id')->nullable()->index();
            $table->foreignId('attribution_id')->nullable()->index();
            $table->string('name', 60)->index();
            $table->json('properties')->nullable();
            $table->string('url', 500)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_events');
    }
};
