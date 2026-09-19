<?php

namespace App\Services\Gameberry;

use App\Models\GameBuddy;
use App\Models\UserOnlineStatus;
use App\Models\FriendNotification;
use App\Models\Challenge;
use Illuminate\Support\Facades\DB;

class SocialService
{
    const MAX_BUDDIES = 25; // Gameberry max 25

    public function addBuddy(int $userId, int $buddyId): GameBuddy
    {
        if ($userId === $buddyId) {
            throw new \Exception('Cannot add yourself as buddy');
        }

        $buddyCount = GameBuddy::where('user_id', $userId)->count();
        if ($buddyCount >= self::MAX_BUDDIES) {
            throw new \Exception('Max '.self::MAX_BUDDIES.' buddies reached');
        }

        $existing = GameBuddy::where('user_id', $userId)->where('buddy_id', $buddyId)->first();
        if ($existing) {
            throw new \Exception('Already buddies');
        }

        return GameBuddy::create([
            'user_id' => $userId,
            'buddy_id' => $buddyId,
            'status' => 'pending',
        ]);
    }

    public function acceptBuddy(int $userId, int $requestId): GameBuddy
    {
        $buddy = GameBuddy::where('id', $requestId)->where('buddy_id', $userId)->where('status', 'pending')->firstOrFail();
        $buddy->status = 'accepted';
        $buddy->save();

        // Create reciprocal entry
        GameBuddy::firstOrCreate(
            ['user_id' => $userId, 'buddy_id' => $buddy->user_id],
            ['status' => 'accepted']
        );

        return $buddy;
    }

    public function removeBuddy(int $userId, int $buddyId): void
    {
        GameBuddy::where('user_id', $userId)->where('buddy_id', $buddyId)->delete();
        GameBuddy::where('user_id', $buddyId)->where('buddy_id', $userId)->delete();
    }

    public function getBuddies(int $userId): \Illuminate\Database\Eloquent\Collection
    {
        return GameBuddy::with(['buddy', 'buddy.onlineStatus'])->where('user_id', $userId)->where('status', 'accepted')->get();
    }

    public function getPendingRequests(int $userId): \Illuminate\Database\Eloquent\Collection
    {
        return GameBuddy::with('user')->where('buddy_id', $userId)->where('status', 'pending')->get();
    }

    public function getOnlineBuddies(int $userId): \Illuminate\Database\Eloquent\Collection
    {
        $buddyIds = GameBuddy::where('user_id', $userId)->where('status', 'accepted')->pluck('buddy_id');
        return UserOnlineStatus::with('user')
            ->whereIn('user_id', $buddyIds)
            ->where('is_online', true)
            ->where('hide_online_status', false)
            ->get();
    }

    public function updateOnlineStatus(int $userId, bool $isOnline, ?string $game = null, ?string $tableCode = null): UserOnlineStatus
    {
        $status = UserOnlineStatus::firstOrCreate(
            ['user_id' => $userId],
            [
                'is_online' => false,
                'hide_online_status' => false,
                'notify_friends_online' => true,
                'is_in_auto_mode' => false,
            ]
        );

        if ($isOnline) {
            $status->goOnline($game, $tableCode);

            // Notify friends if setting enabled
            if ($status->notify_friends_online && !$status->hide_online_status) {
                $this->notifyFriendsOnline($userId);
            }
        } else {
            $status->goOffline();
        }

        return $status;
    }

    private function notifyFriendsOnline(int $userId): void
    {
        $buddyIds = GameBuddy::where('user_id', $userId)->where('status', 'accepted')->pluck('buddy_id');
        foreach ($buddyIds as $buddyId) {
            FriendNotification::notifyFriendOnline($buddyId, $userId);
        }
    }

    public function setHideOnlineStatus(int $userId, bool $hide): UserOnlineStatus
    {
        $status = UserOnlineStatus::firstOrCreate(
            ['user_id' => $userId],
            ['is_online' => false, 'is_in_auto_mode' => false]
        );
        $status->hide_online_status = $hide;
        $status->save();
        return $status;
    }

    public function setNotifyFriendsOnline(int $userId, bool $notify): UserOnlineStatus
    {
        $status = UserOnlineStatus::firstOrCreate(
            ['user_id' => $userId],
            ['is_online' => false, 'is_in_auto_mode' => false]
        );
        $status->notify_friends_online = $notify;
        $status->save();
        return $status;
    }

    public function setAutoMode(int $userId, bool $autoOn, string $reason = 'disconnect'): UserOnlineStatus
    {
        $status = UserOnlineStatus::firstOrCreate(
            ['user_id' => $userId],
            ['is_online' => false]
        );
        $status->setAutoMode($autoOn, $reason);
        return $status;
    }

    public function challengeBuddy(int $challengerId, int $buddyId, int $betAmount = 100): Challenge
    {
        // Check if buddies
        $isBuddy = GameBuddy::where('user_id', $challengerId)->where('buddy_id', $buddyId)->where('status', 'accepted')->exists();
        if (!$isBuddy) {
            throw new \Exception('Not buddies - cannot challenge');
        }

        // Check buddy online status
        $buddyStatus = UserOnlineStatus::where('user_id', $buddyId)->first();
        if ($buddyStatus && $buddyStatus->hide_online_status) {
            throw new \Exception('Buddy not available');
        }

        return Challenge::create([
            'challenger_id' => $challengerId,
            'challenged_id' => $buddyId,
            'type' => 'buddy_challenge',
            'status' => 'pending',
            'bet_amount_minor' => $betAmount,
            'expires_at' => now()->addMinutes(5),
        ]);
    }

    public function getFriendNotifications(int $userId, int $limit = 20): \Illuminate\Database\Eloquent\Collection
    {
        return FriendNotification::with('friend')->where('user_id', $userId)->orderByDesc('created_at')->limit($limit)->get();
    }

    public function getSocialStats(int $userId): array
    {
        $totalBuddies = GameBuddy::where('user_id', $userId)->where('status', 'accepted')->count();
        $pendingRequests = GameBuddy::where('buddy_id', $userId)->where('status', 'pending')->count();
        $onlineBuddies = $this->getOnlineBuddies($userId)->count();
        $unreadNotifications = FriendNotification::where('user_id', $userId)->where('is_read', false)->count();

        return [
            'total_buddies' => $totalBuddies,
            'max_buddies' => self::MAX_BUDDIES,
            'can_add_more' => $totalBuddies < self::MAX_BUDDIES,
            'pending_requests' => $pendingRequests,
            'online_buddies' => $onlineBuddies,
            'unread_notifications' => $unreadNotifications,
        ];
    }
}
