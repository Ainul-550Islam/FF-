<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        // Fix user_leagues schema to support both old and new test expectations
        if (Schema::hasTable('user_leagues')) {
            Schema::table('user_leagues', function (Blueprint $table) {
                if (! Schema::hasColumn('user_leagues', 'season')) {
                    $table->integer('season')->nullable()->index();
                }
                if (! Schema::hasColumn('user_leagues', 'rank')) {
                    $table->integer('rank')->nullable()->index();
                }
                if (! Schema::hasColumn('user_leagues', 'games_played')) {
                    $table->integer('games_played')->default(0);
                }
                if (! Schema::hasColumn('user_leagues', 'is_in_top_20')) {
                    $table->boolean('is_in_top_20')->default(false);
                }
                if (! Schema::hasColumn('user_leagues', 'current_streak')) {
                    $table->integer('current_streak')->default(0);
                }
            });
        }

        // Fix titan_badges to support test expectations
        if (Schema::hasTable('titan_badges')) {
            Schema::table('titan_badges', function (Blueprint $table) {
                if (! Schema::hasColumn('titan_badges', 'season')) {
                    $table->integer('season')->nullable();
                }
                if (! Schema::hasColumn('titan_badges', 'week')) {
                    $table->integer('week')->nullable();
                }
                if (! Schema::hasColumn('titan_badges', 'rank')) {
                    $table->integer('rank')->nullable();
                }
                if (! Schema::hasColumn('titan_badges', 'badge_type')) {
                    $table->string('badge_type')->default('weekly_titan');
                }
            });
        }

        // Fix private_tables
        if (Schema::hasTable('private_tables')) {
            Schema::table('private_tables', function (Blueprint $table) {
                if (! Schema::hasColumn('private_tables', 'host_id')) {
                    $table->unsignedBigInteger('host_id')->nullable();
                }
                if (! Schema::hasColumn('private_tables', 'game_mode')) {
                    $table->string('game_mode')->default('classic');
                }
                if (! Schema::hasColumn('private_tables', 'bet_amount')) {
                    $table->bigInteger('bet_amount')->default(0);
                }
                if (! Schema::hasColumn('private_tables', 'is_private')) {
                    $table->boolean('is_private')->default(true);
                }
            });
        }

        // Fix private_table_participants
        if (Schema::hasTable('private_table_participants')) {
            Schema::table('private_table_participants', function (Blueprint $table) {
                if (! Schema::hasColumn('private_table_participants', 'auto_mode_on_at')) {
                    $table->timestamp('auto_mode_on_at')->nullable();
                }
                if (! Schema::hasColumn('private_table_participants', 'auto_mode_off_at')) {
                    $table->timestamp('auto_mode_off_at')->nullable();
                }
            });
        }

        // Ensure gold_wallets and gem_wallets exist
        if (! Schema::hasTable('gold_wallets')) {
            Schema::create('gold_wallets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->bigInteger('balance')->default(5000);
                $table->bigInteger('total_earned')->default(5000);
                $table->bigInteger('total_spent')->default(0);
                $table->bigInteger('total_won')->default(0);
                $table->bigInteger('total_lost')->default(0);
                $table->timestamps();
                $table->unique('user_id');
            });
        }

        if (! Schema::hasTable('gem_wallets')) {
            Schema::create('gem_wallets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->integer('balance')->default(10);
                $table->integer('total_earned')->default(10);
                $table->integer('total_spent')->default(0);
                $table->timestamps();
                $table->unique('user_id');
            });
        }

        if (! Schema::hasTable('gold_transactions')) {
            Schema::create('gold_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('type')->index();
                $table->bigInteger('amount');
                $table->bigInteger('balance_after');
                $table->string('reference_type')->nullable();
                $table->string('reference_id')->nullable();
                $table->text('description')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('gem_transactions')) {
            Schema::create('gem_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('type')->index();
                $table->integer('amount');
                $table->integer('balance_after');
                $table->string('reference_type')->nullable();
                $table->string('reference_id')->nullable();
                $table->text('description')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('auto_mode_logs')) {
            Schema::create('auto_mode_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('private_table_id')->nullable()->constrained('private_tables')->nullOnDelete();
                $table->string('reason')->default('disconnect');
                $table->boolean('is_auto_on')->default(true);
                $table->timestamp('auto_on_at')->nullable();
                $table->timestamp('auto_off_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('user_online_statuses')) {
            Schema::table('user_online_statuses', function (Blueprint $table) {
                if (! Schema::hasColumn('user_online_statuses', 'is_in_auto_mode')) {
                    $table->boolean('is_in_auto_mode')->default(false);
                }
                if (! Schema::hasColumn('user_online_statuses', 'hide_online_status')) {
                    $table->boolean('hide_online_status')->default(false);
                }
                if (! Schema::hasColumn('user_online_statuses', 'notify_friends_online')) {
                    $table->boolean('notify_friends_online')->default(true);
                }
            });
        } else {
            Schema::create('user_online_statuses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->boolean('is_online')->default(false);
                $table->boolean('is_in_auto_mode')->default(false);
                $table->boolean('hide_online_status')->default(false);
                $table->boolean('notify_friends_online')->default(true);
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();
                $table->unique('user_id');
            });
        }
    }

    public function down(): void {}
};
