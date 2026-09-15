<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14 — profile fields on users + user-targeted live events.
 *
 * Adds self-managed profile/preference/account-lifecycle columns to `users`
 * (all nullable/defaulted so existing rows and seeded accounts keep working)
 * and a nullable `target_user_id` to `live_events` so account-level realtime
 * signals (session revoked, payment status, verification status) can be
 * delivered to exactly one user.
 */
return new class extends Migration
{
    public function up(): void
    {
        // OAuth-only (Google) accounts have no password until the user sets
        // one; the column becomes nullable for exactly that case.
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->text('bio')->nullable()->after('game_uid');
            $table->string('country', 2)->nullable()->after('bio');
            $table->string('region', 100)->nullable()->after('country');
            $table->string('avatar', 255)->nullable()->after('region');
            $table->string('language', 5)->default('en')->after('avatar');
            $table->string('timezone', 64)->default('UTC')->after('language');
            $table->string('privacy', 20)->default('public')->after('timezone'); // public | registered | private
            $table->string('account_status', 20)->default('active')->after('privacy'); // active | deactivated | deletion_pending
            $table->timestamp('deactivated_at')->nullable()->after('account_status');
            $table->timestamp('username_changed_at')->nullable()->after('deactivated_at');
        });

        Schema::table('live_events', function (Blueprint $table) {
            $table->foreignId('target_user_id')->nullable()->after('actor_user_id')
                ->constrained('users')->nullOnDelete();

            $table->index('target_user_id', 'live_events_target_user_index');
        });
    }

    public function down(): void
    {
        Schema::table('live_events', function (Blueprint $table) {
            $table->dropIndex('live_events_target_user_index');
            $table->dropConstrainedForeignId('target_user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'bio', 'country', 'region', 'avatar', 'language', 'timezone',
                'privacy', 'account_status', 'deactivated_at', 'username_changed_at',
            ]);
        });

        // Restore NOT NULL on password (only when rolling back in a fresh
        // environment without OAuth-only accounts).
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
