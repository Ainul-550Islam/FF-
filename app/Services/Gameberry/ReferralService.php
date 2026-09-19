<?php

namespace App\Services\Gameberry;

use App\Models\Referral;
use App\Models\ScratchCard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReferralService
{
    const REFERRAL_BONUS_MINOR = 2500; // ₹25
    const REFERRAL_BONUS_GEMS = 10;
    const CODE_PREFIX = 'BGI'; // BGI20 style from Khiladi Adda research

    public function generateReferralCode(int $userId): string
    {
        $existing = Referral::where('referrer_id', $userId)->whereNotNull('code')->first();
        if ($existing) {
            return $existing->code;
        }

        $user = \App\Models\User::findOrFail($userId);
        $prefix = strtoupper(substr($user->name ?? 'FF', 0, 2));
        if (strlen($prefix) < 2) $prefix = self::CODE_PREFIX;

        $code = $prefix . strtoupper(Str::random(3)) . '20';

        // Ensure unique
        while (Referral::where('code', $code)->exists()) {
            $code = $prefix . strtoupper(Str::random(3)) . '20';
        }

        // Create placeholder referral entry for code ownership
        Referral::create([
            'referrer_id' => $userId,
            'code' => $code,
            'status' => 'pending',
            'bonus_minor' => self::REFERRAL_BONUS_MINOR,
        ]);

        return $code;
    }

    public function getReferralCode(int $userId): ?string
    {
        $ref = Referral::where('referrer_id', $userId)->whereNotNull('code')->latest()->first();
        return $ref?->code;
    }

    public function applyReferralCode(int $newUserId, string $code): Referral
    {
        $code = strtoupper(trim($code));

        $referrerEntry = Referral::where('code', $code)->first();
        if (!$referrerEntry) {
            throw new \Exception('Invalid referral code');
        }

        if ($referrerEntry->referrer_id === $newUserId) {
            throw new \Exception('Cannot use your own referral code');
        }

        // Check if new user already used a referral
        $existingUse = Referral::where('referred_id', $newUserId)->where('status', '!=', 'pending')->first();
        if ($existingUse) {
            throw new \Exception('Referral already used');
        }

        return DB::transaction(function () use ($newUserId, $code, $referrerEntry) {
            // Find or create referral for this new user
            $referral = Referral::where('code', $code)->where('referrer_id', $referrerEntry->referrer_id)->where('referred_id', null)->first();
            if (!$referral) {
                $referral = Referral::create([
                    'referrer_id' => $referrerEntry->referrer_id,
                    'code' => $code,
                    'referred_id' => $newUserId,
                    'status' => 'completed',
                    'bonus_minor' => self::REFERRAL_BONUS_MINOR,
                    'completed_at' => now(),
                ]);
            } else {
                $referral->referred_id = $newUserId;
                $referral->status = 'completed';
                $referral->completed_at = now();
                $referral->save();
            }

            // Reward both users
            $this->rewardReferrer($referrerEntry->referrer_id, $referral);
            $this->rewardReferred($newUserId, $referral);

            // Create scratch cards
            $this->createScratchCards($referrerEntry->referrer_id, $newUserId);

            $referral->status = 'rewarded';
            $referral->rewarded_at = now();
            $referral->save();

            return $referral;
        });
    }

    private function rewardReferrer(int $referrerId, Referral $referral): void
    {
        // Gold wallet reward - ₹25 = 2500 minor
        $goldService = app(GoldEconomyService::class);
        $wallet = $goldService->getOrCreateWallet($referrerId);
        $wallet->addGold(2500, 'referral', 'referral', (string)$referral->id, "Referral bonus for code {$referral->code}");

        // Gem reward
        $gemService = app(GemEconomyService::class);
        $gemWallet = $gemService->getOrCreateWallet($referrerId);
        $gemWallet->addGems(self::REFERRAL_BONUS_GEMS, 'referral', 'referral', (string)$referral->id, "Referral gem bonus");
    }

    private function rewardReferred(int $referredId, Referral $referral): void
    {
        $goldService = app(GoldEconomyService::class);
        $wallet = $goldService->getOrCreateWallet($referredId);
        $wallet->addGold(2500, 'referral', 'referral', (string)$referral->id, "Welcome referral bonus");

        $gemService = app(GemEconomyService::class);
        $gemWallet = $gemService->getOrCreateWallet($referredId);
        $gemWallet->addGems(self::REFERRAL_BONUS_GEMS, 'referral', 'referral', (string)$referral->id, "Welcome gem bonus");
    }

    private function createScratchCards(int $referrerId, int $referredId): void
    {
        // Khiladi Adda scratch card feature - give scratch cards to both
        ScratchCard::create([
            'user_id' => $referrerId,
            'type' => 'referral',
            'reward_minor' => 1000,
            'reward_gems' => 5,
            'status' => 'unscratched',
            'expires_at' => now()->addDays(7),
        ]);

        ScratchCard::create([
            'user_id' => $referredId,
            'type' => 'referral',
            'reward_minor' => 1000,
            'reward_gems' => 5,
            'status' => 'unscratched',
            'expires_at' => now()->addDays(7),
        ]);
    }

    public function getReferralStats(int $userId): array
    {
        $totalReferrals = Referral::where('referrer_id', $userId)->whereNotNull('referred_id')->count();
        $completed = Referral::where('referrer_id', $userId)->where('status', 'completed')->orWhere(function ($q) use ($userId) {
            $q->where('referrer_id', $userId)->where('status', 'rewarded');
        })->count();
        $pending = Referral::where('referrer_id', $userId)->where('status', 'pending')->whereNull('referred_id')->count();
        $totalEarned = Referral::where('referrer_id', $userId)->where('status', 'rewarded')->sum('bonus_minor');

        return [
            'code' => $this->getReferralCode($userId),
            'total_referrals' => $totalReferrals,
            'completed' => $completed,
            'pending' => $pending,
            'total_earned_minor' => $totalEarned,
            'total_earned_formatted' => '₹' . number_format($totalEarned / 100, 2),
        ];
    }

    public function getReferralList(int $userId): \Illuminate\Database\Eloquent\Collection
    {
        return Referral::with('referred')->where('referrer_id', $userId)->whereNotNull('referred_id')->orderByDesc('created_at')->get();
    }
}
