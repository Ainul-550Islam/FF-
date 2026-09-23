<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 21 — experiment variants.
 *
 * allocation is the variant's share of the bucketed traffic (percentages of
 * the experiment's assigned identities, summed across variants <= 100).
 * configuration is the variant-specific payload (copy, theme key, ...) the
 * frontend may read — never secrets.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_experiment_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('experiment_id')->index();
            $table->string('key', 32);
            $table->string('name', 120);
            $table->unsignedTinyInteger('allocation')->default(50);
            $table->json('configuration')->nullable();
            $table->timestamps();

            $table->unique(['experiment_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_experiment_variants');
    }
};
