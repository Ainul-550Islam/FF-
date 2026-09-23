<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Gameberry\MagicChestService;
use Illuminate\Database\Seeder;

class GameberryChestSeeder extends Seeder
{
    public function run(): void
    {
        $chestService = app(MagicChestService::class);
        $users = User::take(5)->get();
        foreach ($users as $user) {
            try {
                $chestService->createChest($user->id, 'bronze');
                $chestService->createChest($user->id, 'silver');
            } catch (\Exception $e) {
                // cooldown may block, ignore
            }
        }
        $this->command->info('Seeded magic chests bronze silver for users - gold gems dice rewards');
    }
}
