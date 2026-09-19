<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FriendNotification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'friend_id',
        'type',
        'is_read',
        'payload',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'payload' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function friend()
    {
        return $this->belongsTo(User::class, 'friend_id');
    }

    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    public function markAsRead(): void
    {
        $this->is_read = true;
        $this->save();
    }

    public static function notifyFriendOnline(int $userId, int $friendId): void
    {
        $friendStatus = UserOnlineStatus::where('user_id', $friendId)->first();
        if ($friendStatus && $friendStatus->hide_online_status) {
            return; // Don't notify if friend hides status
        }

        $userStatus = UserOnlineStatus::where('user_id', $userId)->first();
        if ($userStatus && !$userStatus->notify_friends_online) {
            return; // User disabled notifications
        }

        self::create([
            'user_id' => $userId,
            'friend_id' => $friendId,
            'type' => 'friend_online',
            'payload' => ['friend_id' => $friendId, 'online_at' => now()->toIso8601String()],
        ]);
    }
}
