<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeagueHistory extends Model
{
    use HasFactory;

    protected $table = 'league_history';

    protected $fillable = [
        'user_id',
        'league_id',
        'season',
        'trophies',
        'rank',
        'was_promoted',
        'was_demoted',
        'promotion_league_id',
        'demotion_league_id',
        'rewards',
    ];

    protected $casts = [
        'trophies' => 'integer',
        'rank' => 'integer',
        'was_promoted' => 'boolean',
        'was_demoted' => 'boolean',
        'rewards' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function league()
    {
        return $this->belongsTo(League::class);
    }

    public function promotionLeague()
    {
        return $this->belongsTo(League::class, 'promotion_league_id');
    }

    public function demotionLeague()
    {
        return $this->belongsTo(League::class, 'demotion_league_id');
    }

    public function scopeSeason($query, int $season)
    {
        return $query->where('season', $season);
    }

    public function scopePromoted($query)
    {
        return $query->where('was_promoted', true);
    }

    public function scopeDemoted($query)
    {
        return $query->where('was_demoted', true);
    }
}
