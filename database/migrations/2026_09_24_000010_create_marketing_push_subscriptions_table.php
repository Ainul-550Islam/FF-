<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 21 — marketing push re-engagement subscriptions.
 *
 * Mirrors the mobile_device_tokens safety model: the endpoint/secret keys are
 * stored encrypted at rest and identified by a SHA-256 hash (endpoint_hash is
 * the unique lookup key). One row per endpoint — resubscribing an existing
 * endpoint refreshes and re-activates it instead of duplicating.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 10)->default('fcm');
            $table->string('endpoint', 500);
            $table->string('endpoint_hash', 64)->unique();
            $table->text('encrypted_keys')->nullable();
            $table->string('anonymous_id', 64)->nullable()->index();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('user_agent', 255)->nullable();
            $table->json('topics')->nullable();
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamps();

            $table->index(['revoked_at', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_push_subscriptions');
    }
};
