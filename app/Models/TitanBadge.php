<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TitanBadge extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'league_id',
        'season',
        'week',
        'week_number',
        'year',
        'rank',
        'rank_at_end',
        'badge_type',
        'earned_at',
        'metadata',
    ];

    protected $casts = [
        'season' => 'integer',
        'week' => 'integer',
        'week_number' => 'integer',
        'year' => 'integer',
        'rank' => 'integer',
        'rank_at_end' => 'integer',
        'earned_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected static function booted()
    {
        static::creating(function ($badge) {
            // Sync week and week_number for compatibility
            if (! empty($badge->week) && empty($badge->week_number)) {
                $badge->week_number = $badge->week;
            }
            if (! empty($badge->week_number) && empty($badge->week)) {
                $badge->week = $badge->week_number;
            }
            if (! empty($badge->rank) && empty($badge->rank_at_end)) {
                $badge->rank_at_end = $badge->rank;
            }
            if (! empty($badge->rank_at_end) && empty($badge->rank)) {
                $badge->rank = $badge->rank_at_end;
            }
            if (empty($badge->week_number)) {
                $badge->week_number = (int) date('W');
            }
            if (empty($badge->week)) {
                $badge->week = $badge->week_number;
            }
            if (empty($badge->year)) {
                $badge->year = (int) date('Y');
            }
            if (empty($badge->earned_at)) {
                $badge->earned_at = now();
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function league()
    {
        return $this->belongsTo(League::class);
    }

    public function scopeForYear($query, int $year)
    {
        return $query->where('year', $year);
    }

    public function scopeForWeek($query, int $week, int $year)
    {
        return $query->where(function ($q) use ($week) {
            $q->where('week_number', $week)->orWhere('week', $week);
        })->where('year', $year);
    }

    // Accessors for compatibility
    public function getWeekAttribute($value)
    {
        if ($value !== null) {
            return $value;
        }

        return $this->attributes['week_number'] ?? null;
    }

    public function getRankAttribute($value)
    {
        if ($value !== null) {
            return $value;
        }

        return $this->attributes['rank_at_end'] ?? null;
    }
}
