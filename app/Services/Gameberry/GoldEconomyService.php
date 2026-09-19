<?php

namespace App\Services\Gameberry;

use App\Models\GoldWallet;
use App\Models\GoldTransaction;
use Illuminate\Support\Facades\DB;

class GoldEconomyService
{
    const INITIAL_GOLD = 5000;
    const MIN_BET = 100;
    const MAX_BET = 100000;
    const DAILY_BONUS = 500;
    const VIDEO_AD_REWARD = 100;

    public function getOrCreateWallet(int $userId): GoldWallet
    {
        return GoldWallet::firstOrCreate(
            ['user_id' => $userId],
            [
                'gold_balance' => self::INITIAL_GOLD,
                'total_earned' => self::INITIAL_GOLD,
                'total_spent' => 0,
                'total_won' => 0,
                'total_lost' => 0,
            ]
        );
    }

    public function getBalance(int $userId): int
    {
        return $this->getOrCreateWallet($userId)->gold_balance;
    }

    public function canAffordBet(int $userId, int $betAmount): bool
    {
        if ($betAmount < self::MIN_BET || $betAmount > self::MAX_BET) {
            return false;
        }
        return $this->getBalance($userId) >= $betAmount;
    }

    public function placeBet(int $userId, int $amount, string $tableCode): GoldTransaction
    {
        if (!$this->canAffordBet($userId, $amount)) {
            throw new \Exception('Insufficient gold or invalid bet amount');
        }

        $wallet = $this->getOrCreateWallet($userId);
        return $wallet->spendGold($amount, 'bet', 'private_table', $tableCode, "Gold at stake for table {$tableCode}");
    }

    public function winGold(int $userId, int $amount, string $tableCode): GoldTransaction
    {
        $wallet = $this->getOrCreateWallet($userId);
        return $wallet->addGold($amount, 'win', 'private_table', $tableCode, "Won gold from table {$tableCode}");
    }

    public function refundBet(int $userId, int $amount, string $tableCode, string $reason = 'refund'): GoldTransaction
    {
        $wallet = $this->getOrCreateWallet($userId);
        return $wallet->addGold($amount, 'refund', 'private_table', $tableCode, "Refund: {$reason}");
    }

    public function rewardVideoAd(int $userId, int $reward = null): GoldTransaction
    {
        $reward = $reward ?? self::VIDEO_AD_REWARD;
        $wallet = $this->getOrCreateWallet($userId);
        return $wallet->addGold($reward, 'video_ad', 'video_ad_reward', null, 'Free gold from video ad');
    }

    public function rewardDailyBonus(int $userId): GoldTransaction
    {
        $wallet = $this->getOrCreateWallet($userId);
        return $wallet->addGold(self::DAILY_BONUS, 'daily_bonus', null, null, 'Daily login bonus');
    }

    public function rewardMagicChest(int $userId, int $goldAmount, string $chestId): GoldTransaction
    {
        $wallet = $this->getOrCreateWallet($userId);
        return $wallet->addGold($goldAmount, 'magic_chest', 'magic_chest', $chestId, 'Magic chest reward');
    }

    public function purchaseWithGold(int $userId, int $amount, string $itemType, string $itemId): GoldTransaction
    {
        $wallet = $this->getOrCreateWallet($userId);
        return $wallet->spendGold($amount, 'purchase', $itemType, $itemId, "Purchase {$itemType}");
    }

    public function getTransactionHistory(int $userId, int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return GoldTransaction::where('user_id', $userId)->orderByDesc('created_at')->limit($limit)->get();
    }

    public function getStats(int $userId): array
    {
        $wallet = $this->getOrCreateWallet($userId);
        return [
            'balance' => $wallet->gold_balance,
            'total_earned' => $wallet->total_earned,
            'total_spent' => $wallet->total_spent,
            'total_won' => $wallet->total_won,
            'total_lost' => $wallet->total_lost,
            'win_rate' => $wallet->total_won > 0 ? round(($wallet->total_won / max(1, $wallet->total_won + $wallet->total_lost)) * 100, 2) : 0,
        ];
    }

    public function reconcile(int $userId): array
    {
        $wallet = $this->getOrCreateWallet($userId);
        $transactions = GoldTransaction::where('user_id', $userId)->orderBy('created_at')->get();

        $calculatedBalance = self::INITIAL_GOLD;
        // Actually initial balance already counted, so we recalc from zero plus initial
        // For reconciliation, we sum all transactions starting from initial
        $sum = $transactions->sum('amount');
        $expected = self::INITIAL_GOLD + $sum - $transactions->where('type', 'initial')->sum('amount'); // careful double count
        // Simpler: recompute from transactions only, initial wallet already has INITIAL_GOLD
        $totalCredits = $transactions->where('amount', '>', 0)->sum('amount');
        $totalDebits = abs($transactions->where('amount', '<', 0)->sum('amount'));
        $computed = self::INITIAL_GOLD + $totalCredits - $totalDebits;

        // If wallet was created with initial gold but no transaction, adjust
        $hasInitialTx = $transactions->where('type', 'initial')->count() > 0;
        if (!$hasInitialTx) {
            $computed = $computed; // already includes initial
        }

        $isBalanced = $wallet->gold_balance === $computed;

        return [
            'wallet_balance' => $wallet->gold_balance,
            'computed_balance' => $computed,
            'is_balanced' => $isBalanced,
            'difference' => $wallet->gold_balance - $computed,
            'total_transactions' => $transactions->count(),
        ];
    }
}
