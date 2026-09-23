<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('player_stats')) {
            Schema::create('player_stats', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
                $table->integer('total_games')->default(0);
                $table->integer('wins')->default(0);
                $table->integer('losses')->default(0);
                $table->integer('draws')->default(0);
                $table->integer('total_trophies')->default(0);
                $table->integer('highest_trophies')->default(0);
                $table->integer('win_streak')->default(0);
                $table->integer('best_win_streak')->default(0);
                $table->integer('total_gold_won')->default(0);
                $table->integer('total_gold_lost')->default(0);
                $table->integer('total_gems_earned')->default(0);
                $table->string('favorite_game_mode')->default('classic');
                $table->integer('total_play_time_minutes')->default(0);
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('game_sessions')) {
            Schema::create('game_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('private_table_id')->nullable()->constrained()->nullOnDelete();
                $table->string('game_mode')->default('classic');
                $table->string('game_variation')->default('classic');
                $table->integer('bet_amount')->default(100);
                $table->string('result')->default('pending');
                $table->integer('gold_change')->default(0);
                $table->integer('gem_change')->default(0);
                $table->integer('trophies_change')->default(0);
                $table->integer('duration_seconds')->default(0);
                $table->boolean('is_team_up')->default(false);
                $table->string('team')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'game_mode']);
            });
        }
        if (! Schema::hasTable('spin_wheels')) {
            Schema::create('spin_wheels', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->integer('cost_gold')->default(100);
                $table->boolean('is_active')->default(true);
                $table->json('rewards_config')->nullable();
                $table->integer('daily_free_spins')->default(1);
                $table->integer('max_spins_per_day')->default(10);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('spin_wheels');
        Schema::dropIfExists('game_sessions');
        Schema::dropIfExists('player_stats');
    }
};
