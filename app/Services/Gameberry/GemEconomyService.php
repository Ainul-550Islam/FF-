<?php

namespace App\Services\Gameberry;

use App\Models\GemTransaction;
use App\Models\GemWallet;
use Illuminate\Database\Eloquent\Collection;

class GemEconomyService
{
    const INITIAL_GEMS = 10;

    const LUCKY_DICE_GEM_REWARD = 5;

    const VIDEO_AD_GEM_REWARD = 1;

    public function getOrCreateWallet(int $userId): GemWallet
    {
        return GemWallet::firstOrCreate(
            ['user_id' => $userId],
            [
                'gem_balance' => self::INITIAL_GEMS,
                'total_earned' => self::INITIAL_GEMS,
                'total_spent' => 0,
                'total_purchased' => 0,
            ]
        );
    }

    public function getBalance(int $userId): int
    {
        return $this->getOrCreateWallet($userId)->gem_balance;
    }

    public function rewardLuckyDice(int $userId, int $gems, string $pattern): GemTransaction
    {
        $wallet = $this->getOrCreateWallet($userId);

        return $wallet->addGems($gems, 'lucky_dice', 'lucky_dice', $pattern, "Lucky dice reward pattern {$pattern}");
    }

    public function rewardVideoAd(int $userId): GemTransaction
    {
        $wallet = $this->getOrCreateWallet($userId);

        return $wallet->addGems(self::VIDEO_AD_GEM_REWARD, 'video_ad', 'video_ad_reward', null, 'Free gem from video ad');
    }

    public function rewardSpin(int $userId, int $gems, string $spinId): GemTransaction
    {
        $wallet = $this->getOrCreateWallet($userId);

        return $wallet->addGems($gems, 'spin2win', 'spin2win_reward', $spinId, 'Spin2Win gem reward');
    }

    public function rewardMagicChest(int $userId, int $gems, string $chestId): GemTransaction
    {
        $wallet = $this->getOrCreateWallet($userId);

        return $wallet->addGems($gems, 'magic_chest', 'magic_chest', $chestId, 'Magic chest gem reward');
    }

    public function rewardWeeklyEvent(int $userId, int $gems, string $eventId): GemTransaction
    {
        $wallet = $this->getOrCreateWallet($userId);

        return $wallet->addGems($gems, 'weekly_event', 'weekly_event', $eventId, 'Weekly event reward');
    }

    public function rewardReferral(int $userId, int $gems, string $referralCode): GemTransaction
    {
        $wallet = $this->getOrCreateWallet($userId);

        return $wallet->addGems($gems, 'referral', 'referral', $referralCode, 'Referral bonus BGI20 style');
    }

    public function purchaseGems(int $userId, int $gems, int $priceMinor, string $purchaseId): GemTransaction
    {
        $wallet = $this->getOrCreateWallet($userId);

        return $wallet->addGems($gems, 'purchase', 'gem_purchase', $purchaseId, "Purchased {$gems} gems for {$priceMinor} minor");
    }

    public function spendGems(int $userId, int $amount, string $type, string $referenceType, string $referenceId): GemTransaction
    {
        $wallet = $this->getOrCreateWallet($userId);
        if (! $wallet->hasEnoughGems($amount)) {
            throw new \Exception('Insufficient gems');
        }

        return $wallet->spendGems($amount, $type, $referenceType, $referenceId, "Spent {$amount} gems on {$type}");
    }

    public function getTransactionHistory(int $userId, int $limit = 50): Collection
    {
        return GemTransaction::where('user_id', $userId)->orderByDesc('created_at')->limit($limit)->get();
    }

    public function getStats(int $userId): array
    {
        $wallet = $this->getOrCreateWallet($userId);

        return [
            'balance' => $wallet->gem_balance,
            'total_earned' => $wallet->total_earned,
            'total_spent' => $wallet->total_spent,
            'total_purchased' => $wallet->total_purchased,
        ];
    }

    public function reconcile(int $userId): array
    {
        $wallet = $this->getOrCreateWallet($userId);
        $transactions = GemTransaction::where('user_id', $userId)->get();
        $totalCredits = $transactions->where('amount', '>', 0)->sum('amount');
        $totalDebits = abs($transactions->where('amount', '<', 0)->sum('amount'));
        $computed = self::INITIAL_GEMS + $totalCredits - $totalDebits;
        $hasInitial = $transactions->where('type', 'initial')->count() > 0;

        // If wallet created with initial gems, computed already includes it as base, transactions sum is extra
        return [
            'wallet_balance' => $wallet->gem_balance,
            'computed_balance' => $computed,
            'is_balanced' => $wallet->gem_balance === $computed,
            'difference' => $wallet->gem_balance - $computed,
        ];
    }
}
