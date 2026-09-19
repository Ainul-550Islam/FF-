<?php
namespace App\Services\Gameberry;
use App\Models\UserLeague;
use App\Services\Gameberry\LeagueService;
use Illuminate\Support\Facades\DB;
class TrophyService
{
    const TROPHY_WIN_QUICK = 10;
    const TROPHY_WIN_CLASSIC = 20;
    const TROPHY_WIN_MASTER = 30;
    const TROPHY_LOSS_QUICK = -5;
    const TROPHY_LOSS_CLASSIC = -10;
    const TROPHY_LOSS_MASTER = -15;
    public function getTrophiesForResult(bool $isWin, string $gameMode = 'classic'): int
    {
        if ($isWin) {
            return match($gameMode) { 'quick' => self::TROPHY_WIN_QUICK, 'classic' => self::TROPHY_WIN_CLASSIC, 'master' => self::TROPHY_WIN_MASTER, 'team_up' => self::TROPHY_WIN_CLASSIC, default => self::TROPHY_WIN_CLASSIC };
        } else {
            return match($gameMode) { 'quick' => self::TROPHY_LOSS_QUICK, 'classic' => self::TROPHY_LOSS_CLASSIC, 'master' => self::TROPHY_LOSS_MASTER, 'team_up' => self::TROPHY_LOSS_CLASSIC, default => self::TROPHY_LOSS_CLASSIC };
        }
    }
    public function addTrophies(int $userId, bool $isWin, string $gameMode = 'classic'): UserLeague
    {
        $trophies = $this->getTrophiesForResult($isWin, $gameMode);
        $leagueService = app(LeagueService::class);
        return $leagueService->addTrophies($userId, $trophies, $isWin);
    }
    public function processWin(int $userId, string $gameMode = 'classic'): array
    {
        return DB::transaction(function () use ($userId, $gameMode) {
            $trophyService = $this;
            $xpService = app(XpService::class);
            $levelService = app(LevelService::class);
            $goldService = app(GoldEconomyService::class);
            $chestService = app(MagicChestService::class);
            $userLeague = $trophyService->addTrophies($userId, true, $gameMode);
            $level = $levelService->addWin($userId);
            $xp = $xpService->calculateXpForGame(true, $userLeague->trophies, $gameMode);
            $levelService->addTrophiesXp($userId, $userLeague->trophies);
            $chest = $chestService->rewardForWin($userId, $gameMode);
            return ['user_league' => $userLeague, 'level' => $level, 'xp_gained' => $xp, 'chest' => $chest];
        });
    }
    public function processLoss(int $userId, string $gameMode = 'classic'): array
    {
        return DB::transaction(function () use ($userId, $gameMode) {
            $userLeague = $this->addTrophies($userId, false, $gameMode);
            $levelService = app(LevelService::class);
            $level = $levelService->addLoss($userId);
            return ['user_league' => $userLeague, 'level' => $level];
        });
    }
}
