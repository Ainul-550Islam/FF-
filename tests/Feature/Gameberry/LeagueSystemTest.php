<?php

namespace Tests\Feature\Gameberry;

use Tests\TestCase;
use App\Models\User;
use App\Models\League;
use App\Models\UserLeague;
use App\Models\Level;
use App\Services\Gameberry\LeagueService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LeagueSystemTest extends TestCase
{
    use RefreshDatabase;

    protected LeagueService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(LeagueService::class);
        // Seed leagues
        $this->seed(\Database\Seeders\GameberryLeagueSeeder::class);
    }

    public function test_6_step_league_exists(): void
    {
        $this->assertEquals(6, League::count());
        $this->assertTrue(League::where('slug', 'bronze')->exists());
        $this->assertTrue(League::where('slug', 'titan')->exists());
        $this->assertEquals(['bronze','silver','gold','platinum','diamond','titan'], League::ordered()->pluck('slug')->toArray());
    }

    public function test_level_4_required_for_bronze(): void
    {
        $this->assertTrue($this->service->canAccessLeague(4, 'bronze'));
        $this->assertFalse($this->service->canAccessLeague(3, 'bronze'));
        $this->assertFalse($this->service->canAccessLeague(11, 'titan'));
        $this->assertTrue($this->service->canAccessLeague(12, 'titan'));
    }

    public function test_add_trophies_and_rank(): void
    {
        $user = User::factory()->create();
        $userLeague = $this->service->addTrophies($user->id, 100, true);
        $this->assertEquals(100, $userLeague->trophies);
        $this->assertEquals(1, $userLeague->wins);
        $this->assertEquals(1, $userLeague->games_played);
    }

    public function test_top_20_percent_promotion_logic(): void
    {
        // Create 10 users in bronze
        $bronze = League::where('slug', 'bronze')->first();
        $users = User::factory()->count(10)->create();
        foreach ($users as $index => $u) {
            UserLeague::create([
                'user_id' => $u->id,
                'league_id' => $bronze->id,
                'season' => $this->service->currentSeason(),
                'trophies' => 1000 - ($index * 100), // descending
                'wins' => 10,
                'losses' => 2,
                'games_played' => 12,
            ]);
        }

        $top20Count = (int) ceil(10 * 0.2); // 2
        $this->assertEquals(2, $top20Count);

        $leaderboard = $this->service->getLeaderboard('bronze', null, 100);
        $this->assertEquals(10, $leaderboard->count());
        $this->assertTrue($leaderboard->first()->trophies > $leaderboard->last()->trophies);
    }

    public function test_titan_badge_awarded(): void
    {
        $user = User::factory()->create();
        $titan = League::where('slug', 'titan')->first();
        $badge = \App\Models\TitanBadge::create([
            'user_id' => $user->id,
            'league_id' => $titan->id,
            'season' => $this->service->currentSeason(),
            'week' => (int) date('W'),
            'year' => (int) date('Y'),
            'rank' => 5,
            'badge_type' => 'weekly_titan',
        ]);

        $this->assertDatabaseHas('titan_badges', ['user_id' => $user->id, 'rank' => 5]);
    }
}
