<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 21 — UTM governance snapshots (reporting only).
 *
 * Aggregates marketing_attributions into period rows by normalized dimension
 * combination. The original attribution rows are never rewritten — snapshots
 * are derived, rebuildable and upserted idempotently per period. Empty-string
 * dimensions (rather than NULL) keep the uniqueness deterministic.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_utm_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('source', 120)->default('');
            $table->string('medium', 120)->default('');
            $table->string('campaign', 120)->default('');
            $table->string('content', 255)->default('');
            $table->string('term', 255)->default('');
            $table->unsignedInteger('touches')->default(0);
            $table->unsignedInteger('unique_visitors')->default(0);
            $table->unsignedInteger('conversions')->default(0);
            $table->unsignedInteger('attributed_users')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->unique(['period_start', 'period_end', 'source', 'medium', 'campaign', 'content', 'term']);
            $table->index(['period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_utm_snapshots');
    }
};
