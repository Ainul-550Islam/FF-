<?php
namespace App\Services\Gameberry;
use App\Models\Level;
use Illuminate\Support\Facades\DB;
class LevelService
{
    const XP_PER_WIN = 100;
    const XP_PER_GAME = 20;
    const XP_PER_TROPHY = 2;
    const LEVEL_4_BRONZE = 4;
    const LEVEL_6_GOLD = 6;
    const LEVEL_8_PLATINUM = 8;
    const LEVEL_10_DIAMOND = 10;
    const LEVEL_12_TITAN = 12;
    public function getOrCreateLevel(int $userId): Level
    {
        return Level::firstOrCreate(['user_id' => $userId], ['level' => 1, 'xp' => 0, 'xp_to_next_level' => 1000, 'total_wins' => 0, 'total_losses' => 0, 'total_games' => 0, 'unlocked_features' => []]);
    }
    public function addWin(int $userId): Level
    {
        return DB::transaction(function () use ($userId) {
            $level = $this->getOrCreateLevel($userId);
            $level->total_wins += 1;
            $level->total_games += 1;
            $level->addXp(self::XP_PER_WIN + self::XP_PER_GAME);
            $level->refresh();
            return $level;
        });
    }
    public function addLoss(int $userId): Level
    {
        return DB::transaction(function () use ($userId) {
            $level = $this->getOrCreateLevel($userId);
            $level->total_losses += 1;
            $level->total_games += 1;
            $level->addXp(self::XP_PER_GAME);
            $level->save();
            return $level;
        });
    }
    public function addTrophiesXp(int $userId, int $trophies): Level
    {
        $level = $this->getOrCreateLevel($userId);
        $level->addXp($trophies * self::XP_PER_TROPHY);
        return $level;
    }
    public function canAccessBronze(int $userId): bool { return $this->getOrCreateLevel($userId)->level >= self::LEVEL_4_BRONZE; }
    public function canAccessTitan(int $userId): bool { return $this->getOrCreateLevel($userId)->level >= self::LEVEL_12_TITAN; }
    public function getLevelStats(int $userId): array
    {
        $level = $this->getOrCreateLevel($userId);
        return [
            'level' => $level->level,
            'xp' => $level->xp,
            'xp_to_next' => $level->xp_to_next_level,
            'progress_percent' => $level->xp_to_next_level > 0 ? round(($level->xp / $level->xp_to_next_level)*100,2) : 0,
            'total_wins' => $level->total_wins,
            'total_losses' => $level->total_losses,
            'total_games' => $level->total_games,
            'win_rate' => $level->winRate(),
            'can_bronze' => $level->canAccessBronzeLeague(),
            'can_titan' => $level->canAccessTitanLeague(),
            'unlocked_features' => $level->unlocked_features ?? [],
        ];
    }
}
