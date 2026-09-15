<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14 — account ecosystem tables.
 *
 * Creates the identity-linking, phone-OTP, login-history and saved-payment-
 * method tables. No existing table is altered (profile columns and the live
 * feed target live in the follow-up migration), so Phases 01–13 remain
 * untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // user_identities — provider-linked identities (google, phone).
        //
        // The password provider deliberately has NO row here: the users.password
        // column IS the password identity, and a redundant row would duplicate
        // state. `(provider, provider_subject)` is unique so one Google subject
        // or one normalized phone can never be linked to two accounts.
        // ------------------------------------------------------------------
        Schema::create('user_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 20);              // google | phone
            $table->string('provider_subject', 255);     // Google `sub` | E.164 phone
            $table->string('provider_email', 255)->nullable(); // Google email (safe)
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->json('metadata')->nullable();        // safe subset only
            $table->timestamps();

            $table->unique(['provider', 'provider_subject'], 'user_identities_provider_subject_unique');
            $table->index('user_id', 'user_identities_user_index');
        });

        // ------------------------------------------------------------------
        // otp_challenges — one-time phone verification codes.
        //
        // Only a hash of the code is stored; the raw code exists solely in
        // transit (SMS/log). Challenges expire, cap attempts, and are tied to
        // a normalized phone number (and optionally the acting user).
        // ------------------------------------------------------------------
        Schema::create('otp_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('phone', 32);                 // normalized (E.164)
            $table->string('purpose', 20);               // login | signup | link | recovery
            $table->string('code_hash', 128);            // hash_hmac of the code
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->string('status', 20)->default('pending'); // pending | verified | expired | consumed
            $table->timestamps();

            $table->index('phone', 'otp_challenges_phone_index');
            $table->index('user_id', 'otp_challenges_user_index');
            $table->index('expires_at', 'otp_challenges_expiry_index');
        });

        // ------------------------------------------------------------------
        // login_events — append-only security/authentication history.
        //
        // Only pseudonymous IP/device hashes and a derived device label are
        // stored; raw IPs, raw fingerprints and passwords are never written.
        // ------------------------------------------------------------------
        Schema::create('login_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);                 // closed vocabulary
            $table->string('status', 20);                // success | failure
            $table->string('ip_hash', 64)->nullable();   // HMAC, never raw
            $table->string('device_hash', 64)->nullable();
            $table->string('device_label', 120)->nullable(); // derived browser/OS only
            $table->json('metadata')->nullable();        // safe subset
            $table->timestamp('created_at')->nullable();

            $table->index('user_id', 'login_events_user_index');
            $table->index('event', 'login_events_event_index');
            $table->index(['user_id', 'created_at'], 'login_events_user_created_index');
        });

        // ------------------------------------------------------------------
        // payment_methods — a user's saved payment methods.
        //
        // Only provider + a masked identifier are stored; card numbers, phone
        // numbers and account details are never persisted here.
        // ------------------------------------------------------------------
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 30);              // bkash | nagad | rocket | card | bank
            $table->string('label', 60);
            $table->string('masked_identifier', 40);     // "*******1234"
            $table->string('status', 20)->default('active'); // active | removed
            $table->boolean('is_default')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('user_id', 'payment_methods_user_index');
            $table->index('provider', 'payment_methods_provider_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('login_events');
        Schema::dropIfExists('otp_challenges');
        Schema::dropIfExists('user_identities');
    }
};
