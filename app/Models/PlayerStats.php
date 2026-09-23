<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlayerStats extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'total_games', 'wins', 'losses', 'draws', 'total_trophies', 'highest_trophies', 'win_streak', 'best_win_streak', 'total_gold_won', 'total_gold_lost', 'total_gems_earned', 'favorite_game_mode', 'total_play_time_minutes'];

    protected $casts = ['total_games' => 'integer', 'wins' => 'integer', 'losses' => 'integer', 'draws' => 'integer', 'total_trophies' => 'integer', 'highest_trophies' => 'integer', 'win_streak' => 'integer', 'best_win_streak' => 'integer', 'total_gold_won' => 'integer', 'total_gold_lost' => 'integer', 'total_gems_earned' => 'integer', 'total_play_time_minutes' => 'integer'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function winRate(): float
    {
        if ($this->total_games === 0) {
            return 0;
        }

        return round(($this->wins / $this->total_games) * 100, 2);
    }

    public function addWin(int $goldWon = 0, int $gemsEarned = 0): void
    {
        $this->total_games += 1;
        $this->wins += 1;
        $this->win_streak += 1;
        $this->best_win_streak = max($this->best_win_streak, $this->win_streak);
        $this->total_gold_won += $goldWon;
        $this->total_gems_earned += $gemsEarned;
        $this->save();
    }

    public function addLoss(int $goldLost = 0): void
    {
        $this->total_games += 1;
        $this->losses += 1;
        $this->win_streak = 0;
        $this->total_gold_lost += $goldLost;
        $this->save();
    }
}
