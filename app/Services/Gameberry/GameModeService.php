<?php
namespace App\Services\Gameberry;
class GameModeService
{
    const MODES = [
        'classic' => ['name' => 'Classic', 'max_players' => 4, 'description' => 'Traditional Ludo 4 players', 'trophy_win' => 20, 'trophy_loss' => -10, 'xp_bonus' => 10, 'gold_multiplier' => 1],
        'master' => ['name' => 'Master', 'max_players' => 4, 'description' => 'Advanced rules 4 players', 'trophy_win' => 30, 'trophy_loss' => -15, 'xp_bonus' => 20, 'gold_multiplier' => 1.5],
        'quick' => ['name' => 'Quick', 'max_players' => 2, 'description' => 'Fast game 2 players', 'trophy_win' => 10, 'trophy_loss' => -5, 'xp_bonus' => 0, 'gold_multiplier' => 0.5],
        'team_up' => ['name' => 'Team Up', 'max_players' => 4, 'description' => '2v2 team mode', 'trophy_win' => 20, 'trophy_loss' => -10, 'xp_bonus' => 15, 'gold_multiplier' => 1],
    ];
    const VARIATIONS = ['classic', 'master', 'quick'];
    public function getModes(): array { return self::MODES; }
    public function getMode(string $mode): ?array { return self::MODES[$mode] ?? null; }
    public function getMaxPlayers(string $mode): int { return self::MODES[$mode]['max_players'] ?? 4; }
    public function isValidMode(string $mode): bool { return isset(self::MODES[$mode]); }
    public function isValidVariation(string $variation): bool { return in_array($variation, self::VARIATIONS); }
    public function getTrophies(bool $isWin, string $mode): int
    {
        $config = $this->getMode($mode);
        if (!$config) return $isWin ? 20 : -10;
        return $isWin ? $config['trophy_win'] : $config['trophy_loss'];
    }
    public function getGoldMultiplier(string $mode): float { return $this->getMode($mode)['gold_multiplier'] ?? 1; }
}
