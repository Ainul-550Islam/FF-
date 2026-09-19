<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Services\Gameberry\GoldEconomyService;
use App\Services\Gameberry\GemEconomyService;

class GameberryEconomySeeder extends Seeder
{
    public function run(): void
    {
        $goldService = app(GoldEconomyService::class);
        $gemService = app(GemEconomyService::class);

        $users = User::take(10)->get();
        foreach ($users as $user) {
            $goldService->getOrCreateWallet($user->id);
            $gemService->getOrCreateWallet($user->id);
        }

        // Seed weekly events
        \App\Models\WeeklyEvent::firstOrCreate(
            ['slug' => 'weekly-gold-rush'],
            [
                'name' => 'Weekly Gold Rush',
                'description' => 'Win 10 games to get 1000 gold + 10 gems - Gameberry weekly special event',
                'type' => 'gold_rush',
                'starts_at' => now()->startOfWeek(),
                'ends_at' => now()->endOfWeek(),
                'target_progress' => 10,
                'max_participants' => 10000,
                'rewards' => ['gold' => 1000, 'gems' => 10],
                'is_active' => true,
            ]
        );

        \App\Models\WeeklyEvent::firstOrCreate(
            ['slug' => 'dice-collector-week'],
            [
                'name' => 'Dice Collector Week',
                'description' => 'Collect 5 new dice types - 250+ collection challenge',
                'type' => 'dice_collector',
                'starts_at' => now()->startOfWeek(),
                'ends_at' => now()->endOfWeek(),
                'target_progress' => 5,
                'max_participants' => 5000,
                'rewards' => ['gold' => 500, 'gems' => 20, 'dice' => 1],
                'is_active' => true,
            ]
        );

        \App\Models\WeeklyEvent::firstOrCreate(
            ['slug' => 'titan-challenge'],
            [
                'name' => 'Titan Challenge',
                'description' => 'Reach Titan league top 20% - Titan badges reward',
                'type' => 'titan_challenge',
                'starts_at' => now()->startOfWeek(),
                'ends_at' => now()->endOfWeek(),
                'target_progress' => 1,
                'max_participants' => 1000,
                'rewards' => ['gold' => 5000, 'gems' => 50, 'titan_badge' => 1],
                'is_active' => true,
            ]
        );

        $this->command->info('Seeded economy: gold wallets gem wallets reconciliation, weekly special events, gold at stake');
    }
}
