<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class VideoAd extends Model
{
    use HasFactory;
    protected $fillable = ['provider','placement','gold_reward','gem_reward','is_active','daily_limit','cooldown_minutes','metadata'];
    protected $casts = ['gold_reward' => 'integer', 'gem_reward' => 'integer', 'is_active' => 'boolean', 'daily_limit' => 'integer', 'cooldown_minutes' => 'integer', 'metadata' => 'array'];
    public function scopeActive($query) { return $query->where('is_active', true); }
    public static function getDefault(): ?self { return self::where('is_active', true)->first(); }
}
