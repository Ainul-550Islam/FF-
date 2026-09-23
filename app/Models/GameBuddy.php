<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GameBuddy extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'buddy_id',
        'status',
        'is_favorite',
        'accepted_at',
        'last_played_at',
        'metadata',
    ];

    protected $casts = [
        'is_favorite' => 'boolean',
        'accepted_at' => 'datetime',
        'last_played_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function buddy()
    {
        return $this->belongsTo(User::class, 'buddy_id');
    }

    public function scopeAccepted($query)
    {
        return $query->where('status', 'accepted');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeFavorite($query)
    {
        return $query->where('is_favorite', true);
    }

    public function isAccepted(): bool
    {
        return $this->status === 'accepted';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public static function canAddMore(int $userId): bool
    {
        $count = self::where('user_id', $userId)->where('status', '!=', 'removed')->count();

        return $count < 25; // Gameberry max 25
    }

    public static function buddyCount(int $userId): int
    {
        return self::where('user_id', $userId)->where('status', 'accepted')->count();
    }
}
