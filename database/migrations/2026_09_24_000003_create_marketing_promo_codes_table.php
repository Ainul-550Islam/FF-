<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 21 — promo code definitions.
 *
 * value is type-scoped: a percentage (1-100) for type=percent, integer minor
 * units (poisha) for type=fixed. Amounts always stay integer minor units and
 * the discount is always recomputed server-side from the tournament's own
 * entry fee — client totals are never trusted.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_promo_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('type', 20)->default('percent');
            $table->integer('value');
            $table->string('description', 255)->nullable();
            $table->integer('min_entry_fee_minor')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->integer('max_redemptions')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_promo_codes');
    }
};
