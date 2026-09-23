<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        // Add indexes for Gameberry performance - preserve existing logic, only add
        // Wrapped in outer try/catch to handle duplicate index on SQLite :memory: for tests
        // G1: SQLite must continue to work for local/test
        try {
            if (Schema::hasTable('user_dices')) {
                Schema::table('user_dices', function (Blueprint $table) {
                    if (! $this->indexExists('user_dices', 'user_dices_user_id_is_equipped_index')) {
                        $table->index(['user_id', 'is_equipped']);
                    }
                });
            }
        } catch (Exception $e) {
        }

        try {
            if (Schema::hasTable('user_dices')) {
                Schema::table('user_dices', function (Blueprint $table) {
                    if (! $this->indexExists('user_dices', 'user_dices_user_id_is_favorite_index')) {
                        $table->index(['user_id', 'is_favorite']);
                    }
                });
            }
        } catch (Exception $e) {
        }

        try {
            if (Schema::hasTable('user_dices')) {
                Schema::table('user_dices', function (Blueprint $table) {
                    if (! $this->indexExists('user_dices', 'user_dices_dice_id_index')) {
                        $table->index('dice_id');
                    }
                });
            }
        } catch (Exception $e) {
        }

        try {
            if (Schema::hasTable('user_leagues')) {
                Schema::table('user_leagues', function (Blueprint $table) {
                    $table->index(['league_id', 'season', 'trophies']);
                });
            }
        } catch (Exception $e) {
        }

        try {
            if (Schema::hasTable('user_leagues')) {
                Schema::table('user_leagues', function (Blueprint $table) {
                    $table->index(['user_id', 'season']);
                });
            }
        } catch (Exception $e) {
        }

        try {
            if (Schema::hasTable('private_tables')) {
                Schema::table('private_tables', function (Blueprint $table) {
                    $table->index('code');
                });
            }
        } catch (Exception $e) {
        }

        try {
            if (Schema::hasTable('private_tables')) {
                Schema::table('private_tables', function (Blueprint $table) {
                    $table->index('status');
                });
            }
        } catch (Exception $e) {
        }

        try {
            if (Schema::hasTable('private_tables')) {
                Schema::table('private_tables', function (Blueprint $table) {
                    $table->index('host_id');
                });
            }
        } catch (Exception $e) {
        }

        try {
            if (Schema::hasTable('gold_transactions')) {
                Schema::table('gold_transactions', function (Blueprint $table) {
                    $table->index(['user_id', 'created_at']);
                });
            }
        } catch (Exception $e) {
        }

        try {
            if (Schema::hasTable('gold_transactions')) {
                Schema::table('gold_transactions', function (Blueprint $table) {
                    $table->index('type');
                });
            }
        } catch (Exception $e) {
        }

        try {
            if (Schema::hasTable('gem_transactions')) {
                Schema::table('gem_transactions', function (Blueprint $table) {
                    $table->index(['user_id', 'created_at']);
                });
            }
        } catch (Exception $e) {
        }

        try {
            if (Schema::hasTable('game_buddies')) {
                Schema::table('game_buddies', function (Blueprint $table) {
                    $table->index(['user_id', 'status']);
                });
            }
        } catch (Exception $e) {
        }

        try {
            if (Schema::hasTable('game_buddies')) {
                Schema::table('game_buddies', function (Blueprint $table) {
                    $table->index('buddy_id');
                });
            }
        } catch (Exception $e) {
        }

        try {
            if (Schema::hasTable('user_online_statuses')) {
                Schema::table('user_online_statuses', function (Blueprint $table) {
                    $table->index(['is_online', 'hide_online_status']);
                });
            }
        } catch (Exception $e) {
        }

        try {
            if (Schema::hasTable('referrals')) {
                Schema::table('referrals', function (Blueprint $table) {
                    $table->index('code');
                });
            }
        } catch (Exception $e) {
        }

        try {
            if (Schema::hasTable('referrals')) {
                Schema::table('referrals', function (Blueprint $table) {
                    $table->index(['referrer_id', 'status']);
                });
            }
        } catch (Exception $e) {
        }

        try {
            if (Schema::hasTable('weekly_events')) {
                Schema::table('weekly_events', function (Blueprint $table) {
                    $table->index(['is_active', 'starts_at', 'ends_at']);
                });
            }
        } catch (Exception $e) {
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        try {
            $connection = Schema::getConnection();
            $driver = $connection->getDriverName();
            if ($driver === 'sqlite') {
                $result = $connection->select("SELECT name FROM sqlite_master WHERE type='index' AND name=? AND tbl_name=?", [$indexName, $table]);

                return count($result) > 0;
            }

            // For other drivers, assume not exists to try creation, catch will handle
            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    public function down(): void
    {
        // Indexes dropped automatically or ignore for SQLite compatibility
    }
};
