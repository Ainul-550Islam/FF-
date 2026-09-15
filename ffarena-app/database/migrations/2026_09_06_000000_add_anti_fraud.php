<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10 — anti-fraud + anti-cheat + identity + device/IP + ban-evasion.
     *
     * risk_profiles         : per-user server-side risk profile (score, level,
     *                         flags, manual-review flag, restriction state).
     * risk_events           : append-only suspicious/signal audit events.
     * devices / device_links: pseudonymous device identities (server-derived
     *                         hashes, never raw fingerprints) and their user
     *                         associations.
     * ip_intel / ip_links   : privacy-aware IP intelligence (hashed IPs +
     *                         hashed subnet grouping) and user associations.
     * account_links         : defensive account-similarity links (weak /
     *                         moderate / strong) with reasons.
     * restrictions          : granular, auditable account restrictions.
     * identity_verifications: identity-verification state machine
     *                         (unverified/pending/verified/rejected/expired/
     *                         review_required) with a provider abstraction —
     *                         never fabricated.
     * anti_cheat_incidents  : defensive anti-cheat cases with controlled
     *                         status machine (flagged/under_review/cleared/
     *                         confirmed/restricted/dismissed).
     * match_anomalies       : deterministic, append-only match anomaly
     *                         signals (never auto-labelled "cheating").
     *
     * No existing table or column is modified. No sensitive raw identifiers
     * (raw IPs, raw device fingerprints, documents, credentials) are stored.
     */
    public function up(): void
    {
        Schema::create('risk_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('risk_score')->default(0);
            $table->string('risk_level', 12)->default('low'); // low|medium|high|critical
            $table->json('account_flags')->nullable();
            $table->boolean('manual_review_required')->default(false);
            $table->timestamp('restricted_until')->nullable();
            $table->string('status', 12)->default('active'); // active|restricted|suspended
            $table->timestamp('last_risk_calculation_at')->nullable();
            $table->timestamps();
        });

        Schema::create('risk_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tournament_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40);
            $table->string('severity', 12); // info|low|medium|high|critical
            $table->unsignedInteger('score_contribution')->default(0);
            $table->string('source', 20);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('user_id', 'risk_events_user_index');
            $table->index('type', 'risk_events_type_index');
            $table->index('severity', 'risk_events_severity_index');
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_hash', 64)->unique();
            $table->string('status', 12)->default('active'); // active|blocked
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('device_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'user_id'], 'device_links_device_user_unique');
        });

        Schema::create('ip_intel', function (Blueprint $table) {
            $table->id();
            $table->string('ip_hash', 64)->unique();
            $table->string('subnet_hash', 64)->index();
            $table->unsignedInteger('observation_count')->default(0);
            $table->unsignedInteger('suspicious_count')->default(0);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ip_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ip_intel_id')->constrained('ip_intel')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['ip_intel_id', 'user_id'], 'ip_links_ip_user_unique');
        });

        Schema::create('account_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('linked_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('strength', 12); // weak|moderate|strong
            $table->json('reasons')->nullable();
            $table->string('source', 20);
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'linked_user_id'], 'account_links_pair_unique');
            $table->index('user_id', 'account_links_user_index');
            $table->index('linked_user_id', 'account_links_linked_index');
        });

        Schema::create('restrictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('reason');
            $table->string('source', 20);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('status', 12)->default('active'); // active|lifted
            $table->foreignId('lifted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('lifted_at')->nullable();
            $table->timestamps();

            $table->index('user_id', 'restrictions_user_index');
            $table->index(['type', 'status'], 'restrictions_type_status_index');
        });

        Schema::create('identity_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('unverified'); // unverified|pending|verified|rejected|expired|review_required
            $table->string('provider', 30)->default('manual');
            $table->string('provider_reference', 80)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('anti_cheat_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('match_id')->nullable()->constrained('matches')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('accused_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reporter_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 20); // system|participant|staff
            $table->string('category', 30);
            $table->string('severity', 12); // low|medium|high|critical
            $table->text('description')->nullable();
            $table->string('evidence_reference', 120)->nullable();
            $table->string('status', 16)->default('flagged'); // flagged|under_review|cleared|confirmed|restricted|dismissed
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('tournament_id', 'anti_cheat_tournament_index');
            $table->index('status', 'anti_cheat_status_index');
        });

        Schema::create('match_anomalies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40); // abnormal_kill_ratio|repeated_pattern|unexpected_participation
            $table->string('severity', 12); // anomaly|suspicious|requires_review
            $table->string('status', 12)->default('anomaly'); // anomaly|suspicious|requires_review
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('match_id', 'match_anomalies_match_index');
            $table->index('tournament_id', 'match_anomalies_tournament_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_anomalies');
        Schema::dropIfExists('anti_cheat_incidents');
        Schema::dropIfExists('identity_verifications');
        Schema::dropIfExists('restrictions');
        Schema::dropIfExists('account_links');
        Schema::dropIfExists('ip_links');
        Schema::dropIfExists('ip_intel');
        Schema::dropIfExists('device_links');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('risk_events');
        Schema::dropIfExists('risk_profiles');
    }
};
