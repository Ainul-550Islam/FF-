<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GameMode extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'max_players', 'description', 'is_active', 'config'];

    protected $casts = ['max_players' => 'integer', 'is_active' => 'boolean', 'config' => 'array'];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public static function getClassic(): ?self
    {
        return self::where('slug', 'classic')->first();
    }

    public static function getMaster(): ?self
    {
        return self::where('slug', 'master')->first();
    }

    public static function getQuick(): ?self
    {
        return self::where('slug', 'quick')->first();
    }

    public static function getTeamUp(): ?self
    {
        return self::where('slug', 'team_up')->first();
    }
}
