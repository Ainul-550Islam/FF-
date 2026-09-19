<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class League extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'level',
        'min_trophies',
        'max_trophies',
        'min_level_required',
        'color',
        'icon_path',
        'promotion_top_percent',
        'promotion_top_count',
        'demotion_bottom_percent',
        'rewards',
        'metadata',
    ];

    protected $casts = [
        'level' => 'integer',
        'min_trophies' => 'integer',
        'max_trophies' => 'integer',
        'min_level_required' => 'integer',
        'promotion_top_percent' => 'integer',
        'promotion_top_count' => 'integer',
        'demotion_bottom_percent' => 'integer',
        'rewards' => 'array',
        'metadata' => 'array',
    ];

    public function userLeagues()
    {
        return $this->hasMany(UserLeague::class);
    }

    public function titanBadges()
    {
        return $this->hasMany(TitanBadge::class);
    }

    public function isTitan(): bool
    {
        return $this->slug === 'titan' || $this->level === 6;
    }

    public function isBronze(): bool
    {
        return $this->slug === 'bronze' || $this->level === 1;
    }

    public function nextLeague(): ?League
    {
        return League::where('level', $this->level + 1)->first();
    }

    public function previousLeague(): ?League
    {
        return League::where('level', $this->level - 1)->first();
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('level', 'asc');
    }
}
