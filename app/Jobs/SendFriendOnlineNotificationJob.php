<?php

namespace App\Jobs;

use App\Models\FriendNotification;
use App\Models\UserOnlineStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendFriendOnlineNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $userId;

    public int $friendId;

    public function __construct(int $userId, int $friendId)
    {
        $this->userId = $userId;
        $this->friendId = $friendId;
    }

    public function handle(): void
    {
        try {
            // Check if friend hides online status
            $friendStatus = UserOnlineStatus::where('user_id', $this->friendId)->first();
            if ($friendStatus && $friendStatus->hide_online_status) {
                Log::info("Friend {$this->friendId} hides online status, skipping notification to {$this->userId}");

                return;
            }

            // Check if user wants to notify friends
            $userStatus = UserOnlineStatus::where('user_id', $this->userId)->first();
            if ($userStatus && ! $userStatus->notify_friends_online) {
                Log::info("User {$this->userId} disabled notify friends online");

                return;
            }

            FriendNotification::create([
                'user_id' => $this->userId,
                'friend_id' => $this->friendId,
                'type' => 'friend_online',
                'payload' => ['friend_id' => $this->friendId, 'online_at' => now()->toIso8601String()],
            ]);

            Log::info("Notified user {$this->userId} that friend {$this->friendId} is online");
        } catch (\Exception $e) {
            Log::error('Failed to send friend online notification: '.$e->getMessage());
        }
    }
}
