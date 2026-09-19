<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        // Additional session tracking for auto mode and disconnect handling
        if (Schema::hasTable('user_levels')) {
            Schema::table('user_levels', function (Blueprint $table) {
                if (!Schema::hasColumn('user_levels', 'last_level_up_at')) {
                    $table->timestamp('last_level_up_at')->nullable();
                }
                if (!Schema::hasColumn('user_levels', 'level_up_rewards_claimed')) {
                    $table->json('level_up_rewards_claimed')->nullable();
                }
            });
        }
        if (Schema::hasTable('private_tables')) {
            Schema::table('private_tables', function (Blueprint $table) {
                if (!Schema::hasColumn('private_tables', 'team_a_score')) {
                    $table->integer('team_a_score')->default(0);
                }
                if (!Schema::hasColumn('private_tables', 'team_b_score')) {
                    $table->integer('team_b_score')->default(0);
                }
                if (!Schema::hasColumn('private_tables', 'winning_team')) {
                    $table->string('winning_team')->nullable();
                }
            });
        }
    }
    public function down(): void {
        if (Schema::hasTable('user_levels')) {
            Schema::table('user_levels', function (Blueprint $table) {
                $table->dropColumn(['last_level_up_at', 'level_up_rewards_claimed']);
            });
        }
        if (Schema::hasTable('private_tables')) {
            Schema::table('private_tables', function (Blueprint $table) {
                $table->dropColumn(['team_a_score', 'team_b_score', 'winning_team']);
            });
        }
    }
};
