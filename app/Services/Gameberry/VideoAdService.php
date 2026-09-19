<?php

namespace App\Services\Gameberry;

use App\Models\VideoAdReward;
use Illuminate\Support\Facades\DB;

class VideoAdService
{
    const DAILY_LIMIT = 5;
    const GOLD_REWARD = 100;
    const GEM_REWARD = 1;
    const COOLDOWN_MINUTES = 30;

    public function canWatch(int $userId): bool
    {
        $todayCount = VideoAdReward::todayCount($userId);
        if ($todayCount >= self::DAILY_LIMIT) {
            return false;
        }

        $lastAd = VideoAdReward::where('user_id', $userId)->orderByDesc('created_at')->first();
        if ($lastAd && $lastAd->created_at->diffInMinutes(now()) < self::COOLDOWN_MINUTES) {
            return false;
        }

        return true;
    }

    public function getCooldownRemaining(int $userId): int
    {
        $lastAd = VideoAdReward::where('user_id', $userId)->orderByDesc('created_at')->first();
        if (!$lastAd) return 0;
        $elapsed = $lastAd->created_at->diffInMinutes(now());
        return max(0, self::COOLDOWN_MINUTES - $elapsed);
    }

    public function watchAd(int $userId, string $provider = 'admob'): VideoAdReward
    {
        if (!$this->canWatch($userId)) {
            $todayCount = VideoAdReward::todayCount($userId);
            if ($todayCount >= self::DAILY_LIMIT) {
                throw new \Exception('Daily video ad limit reached ('.self::DAILY_LIMIT.')');
            }
            $cooldown = $this->getCooldownRemaining($userId);
            throw new \Exception("Cooldown active, wait {$cooldown} minutes");
        }

        return DB::transaction(function () use ($userId, $provider) {
            $reward = VideoAdReward::create([
                'user_id' => $userId,
                'ad_provider' => $provider,
                'status' => 'watched',
                'gold_reward' => self::GOLD_REWARD,
                'gem_reward' => self::GEM_REWARD,
                'daily_limit' => self::DAILY_LIMIT,
                'watched_at' => now(),
            ]);

            // Give rewards
            app(GoldEconomyService::class)->getOrCreateWallet($userId)->addGold(self::GOLD_REWARD, 'video_ad', 'video_ad_reward', (string)$reward->id, 'Free gold from video ad');
            app(GemEconomyService::class)->getOrCreateWallet($userId)->addGems(self::GEM_REWARD, 'video_ad', 'video_ad_reward', (string)$reward->id, 'Free gem from video ad');

            $reward->status = 'rewarded';
            $reward->rewarded_at = now();
            $reward->save();

            return $reward;
        });
    }

    public function getTodayCount(int $userId): int
    {
        return VideoAdReward::todayCount($userId);
    }

    public function getStats(int $userId): array
    {
        $todayCount = $this->getTodayCount($userId);
        $totalWatched = VideoAdReward::where('user_id', $userId)->count();
        $totalGold = VideoAdReward::where('user_id', $userId)->sum('gold_reward');
        $totalGems = VideoAdReward::where('user_id', $userId)->sum('gem_reward');

        return [
            'today_count' => $todayCount,
            'daily_limit' => self::DAILY_LIMIT,
            'remaining_today' => max(0, self::DAILY_LIMIT - $todayCount),
            'can_watch' => $this->canWatch($userId),
            'cooldown_remaining_minutes' => $this->getCooldownRemaining($userId),
            'total_watched' => $totalWatched,
            'total_gold_earned' => $totalGold,
            'total_gems_earned' => $totalGems,
            'gold_per_ad' => self::GOLD_REWARD,
            'gem_per_ad' => self::GEM_REWARD,
        ];
    }

    public function getHistory(int $userId, int $limit = 20): \Illuminate\Database\Eloquent\Collection
    {
        return VideoAdReward::where('user_id', $userId)->orderByDesc('created_at')->limit($limit)->get();
    }
}
