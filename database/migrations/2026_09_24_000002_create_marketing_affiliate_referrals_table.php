<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 21 — attributed affiliate referrals.
 *
 * One click row per (affiliate, anonymous visitor); the referred user is
 * credited exactly once (unique referred_user_id), server-side, when the
 * visitor registers. attribution_id links the referral to the matching
 * marketing_attributions row so affiliate and campaign reporting agree.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_affiliate_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->index();
            $table->string('anonymous_id', 64)->nullable()->index();
            $table->foreignId('referred_user_id')->nullable()->unique();
            $table->foreignId('attribution_id')->nullable();
            $table->string('landing_path', 500)->nullable();
            $table->string('status', 20)->default('clicked');
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('signed_up_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['affiliate_id', 'anonymous_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_affiliate_referrals');
    }
};
