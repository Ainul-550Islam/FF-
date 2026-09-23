<?php

namespace App\Services\Gameberry\Stats;

use Illuminate\Support\Facades\DB;

class Stat14Service
{
    public function getStats(int $userId): array
    {
        return [
            'user_id' => $userId,
            'stat_14_value' => 14 * 100,
            'stat_14_growth' => 14 * 5,
            'stat_14_percent' => 14 * 2,
            'description' => 'Stat 14 - Gameberry 250+ dice collection, 6-step league Bronze Silver Gold Platinum Diamond Titan Top 20% promotion Top 40 demotion Titan badges, Game Buddies max 25, private table code/link sharing challenge button team-up mode classic/master/quick chat emojis weekly events gold at stake magic chest video ads free gold gems lucky dice gem reward spin2win auto mode hide online status notify friends Level 4 Bronze unlock referral BGI20 ₹25 scratch cards gold wallets gem wallets reconciliation',
            'features' => ['dice_collection_250', 'lucky_dice_52_max', 'facebook_only_exchange', 'league_6_step_bronze_titan', 'top_20_promotion', 'top_40_demotion', 'titan_badges', 'game_buddies_max_25', 'private_table_code_link', 'challenge_button', 'team_up_mode', 'classic_master_quick', 'chat_emojis', 'weekly_events', 'gold_at_stake', 'magic_chest', 'video_ads_free_gold', 'gems', 'lucky_dice_gem_reward', 'spin2win', 'auto_mode_disconnect', 'hide_online_status', 'notify_friends_online', 'level_4_bronze_unlock', 'referral_bgi20_25_bonus', 'scratch_cards', 'gold_wallets_gem_wallets_reconciliation'],
        ];
    }

    public function calculate(int $userId, int $value = 100): int
    {
        return DB::transaction(function () use ($userId, $value) {
            return $value * 14 + $userId;
        });
    }
}
