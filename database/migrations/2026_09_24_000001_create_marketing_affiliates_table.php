<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 21 — marketing affiliate program (partner accounts).
 *
 * An affiliate is a partner whose referral code drives acquisition. The code
 * doubles as the attribution identity: every /r/{code} click lands as a
 * utm_campaign=code touch in marketing_attributions, so affiliate performance
 * is readable through the existing funnel without a parallel attribution
 * system. Deliberately separate from the Gameberry user-to-user Referral
 * bonus program — this is acquisition-partner tracking, not wallet rewards.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_affiliates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('code', 24)->unique();
            $table->string('name', 120)->nullable();
            $table->string('status', 20)->default('active');
            $table->string('landing_url', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('last_conversion_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_affiliates');
    }
};
