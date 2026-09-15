<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 19 — per-category push-channel notification preferences.
     *
     * One row per user. The booleans govern ONLY the push channel: in-app
     * notifications and email are unaffected. `push_security` is always
     * enforced server-side (it can never be disabled) so critical account
     * signals always reach the user.
     */
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('push_tournament')->default(true);
            $table->boolean('push_match')->default(true);
            $table->boolean('push_team')->default(true);
            $table->boolean('push_payment')->default(true);
            $table->boolean('push_payout')->default(true);
            $table->boolean('push_dispute')->default(true);
            $table->boolean('push_security')->default(true);
            $table->boolean('push_support')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
