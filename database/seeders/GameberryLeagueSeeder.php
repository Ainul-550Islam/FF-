<?php

namespace Database\Seeders;

use App\Models\League;
use Illuminate\Database\Seeder;

class GameberryLeagueSeeder extends Seeder
{
    public function run(): void
    {
        $leagues = [
            [
                'name' => 'Bronze',
                'slug' => 'bronze',
                'level' => 1,
                'min_trophies' => 0,
                'max_trophies' => 499,
                'color_code' => '#CD7F32',
                'description' => 'Entry league - Unlocks at Level 4 - Top 20% promotion',
                'is_active' => true,
            ],
            [
                'name' => 'Silver',
                'slug' => 'silver',
                'level' => 2,
                'min_trophies' => 500,
                'max_trophies' => 999,
                'color_code' => '#C0C0C0',
                'description' => 'Silver league - Top 20% promotion, Bottom 40% demotion',
                'is_active' => true,
            ],
            [
                'name' => 'Gold',
                'slug' => 'gold',
                'level' => 3,
                'min_trophies' => 1000,
                'max_trophies' => 1999,
                'color_code' => '#FFD700',
                'description' => 'Gold league - Requires Level 6',
                'is_active' => true,
            ],
            [
                'name' => 'Platinum',
                'slug' => 'platinum',
                'level' => 4,
                'min_trophies' => 2000,
                'max_trophies' => 3499,
                'color_code' => '#E5E4E2',
                'description' => 'Platinum league - Requires Level 8',
                'is_active' => true,
            ],
            [
                'name' => 'Diamond',
                'slug' => 'diamond',
                'level' => 5,
                'min_trophies' => 3500,
                'max_trophies' => 4999,
                'color_code' => '#B9F2FF',
                'description' => 'Diamond league - Requires Level 10',
                'is_active' => true,
            ],
            [
                'name' => 'Titan',
                'slug' => 'titan',
                'level' => 6,
                'min_trophies' => 5000,
                'max_trophies' => null,
                'color_code' => '#FF4500',
                'description' => 'Titan league - Top 20% stays, Top 40 promotion rule, Titan badges weekly, Requires Level 12',
                'is_active' => true,
            ],
        ];

        foreach ($leagues as $leagueData) {
            League::firstOrCreate(
                ['slug' => $leagueData['slug']],
                $leagueData
            );
        }

        $this->command->info('Seeded 6-step league: Bronze Silver Gold Platinum Diamond Titan - Top 20% promotion Top 40 demotion Titan badges Level 4 unlock');
    }
}
