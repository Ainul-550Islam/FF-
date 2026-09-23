<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VideoAdReward extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'ad_provider',
        'status',
        'gold_reward',
        'gem_reward',
        'daily_count',
        'daily_limit',
        'watched_at',
        'rewarded_at',
        'metadata',
    ];

    protected $casts = [
        'gold_reward' => 'integer',
        'gem_reward' => 'integer',
        'daily_count' => 'integer',
        'daily_limit' => 'integer',
        'watched_at' => 'datetime',
        'rewarded_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function canWatchToday(): bool
    {
        $todayCount = self::where('user_id', $this->user_id)->whereDate('created_at', today())->count();

        return $todayCount < ($this->daily_limit ?? 5);
    }

    public static function todayCount(int $userId): int
    {
        return self::where('user_id', $userId)->whereDate('created_at', today())->count();
    }

    public static function canWatch(int $userId, int $limit = 5): bool
    {
        return self::todayCount($userId) < $limit;
    }

    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }
}
