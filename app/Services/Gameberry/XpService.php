<?php
namespace App\Services\Gameberry;
use App\Models\Level;
class XpService
{
    const XP_WIN = 100;
    const XP_LOSS = 20;
    const XP_DRAW = 50;
    const XP_TROPHY_MULTIPLIER = 2;
    const XP_DAILY_BONUS = 50;
    const XP_WEEKLY_EVENT = 200;
    const XP_TITAN_BADGE = 500;
    public function calculateXpForGame(bool $isWin, int $trophies = 0, string $gameMode = 'classic'): int
    {
        $base = $isWin ? self::XP_WIN : self::XP_LOSS;
        $modeBonus = match($gameMode) { 'quick' => 0, 'classic' => 10, 'master' => 20, 'team_up' => 15, default => 0 };
        $trophyXp = $trophies * self::XP_TROPHY_MULTIPLIER;
        return $base + $modeBonus + $trophyXp;
    }
    public function addDailyBonusXp(int $userId): Level
    {
        $levelService = app(LevelService::class);
        $level = $levelService->getOrCreateLevel($userId);
        $level->addXp(self::XP_DAILY_BONUS);
        return $level;
    }
    public function addWeeklyEventXp(int $userId): Level
    {
        $levelService = app(LevelService::class);
        $level = $levelService->getOrCreateLevel($userId);
        $level->addXp(self::XP_WEEKLY_EVENT);
        return $level;
    }
    public function addTitanBadgeXp(int $userId): Level
    {
        $levelService = app(LevelService::class);
        $level = $levelService->getOrCreateLevel($userId);
        $level->addXp(self::XP_TITAN_BADGE);
        return $level;
    }
}
