<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserLeague extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'league_id',
        'season',
        'trophies',
        'rank',
        'rank_in_league',
        'total_players_in_group',
        'wins',
        'losses',
        'games_played',
        'is_promoted',
        'is_demoted',
        'is_in_top_20',
        'current_streak',
        'season_start_at',
        'season_end_at',
        'progress',
    ];

    protected $casts = [
        'season' => 'integer',
        'trophies' => 'integer',
        'rank' => 'integer',
        'rank_in_league' => 'integer',
        'total_players_in_group' => 'integer',
        'wins' => 'integer',
        'losses' => 'integer',
        'games_played' => 'integer',
        'is_promoted' => 'boolean',
        'is_demoted' => 'boolean',
        'is_in_top_20' => 'boolean',
        'current_streak' => 'integer',
        'season_start_at' => 'datetime',
        'season_end_at' => 'datetime',
        'progress' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function league()
    {
        return $this->belongsTo(League::class);
    }

    public function isInTopPercent(): bool
    {
        $rank = $this->rank ?? $this->rank_in_league;
        if (! $rank) {
            return false;
        }
        $topCount = $this->league->promotion_top_count ?? 40;

        return $rank <= $topCount;
    }

    public function isPromotedToNext(): bool
    {
        return $this->is_promoted && $this->isInTopPercent();
    }

    public function winRate(): float
    {
        $total = $this->wins + $this->losses;
        if ($total === 0) {
            return 0;
        }

        return round(($this->wins / $total) * 100, 2);
    }

    public function scopeCurrentSeason($query)
    {
        return $query->where('season_end_at', '>', now())->orWhereNull('season_end_at');
    }

    // Accessor for games_played compatibility - if not set, compute from wins+losses
    public function getGamesPlayedAttribute($value)
    {
        if ($value !== null) {
            return $value;
        }
        // Fallback to wins+losses if games_played not set
        $wins = $this->attributes['wins'] ?? 0;
        $losses = $this->attributes['losses'] ?? 0;

        return $wins + $losses;
    }

    // Accessor for rank compatibility
    public function getRankAttribute($value)
    {
        if ($value !== null) {
            return $value;
        }

        return $this->attributes['rank_in_league'] ?? null;
    }
}
