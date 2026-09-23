<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('game_buddies')) {
            Schema::create('game_buddies', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('buddy_id')->constrained('users')->cascadeOnDelete();
                $table->string('status')->default('pending')->index(); // pending, accepted, blocked, removed
                $table->boolean('is_favorite')->default(false);
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('last_played_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'buddy_id']);
                $table->index(['user_id', 'status']);
                $table->index(['buddy_id', 'status']);
            });
        }

        if (! Schema::hasTable('user_online_statuses')) {
            Schema::create('user_online_statuses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
                $table->boolean('is_online')->default(false)->index();
                $table->boolean('hide_online_status')->default(false)->index(); // Gameberry feature
                $table->boolean('notify_friends_online')->default(true); // Gameberry: notifications when friends come online
                $table->boolean('is_in_auto_mode')->default(false)->index(); // Gameberry auto mode on disconnect
                $table->timestamp('last_online_at')->nullable();
                $table->timestamp('last_offline_at')->nullable();
                $table->string('current_game')->nullable();
                $table->string('current_table_code')->nullable();
                $table->json('device_info')->nullable();
                $table->timestamps();
                $table->index(['is_online', 'hide_online_status']);
            });
        }

        if (! Schema::hasTable('friend_notifications')) {
            Schema::create('friend_notifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // who gets notified
                $table->foreignId('friend_id')->constrained('users')->cascadeOnDelete(); // who came online
                $table->string('type')->index(); // friend_online, challenge_received, dice_gift, game_invite
                $table->boolean('is_read')->default(false)->index();
                $table->json('payload')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'is_read']);
            });
        }

        if (! Schema::hasTable('user_levels')) {
            Schema::create('user_levels', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
                $table->integer('level')->default(1)->index();
                $table->bigInteger('xp')->default(0);
                $table->bigInteger('xp_to_next_level')->default(1000);
                $table->integer('total_wins')->default(0);
                $table->integer('total_losses')->default(0);
                $table->integer('total_games')->default(0);
                $table->json('unlocked_features')->nullable(); // e.g. Bronze league at level 4
                $table->timestamps();
                $table->index(['level', 'xp']);
            });
        }

        if (! Schema::hasTable('referrals')) {
            Schema::create('referrals', function (Blueprint $table) {
                $table->id();
                $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('referred_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('code')->unique()->index(); // BGI20 etc
                $table->string('referred_email_or_phone')->nullable();
                $table->string('status')->default('pending')->index(); // pending, completed, rewarded
                $table->bigInteger('bonus_minor')->default(0); // ₹25 bonus per Gameberry
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('rewarded_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['referrer_id', 'status']);
            });
        }

        if (! Schema::hasTable('scratch_cards')) {
            Schema::create('scratch_cards', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('code')->unique();
                $table->string('type')->index(); // welcome, referral, win, event
                $table->bigInteger('reward_minor')->default(0);
                $table->integer('reward_gems')->default(0);
                $table->string('status')->default('unscratched')->index(); // unscratched, scratched, claimed, expired
                $table->timestamp('scratched_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scratch_cards');
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('user_levels');
        Schema::dropIfExists('friend_notifications');
        Schema::dropIfExists('user_online_statuses');
        Schema::dropIfExists('game_buddies');
    }
};
