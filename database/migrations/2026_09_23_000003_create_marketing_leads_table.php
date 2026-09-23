<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 20 — lead capture (newsletter / organizer / contact / partner).
 *
 * Leads are the top of the acquisition funnel. Each lead records the
 * acquisition source it arrived with and carries its own unsubscribe token
 * so every future lifecycle email has a working, tokenised opt-out.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_leads', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('name', 120)->nullable();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('type', 30)->default('newsletter')->index();
            $table->string('source', 120)->nullable();
            $table->string('medium', 120)->nullable();
            $table->string('campaign', 120)->nullable();
            $table->json('metadata')->nullable();
            $table->string('unsubscribe_token', 64)->unique();
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_leads');
    }
};
