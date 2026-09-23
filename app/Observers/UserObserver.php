<?php

namespace App\Observers;

use App\Models\Level;
use App\Models\User;
use App\Models\UserOnlineStatus;
use App\Services\Gameberry\GemEconomyService;
use App\Services\Gameberry\GoldEconomyService;
use Illuminate\Support\Facades\Log;

class UserObserver
{
    public function created(User $user): void
    {
        // Auto-create Gameberry economy wallets and related models - preserve existing logic, only add
        try {
            $goldService = app(GoldEconomyService::class);
            $gemService = app(GemEconomyService::class);

            $goldService->getOrCreateWallet($user->id);
            $gemService->getOrCreateWallet($user->id);

            // Create level system - Level 1 start, Level 4 Bronze unlock
            Level::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'level' => 1,
                    'xp' => 0,
                    'xp_to_next_level' => 1000,
                    'total_wins' => 0,
                    'total_losses' => 0,
                    'total_games' => 0,
                    'unlocked_features' => [],
                ]
            );

            // Create online status
            UserOnlineStatus::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'is_online' => false,
                    'hide_online_status' => false,
                    'notify_friends_online' => true,
                    'is_in_auto_mode' => false,
                ]
            );
        } catch (\Exception $e) {
            // Don't break user creation if Gameberry tables don't exist yet (migration not run)
            Log::warning('UserObserver Gameberry wallet creation failed: '.$e->getMessage());
        }
    }
}
