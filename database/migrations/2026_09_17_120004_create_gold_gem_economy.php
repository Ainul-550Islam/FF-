<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gold_wallets')) {
            Schema::create('gold_wallets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
                $table->bigInteger('gold_balance')->default(1000); // Starting gold per Gameberry
                $table->bigInteger('total_earned')->default(0);
                $table->bigInteger('total_spent')->default(0);
                $table->bigInteger('total_won')->default(0);
                $table->bigInteger('total_lost')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('gem_wallets')) {
            Schema::create('gem_wallets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
                $table->integer('gem_balance')->default(10); // Starting gems
                $table->integer('total_earned')->default(0);
                $table->integer('total_spent')->default(0);
                $table->integer('total_purchased')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('gold_transactions')) {
            Schema::create('gold_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('gold_wallet_id')->constrained('gold_wallets')->cascadeOnDelete();
                $table->string('type')->index(); // bet, win, loss, magic_chest, video_ad, purchase, gift, referral, scratch_card, weekly_event, refund
                $table->bigInteger('amount'); // positive for credit, negative for debit
                $table->bigInteger('balance_after');
                $table->string('reference_type')->nullable()->index();
                $table->string('reference_id')->nullable()->index();
                $table->string('description')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'type']);
                $table->index(['user_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('gem_transactions')) {
            Schema::create('gem_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('gem_wallet_id')->constrained('gem_wallets')->cascadeOnDelete();
                $table->string('type')->index(); // purchase, win, spin2win, lucky_dice, magic_chest, video_ad, referral, weekly_event, dice_purchase, reroll
                $table->integer('amount'); // positive credit, negative debit
                $table->integer('balance_after');
                $table->string('reference_type')->nullable()->index();
                $table->string('reference_id')->nullable()->index();
                $table->string('description')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'type']);
                $table->index(['user_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('magic_chests')) {
            Schema::create('magic_chests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('type')->default('free')->index(); // free, premium, titan
                $table->string('status')->default('available')->index(); // available, opened, expired
                $table->bigInteger('gold_reward')->default(0);
                $table->integer('gem_reward')->default(0);
                $table->json('dice_rewards')->nullable(); // array of dice ids
                $table->timestamp('available_at')->useCurrent();
                $table->timestamp('opened_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'status']);
            });
        }

        if (! Schema::hasTable('video_ad_rewards')) {
            Schema::create('video_ad_rewards', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('ad_provider')->default('admob')->index();
                $table->string('status')->default('pending')->index(); // pending, completed, failed, rewarded
                $table->bigInteger('gold_reward')->default(0);
                $table->integer('gem_reward')->default(0);
                $table->integer('daily_count')->default(0);
                $table->integer('daily_limit')->default(5); // Gameberry daily limit
                $table->timestamp('watched_at')->nullable();
                $table->timestamp('rewarded_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'status']);
                $table->index(['user_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('spin2win_rewards')) {
            Schema::create('spin2win_rewards', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('result')->index(); // gold, gems, dice, jackpot
                $table->bigInteger('gold_amount')->default(0);
                $table->integer('gem_amount')->default(0);
                $table->foreignId('dice_id')->nullable()->constrained('dices')->nullOnDelete();
                $table->bigInteger('gold_cost')->default(0); // cost to spin
                $table->timestamp('spun_at')->useCurrent();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('auto_mode_logs')) {
            Schema::create('auto_mode_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('private_table_id')->nullable()->constrained('private_tables')->nullOnDelete();
                $table->string('reason')->index(); // disconnect, missed_turn, manual
                $table->boolean('is_auto_on')->default(true);
                $table->timestamp('auto_on_at')->useCurrent();
                $table->timestamp('auto_off_at')->nullable();
                $table->integer('turns_in_auto')->default(0);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_mode_logs');
        Schema::dropIfExists('spin2win_rewards');
        Schema::dropIfExists('video_ad_rewards');
        Schema::dropIfExists('magic_chests');
        Schema::dropIfExists('gem_transactions');
        Schema::dropIfExists('gold_transactions');
        Schema::dropIfExists('gem_wallets');
        Schema::dropIfExists('gold_wallets');
    }
};
