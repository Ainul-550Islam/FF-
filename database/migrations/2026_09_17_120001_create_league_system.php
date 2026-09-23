<?php

use App\Models\League;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leagues')) {
            Schema::create('leagues', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique(); // Bronze, Silver, Gold, Platinum, Diamond, Titan
                $table->string('slug')->unique();
                $table->integer('level')->unique(); // 1=Bronze, 2=Silver, 3=Gold, 4=Platinum, 5=Diamond, 6=Titan
                $table->integer('min_trophies')->default(0);
                $table->integer('max_trophies')->nullable();
                $table->integer('min_level_required')->default(1); // Level 4 to reach Bronze per Gameberry
                $table->string('color')->default('#cd7f32');
                $table->string('icon_path')->nullable();
                $table->integer('promotion_top_percent')->default(20); // Top 20% promotion
                $table->integer('promotion_top_count')->default(40); // Top 40 people
                $table->integer('demotion_bottom_percent')->default(20);
                $table->json('rewards')->nullable(); // gold, gems, dice, badge
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('user_leagues')) {
            Schema::create('user_leagues', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('league_id')->constrained('leagues')->cascadeOnDelete();
                $table->integer('trophies')->default(0);
                $table->integer('rank_in_league')->nullable()->index();
                $table->integer('total_players_in_group')->default(200); // 200 per group per Gameberry
                $table->integer('wins')->default(0);
                $table->integer('losses')->default(0);
                $table->boolean('is_promoted')->default(false);
                $table->boolean('is_demoted')->default(false);
                $table->timestamp('season_start_at')->nullable();
                $table->timestamp('season_end_at')->nullable();
                $table->json('progress')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'league_id', 'season_start_at']);
                $table->index(['league_id', 'trophies']);
                $table->index(['user_id', 'league_id']);
            });
        }

        if (! Schema::hasTable('titan_badges')) {
            Schema::create('titan_badges', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('league_id')->constrained('leagues')->cascadeOnDelete();
                $table->integer('week_number')->index();
                $table->integer('year')->index();
                $table->integer('rank_at_end')->nullable();
                $table->timestamp('earned_at')->useCurrent();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'week_number', 'year']);
                $table->index(['user_id', 'league_id']);
            });
        }

        if (! Schema::hasTable('league_history')) {
            Schema::create('league_history', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('from_league_id')->nullable()->constrained('leagues')->nullOnDelete();
                $table->foreignId('to_league_id')->constrained('leagues')->cascadeOnDelete();
                $table->string('type')->index(); // promotion, demotion, season_reset
                $table->integer('trophies_at_time')->default(0);
                $table->integer('rank_at_time')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'created_at']);
            });
        }

        // Seed leagues if empty
        if (Schema::hasTable('leagues')) {
            try {
                if (League::count() === 0) {
                    $leagues = [
                        ['name' => 'Bronze', 'slug' => 'bronze', 'level' => 1, 'min_trophies' => 0, 'max_trophies' => 499, 'min_level_required' => 4, 'color' => '#cd7f32', 'promotion_top_percent' => 20, 'promotion_top_count' => 40],
                        ['name' => 'Silver', 'slug' => 'silver', 'level' => 2, 'min_trophies' => 500, 'max_trophies' => 999, 'min_level_required' => 4, 'color' => '#c0c0c0', 'promotion_top_percent' => 20, 'promotion_top_count' => 40],
                        ['name' => 'Gold', 'slug' => 'gold', 'level' => 3, 'min_trophies' => 1000, 'max_trophies' => 1999, 'min_level_required' => 6, 'color' => '#ffd700', 'promotion_top_percent' => 20, 'promotion_top_count' => 40],
                        ['name' => 'Platinum', 'slug' => 'platinum', 'level' => 4, 'min_trophies' => 2000, 'max_trophies' => 3499, 'min_level_required' => 8, 'color' => '#e5e4e2', 'promotion_top_percent' => 20, 'promotion_top_count' => 40],
                        ['name' => 'Diamond', 'slug' => 'diamond', 'level' => 5, 'min_trophies' => 3500, 'max_trophies' => 4999, 'min_level_required' => 10, 'color' => '#b9f2ff', 'promotion_top_percent' => 20, 'promotion_top_count' => 40],
                        ['name' => 'Titan', 'slug' => 'titan', 'level' => 6, 'min_trophies' => 5000, 'max_trophies' => null, 'min_level_required' => 12, 'color' => '#6c5ce7', 'promotion_top_percent' => 20, 'promotion_top_count' => 40],
                    ];
                    foreach ($leagues as $l) {
                        League::firstOrCreate(['slug' => $l['slug']], $l);
                    }
                }
            } catch (Throwable $e) {
                // Ignore seeding failure in migration
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('league_history');
        Schema::dropIfExists('titan_badges');
        Schema::dropIfExists('user_leagues');
        Schema::dropIfExists('leagues');
    }
};
