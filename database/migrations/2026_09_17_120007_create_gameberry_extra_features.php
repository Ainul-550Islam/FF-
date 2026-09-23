<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('game_modes')) {
            Schema::create('game_modes', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->integer('max_players')->default(4);
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->json('config')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('team_up_matches')) {
            Schema::create('team_up_matches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('private_table_id')->constrained()->cascadeOnDelete();
                $table->foreignId('team_a_user1_id')->nullable()->constrained('users');
                $table->foreignId('team_a_user2_id')->nullable()->constrained('users');
                $table->foreignId('team_b_user1_id')->nullable()->constrained('users');
                $table->foreignId('team_b_user2_id')->nullable()->constrained('users');
                $table->string('status')->default('waiting');
                $table->string('winning_team')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('auto_mode_settings')) {
            Schema::create('auto_mode_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
                $table->boolean('is_enabled')->default(false);
                $table->boolean('auto_on_disconnect')->default(true);
                $table->boolean('auto_on_afk')->default(false);
                $table->integer('afk_timeout_seconds')->default(60);
                $table->string('strategy')->default('safe');
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('gold_at_stake')) {
            Schema::create('gold_at_stake', function (Blueprint $table) {
                $table->id();
                $table->foreignId('private_table_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->integer('amount');
                $table->string('status')->default('pending');
                $table->integer('won_amount')->default(0);
                $table->integer('refunded_amount')->default(0);
                $table->timestamp('settled_at')->nullable();
                $table->timestamps();
                $table->index(['private_table_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gold_at_stake');
        Schema::dropIfExists('auto_mode_settings');
        Schema::dropIfExists('team_up_matches');
        Schema::dropIfExists('game_modes');
    }
};
