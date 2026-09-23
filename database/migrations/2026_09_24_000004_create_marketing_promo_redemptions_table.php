<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 21 — promo code redemptions (the idempotency ledger).
 *
 * One row per (promo code, user): a repeat apply returns the same row instead
 * of double-counting. The stored discount is the server-computed minor-unit
 * amount at apply time; payment settlement is never derived from it here.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_promo_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_code_id')->index();
            $table->foreignId('user_id')->index();
            $table->foreignId('tournament_id')->nullable()->index();
            $table->integer('discount_minor')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['promo_code_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_promo_redemptions');
    }
};
