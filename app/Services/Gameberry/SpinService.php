<?php

namespace App\Services\Gameberry;

use App\Models\SpinReward;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SpinService
{
    const SPIN_COST_GOLD = 100;

    const DAILY_FREE_SPINS = 1;

    const MAX_SPINS_PER_DAY = 10;

    public function canSpin(int $userId): bool
    {
        $todaySpins = SpinReward::where('user_id', $userId)->whereDate('created_at', today())->count();

        return $todaySpins < self::MAX_SPINS_PER_DAY;
    }

    public function getFreeSpinsRemaining(int $userId): int
    {
        $todaySpins = SpinReward::where('user_id', $userId)->whereDate('created_at', today())->count();
        $freeUsed = min($todaySpins, self::DAILY_FREE_SPINS);

        return max(0, self::DAILY_FREE_SPINS - $freeUsed);
    }

    public function spin(int $userId, bool $useFreeSpin = false): SpinReward
    {
        if (! $this->canSpin($userId)) {
            throw new \Exception('Daily spin limit reached');
        }

        $goldService = app(GoldEconomyService::class);
        $cost = self::SPIN_COST_GOLD;

        if ($useFreeSpin) {
            $freeRemaining = $this->getFreeSpinsRemaining($userId);
            if ($freeRemaining <= 0) {
                throw new \Exception('No free spins remaining');
            }
            $cost = 0;
        } else {
            if (! $goldService->canAffordBet($userId, $cost)) {
                throw new \Exception('Insufficient gold for spin');
            }
        }

        return DB::transaction(function () use ($userId, $cost) {
            if ($cost > 0) {
                $goldService = app(GoldEconomyService::class);
                $goldService->getOrCreateWallet($userId)->spendGold($cost, 'spin2win', 'spin2win_reward', null, 'Spin2Win cost');
            }

            $spin = SpinReward::spin($userId, $cost);

            // Reward the result
            if ($spin->gold_amount > 0) {
                app(GoldEconomyService::class)->getOrCreateWallet($userId)->addGold($spin->gold_amount, 'spin2win', 'spin2win_reward', (string) $spin->id, 'Spin2Win gold reward');
            }
            if ($spin->gem_amount > 0) {
                app(GemEconomyService::class)->getOrCreateWallet($userId)->addGems($spin->gem_amount, 'spin2win', 'spin2win_reward', (string) $spin->id, 'Spin2Win gem reward');
            }
            if ($spin->dice_id) {
                app(DiceCollectionService::class)->addDiceToUser($userId, $spin->dice_id, 1);
            }

            return $spin;
        });
    }

    public function getSpinHistory(int $userId, int $limit = 20): Collection
    {
        return SpinReward::where('user_id', $userId)->orderByDesc('created_at')->limit($limit)->get();
    }

    public function getSpinStats(int $userId): array
    {
        $totalSpins = SpinReward::where('user_id', $userId)->count();
        $todaySpins = SpinReward::where('user_id', $userId)->whereDate('created_at', today())->count();
        $totalGoldWon = SpinReward::where('user_id', $userId)->sum('gold_amount');
        $totalGemsWon = SpinReward::where('user_id', $userId)->sum('gem_amount');
        $jackpots = SpinReward::where('user_id', $userId)->where('result', 'jackpot')->count();

        return [
            'total_spins' => $totalSpins,
            'today_spins' => $todaySpins,
            'free_spins_remaining' => $this->getFreeSpinsRemaining($userId),
            'can_spin' => $this->canSpin($userId),
            'total_gold_won' => $totalGoldWon,
            'total_gems_won' => $totalGemsWon,
            'jackpots' => $jackpots,
            'spin_cost' => self::SPIN_COST_GOLD,
        ];
    }
}
