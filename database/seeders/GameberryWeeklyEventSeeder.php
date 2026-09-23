<?php

namespace Database\Seeders;

use App\Models\WeeklyEvent;
use Illuminate\Database\Seeder;

class GameberryWeeklyEventSeeder extends Seeder
{
    public function run(): void
    {
        $events = [
            ['name' => 'Weekly Gold Rush', 'slug' => 'weekly-gold-rush-2', 'description' => 'Win 10 games to get 1000 gold + 10 gems', 'type' => 'gold_rush', 'target' => 10, 'rewards' => ['gold' => 1000, 'gems' => 10]],
            ['name' => 'Dice Collector Week', 'slug' => 'dice-collector-week-2', 'description' => 'Collect 5 new dice types from 250+ collection', 'type' => 'dice_collector', 'target' => 5, 'rewards' => ['gold' => 500, 'gems' => 20]],
            ['name' => 'Titan Challenge', 'slug' => 'titan-challenge-2', 'description' => 'Reach Titan league top 20% for Titan badges', 'type' => 'titan_challenge', 'target' => 1, 'rewards' => ['gold' => 5000, 'gems' => 50]],
            ['name' => 'Team Up Tournament', 'slug' => 'team-up-tournament', 'description' => 'Win 5 Team Up matches 2v2', 'type' => 'team_up', 'target' => 5, 'rewards' => ['gold' => 2000, 'gems' => 15]],
            ['name' => 'Spin Master', 'slug' => 'spin-master', 'description' => 'Spin Spin2Win 10 times', 'type' => 'spin', 'target' => 10, 'rewards' => ['gold' => 1000, 'gems' => 5]],
        ];
        foreach ($events as $e) {
            WeeklyEvent::firstOrCreate(['slug' => $e['slug']], [
                'name' => $e['name'],
                'description' => $e['description'],
                'type' => $e['type'],
                'starts_at' => now()->startOfWeek(),
                'ends_at' => now()->endOfWeek(),
                'target_progress' => $e['target'],
                'max_participants' => 10000,
                'rewards' => $e['rewards'],
                'is_active' => true,
            ]);
        }
        $this->command->info('Seeded weekly special events: gold rush, dice collector, titan challenge, team up, spin master');
    }
}
