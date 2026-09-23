<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 21 — A/B experiments.
 *
 * traffic_allocation is the percentage of eligible identities bucketed into
 * the experiment (0-100); the rest see the default product untouched.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_experiments', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name', 190);
            $table->string('description', 500)->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->unsignedTinyInteger('traffic_allocation')->default(100);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_experiments');
    }
};
