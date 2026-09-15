<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 18 — mobile push-token registration.
     *
     * Additive, read-mostly registry of a user's mobile devices for push
     * notification delivery. The RAW push token is never persisted — only a
     * SHA-256 hash — and the row is never exposed to other users.
     */
    public function up(): void
    {
        Schema::create('mobile_device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 20);           // android | ios
            $table->string('provider', 20);            // fcm | apns
            $table->string('token_hash', 64)->index(); // sha256(raw token)
            $table->string('device_label', 120)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'token_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_device_tokens');
    }
};
