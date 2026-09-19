<?php
namespace App\Services\Gameberry;
use App\Models\TitanBadge;
use App\Models\League;
class BadgeService
{
    public function getUserBadges(int $userId): \Illuminate\Database\Eloquent\Collection
    {
        return TitanBadge::with('league')->where('user_id', $userId)->orderByDesc('year')->orderByDesc('week')->get();
    }
    public function getBadgeCount(int $userId): int
    {
        return TitanBadge::where('user_id', $userId)->count();
    }
    public function getLatestBadge(int $userId): ?TitanBadge
    {
        return TitanBadge::where('user_id', $userId)->orderByDesc('created_at')->first();
    }
    public function awardBadge(int $userId, int $season, int $rank, string $type = 'weekly_titan'): TitanBadge
    {
        $week = (int) date('W');
        $year = (int) date('Y');
        $titanLeague = League::where('slug', 'titan')->first();
        return TitanBadge::create([
            'user_id' => $userId,
            'league_id' => $titanLeague->id ?? 6,
            'season' => $season,
            'week' => $week,
            'year' => $year,
            'rank' => $rank,
            'badge_type' => $type,
        ]);
    }
    public function getBadgeStats(int $userId): array
    {
        $badges = $this->getUserBadges($userId);
        return [
            'total' => $badges->count(),
            'latest' => $badges->first(),
            'by_year' => $badges->groupBy('year')->map->count(),
            'best_rank' => $badges->min('rank'),
            'worst_rank' => $badges->max('rank'),
        ];
    }
}
