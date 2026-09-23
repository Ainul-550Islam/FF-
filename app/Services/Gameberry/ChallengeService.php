<?php

namespace App\Services\Gameberry;

use App\Models\Challenge;
use App\Models\GameBuddy;
use App\Models\UserOnlineStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ChallengeService
{
    const EXPIRY_MINUTES = 5;

    const MIN_BET = 100;

    const MAX_BET = 100000;

    public function canChallenge(int $challengerId, int $challengedId): bool
    {
        if ($challengerId === $challengedId) {
            return false;
        }
        $isBuddy = GameBuddy::where('user_id', $challengerId)->where('buddy_id', $challengedId)->where('status', 'accepted')->exists();
        if (! $isBuddy) {
            return false;
        }
        $status = UserOnlineStatus::where('user_id', $challengedId)->first();
        if ($status && $status->hide_online_status) {
            return false;
        }
        $pending = Challenge::where('challenger_id', $challengerId)->where('challenged_id', $challengedId)->where('status', 'pending')->where('expires_at', '>', now())->exists();
        if ($pending) {
            return false;
        }

        return true;
    }

    public function createChallenge(int $challengerId, int $challengedId, string $type = 'buddy_challenge', int $betAmount = 100): Challenge
    {
        if (! $this->canChallenge($challengerId, $challengedId)) {
            throw new \Exception('Cannot challenge - not buddies, hidden, or pending exists');
        }
        if ($betAmount < self::MIN_BET || $betAmount > self::MAX_BET) {
            throw new \Exception('Invalid bet amount');
        }
        $goldService = app(GoldEconomyService::class);
        if (! $goldService->canAffordBet($challengerId, $betAmount)) {
            throw new \Exception('Insufficient gold for challenge bet');
        }

        return DB::transaction(function () use ($challengerId, $challengedId, $type, $betAmount) {
            $challenge = Challenge::create(['challenger_id' => $challengerId, 'challenged_id' => $challengedId, 'type' => $type, 'status' => 'pending', 'bet_amount_minor' => $betAmount, 'expires_at' => now()->addMinutes(self::EXPIRY_MINUTES)]);
            app(NotificationService::class)->notifyChallenge($challengerId, $challengedId, $betAmount);

            return $challenge;
        });
    }

    public function acceptChallenge(int $challengeId, int $userId): Challenge
    {
        return DB::transaction(function () use ($challengeId, $userId) {
            $challenge = Challenge::where('id', $challengeId)->where('challenged_id', $userId)->where('status', 'pending')->firstOrFail();
            if ($challenge->isExpired()) {
                throw new \Exception('Challenge expired');
            }
            $goldService = app(GoldEconomyService::class);
            if (! $goldService->canAffordBet($userId, $challenge->bet_amount_minor)) {
                throw new \Exception('Insufficient gold to accept');
            }
            $challenge->accept();

            return $challenge;
        });
    }

    public function denyChallenge(int $challengeId, int $userId): Challenge
    {
        $challenge = Challenge::where('id', $challengeId)->where('challenged_id', $userId)->where('status', 'pending')->firstOrFail();
        $challenge->deny();

        return $challenge;
    }

    public function getPendingForUser(int $userId): Collection
    {
        return Challenge::with(['challenger', 'challenged'])->where(function ($q) use ($userId) {
            $q->where('challenger_id', $userId)->orWhere('challenged_id', $userId);
        })->where('status', 'pending')->where('expires_at', '>', now())->orderByDesc('created_at')->get();
    }
}
