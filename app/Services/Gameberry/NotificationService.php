<?php
namespace App\Services\Gameberry;
use App\Models\FriendNotification;
use App\Models\UserOnlineStatus;
class NotificationService
{
    public function notifyFriendOnline(int $userId, int $friendId): void
    {
        $friendStatus = UserOnlineStatus::where('user_id', $friendId)->first();
        if ($friendStatus && $friendStatus->hide_online_status) return;
        $userStatus = UserOnlineStatus::where('user_id', $userId)->first();
        if ($userStatus && !$userStatus->notify_friends_online) return;
        FriendNotification::create(['user_id' => $userId, 'friend_id' => $friendId, 'type' => 'friend_online', 'payload' => ['friend_id' => $friendId, 'online_at' => now()->toIso8601String()]]);
    }
    public function notifyChallenge(int $challengerId, int $challengedId, int $betAmount): void
    {
        FriendNotification::create(['user_id' => $challengedId, 'friend_id' => $challengerId, 'type' => 'challenge_received', 'payload' => ['challenger_id' => $challengerId, 'bet_amount' => $betAmount, 'received_at' => now()->toIso8601String()]]);
    }
    public function notifyDiceExchange(int $senderId, int $receiverId, int $diceId): void
    {
        FriendNotification::create(['user_id' => $receiverId, 'friend_id' => $senderId, 'type' => 'dice_exchange', 'payload' => ['sender_id' => $senderId, 'dice_id' => $diceId, 'facebook_only' => true]]);
    }
    public function notifyLeaguePromotion(int $userId, string $fromLeague, string $toLeague): void
    {
        FriendNotification::create(['user_id' => $userId, 'friend_id' => $userId, 'type' => 'league_promotion', 'payload' => ['from' => $fromLeague, 'to' => $toLeague, 'top_20_percent' => true]]);
    }
    public function notifyTitanBadge(int $userId, int $week, int $year, int $rank): void
    {
        FriendNotification::create(['user_id' => $userId, 'friend_id' => $userId, 'type' => 'titan_badge', 'payload' => ['week' => $week, 'year' => $year, 'rank' => $rank]]);
    }
    public function getUnreadCount(int $userId): int
    {
        return FriendNotification::where('user_id', $userId)->where('is_read', false)->count();
    }
    public function markAllRead(int $userId): int
    {
        return FriendNotification::where('user_id', $userId)->where('is_read', false)->update(['is_read' => true]);
    }
}
