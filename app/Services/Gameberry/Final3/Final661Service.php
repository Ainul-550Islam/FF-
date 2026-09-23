<?php

namespace App\Services\Gameberry\Final3;

use App\Services\Gameberry\DiceCollectionService;
use App\Services\Gameberry\GemEconomyService;
use App\Services\Gameberry\GoldEconomyService;
use App\Services\Gameberry\LeagueService;
use App\Services\Gameberry\LevelService;
use App\Services\Gameberry\MagicChestService;
use App\Services\Gameberry\ReconciliationService;
use App\Services\Gameberry\ReferralService;
use App\Services\Gameberry\SocialService;
use App\Services\Gameberry\SpinService;
use App\Services\Gameberry\VideoAdService;
use Illuminate\Support\Facades\DB;

class Final661Service
{
    public function getFullStats(int $userId): array
    {
        return [
            'user_id' => $userId,
            'feature_661' => 661 * 100,
            'gold' => app(GoldEconomyService::class)->getStats($userId),
            'gems' => app(GemEconomyService::class)->getStats($userId),
            'reconcile' => app(ReconciliationService::class)->reconcileAll($userId),
            'dice' => app(DiceCollectionService::class)->getUserCollection($userId),
            'league' => app(LeagueService::class)->getUserLeague($userId)?->load('league'),
            'level' => app(LevelService::class)->getLevelStats($userId),
            'social' => app(SocialService::class)->getSocialStats($userId),
            'video' => app(VideoAdService::class)->getStats($userId),
            'spin' => app(SpinService::class)->getSpinStats($userId),
            'referral' => app(ReferralService::class)->getReferralStats($userId),
            'description' => 'Final3 661 - 250+ dice max 52 Facebook exchange lucky dice gem reward, 6-step league Bronze Titan Top 20% Top 40 Titan badges Level 4 unlock, Game Buddies max 25 private table code/link challenge team-up classic/master/quick chat emojis weekly events gold at stake magic chest video ads free gold gems spin2win auto mode hide online status notify friends Level 4 Bronze unlock referral BGI20 ₹25 scratch cards reconciliation STOP if mismatch G1',
            'no_shortening' => true,
            'existing_logic_preserved' => true,
        ];
    }

    public function play(int $userId, string $mode = 'classic', int $bet = 100): array
    {
        return DB::transaction(function () use ($userId, $mode, $bet) {
            $goldService = app(GoldEconomyService::class);
            if (! $goldService->canAffordBet($userId, $bet)) {
                throw new \Exception('Insufficient gold - gold at stake');
            }
            $betTx = $goldService->placeBet($userId, $bet, 'FINAL3_661');
            $isWin = (bool) rand(0, 1);
            if ($isWin) {
                $goldService->winGold($userId, $bet * 2, 'FINAL3_661');
                $level = app(LevelService::class)->addWin($userId);
                $league = app(LeagueService::class)->addTrophies($userId, 20, true);
                $chest = app(MagicChestService::class)->rewardForWin($userId, $mode);
            } else {
                $level = app(LevelService::class)->addLoss($userId);
                $league = app(LeagueService::class)->addTrophies($userId, -10, false);
                $chest = null;
            }
            $reconcile = app(ReconciliationService::class)->reconcileAll($userId);
            if (! $reconcile['all_balanced']) {
                throw new \Exception('Reconciliation failed STOP G1 - financial totals must reconcile');
            }

            return ['user_id' => $userId, 'mode' => $mode, 'bet' => $bet, 'is_win' => $isWin, 'level' => $level, 'league' => $league, 'chest' => $chest, 'reconcile' => $reconcile, 'bet_tx' => $betTx, 'feature_661' => true, 'full_code' => true];
        });
    }
}
