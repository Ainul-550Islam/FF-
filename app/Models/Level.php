<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Level extends Model
{
    use HasFactory;

    protected $table = 'user_levels';

    protected $fillable = [
        'user_id',
        'level',
        'xp',
        'xp_to_next_level',
        'total_wins',
        'total_losses',
        'total_games',
        'unlocked_features',
    ];

    protected $casts = [
        'level' => 'integer',
        'xp' => 'integer',
        'xp_to_next_level' => 'integer',
        'total_wins' => 'integer',
        'total_losses' => 'integer',
        'total_games' => 'integer',
        'unlocked_features' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function canAccessBronzeLeague(): bool
    {
        return $this->level >= 4; // Gameberry Level 4 to reach Bronze
    }

    public function canAccessSilverLeague(): bool
    {
        return $this->level >= 4;
    }

    public function canAccessGoldLeague(): bool
    {
        return $this->level >= 6;
    }

    public function canAccessPlatinumLeague(): bool
    {
        return $this->level >= 8;
    }

    public function canAccessDiamondLeague(): bool
    {
        return $this->level >= 10;
    }

    public function canAccessTitanLeague(): bool
    {
        return $this->level >= 12;
    }

    public function winRate(): float
    {
        if ($this->total_games === 0) return 0;
        return round(($this->total_wins / $this->total_games) * 100, 2);
    }

    public function addXp(int $xp): void
    {
        $this->xp += $xp;
        while ($this->xp >= $this->xp_to_next_level) {
            $this->xp -= $this->xp_to_next_level;
            $this->level++;
            $this->xp_to_next_level = $this->calculateXpToNextLevel();
            $this->unlockFeaturesForLevel();
        }
        $this->save();
    }

    private function calculateXpToNextLevel(): int
    {
        return 1000 + ($this->level * 200); // Progressive XP
    }

    private function unlockFeaturesForLevel(): void
    {
        $features = $this->unlocked_features ?? [];
        if ($this->level === 4 && !in_array('bronze_league', $features)) {
            $features[] = 'bronze_league';
        }
        if ($this->level === 6 && !in_array('gold_league', $features)) {
            $features[] = 'gold_league';
        }
        if ($this->level === 12 && !in_array('titan_league', $features)) {
            $features[] = 'titan_league';
        }
        $this->unlocked_features = $features;
    }
}
