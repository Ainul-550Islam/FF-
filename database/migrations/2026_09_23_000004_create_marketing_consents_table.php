<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 20 — marketing / analytics consent ledger.
 *
 * Append-only history: every grant, change and withdrawal is a row carrying
 * the policy version it applied to. The latest row per visitor/user is the
 * current consent state; trackers are only ever loaded after a granted row.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_consents', function (Blueprint $table) {
            $table->id();
            $table->string('anonymous_id', 64)->nullable()->index();
            $table->foreignId('user_id')->nullable()->index();
            $table->boolean('analytics_consent')->default(false);
            $table->boolean('marketing_consent')->default(false);
            $table->string('policy_version', 20)->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_consents');
    }
};
