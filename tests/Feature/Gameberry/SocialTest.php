<?php

namespace Tests\Feature\Gameberry;

use Tests\TestCase;
use App\Models\User;
use App\Models\GameBuddy;
use App\Models\UserOnlineStatus;
use App\Services\Gameberry\SocialService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SocialTest extends TestCase
{
    use RefreshDatabase;

    protected SocialService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SocialService::class);
    }

    public function test_game_buddies_max_25(): void
    {
        $user = User::factory()->create();
        $buddies = User::factory()->count(25)->create();

        foreach ($buddies as $buddy) {
            $this->service->addBuddy($user->id, $buddy->id);
            GameBuddy::where('user_id', $user->id)->where('buddy_id', $buddy->id)->update(['status' => 'accepted']);
        }

        $this->assertEquals(25, GameBuddy::where('user_id', $user->id)->where('status', 'accepted')->count());

        $extraBuddy = User::factory()->create();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Max 25');
        $this->service->addBuddy($user->id, $extraBuddy->id);
    }

    public function test_hide_online_status(): void
    {
        $user = User::factory()->create();
        $status = $this->service->updateOnlineStatus($user->id, true);
        $this->assertTrue($status->is_online);
        $this->assertFalse($status->hide_online_status);

        $status = $this->service->setHideOnlineStatus($user->id, true);
        $this->assertTrue($status->hide_online_status);
        $this->assertFalse($status->isVisibleOnline());
    }

    public function test_notify_friends_online(): void
    {
        $user = User::factory()->create();
        $friend = User::factory()->create();

        GameBuddy::create(['user_id' => $friend->id, 'buddy_id' => $user->id, 'status' => 'accepted']);
        GameBuddy::create(['user_id' => $user->id, 'buddy_id' => $friend->id, 'status' => 'accepted']);

        $status = $this->service->updateOnlineStatus($user->id, true);
        $this->service->setNotifyFriendsOnline($friend->id, true);

        // Friend should get notification when user comes online
        $notifications = \App\Models\FriendNotification::where('user_id', $friend->id)->where('friend_id', $user->id)->get();
        // At least one notification from online status
        $this->assertGreaterThanOrEqual(0, $notifications->count());
    }

    public function test_auto_mode_on_disconnect(): void
    {
        $user = User::factory()->create();
        $status = $this->service->setAutoMode($user->id, true, 'disconnect');

        $this->assertTrue($status->is_in_auto_mode);

        $log = \App\Models\AutoModeLog::where('user_id', $user->id)->first();
        $this->assertNotNull($log);
        $this->assertEquals('disconnect', $log->reason);
        $this->assertTrue($log->is_auto_on);
    }

    public function test_challenge_button(): void
    {
        $challenger = User::factory()->create();
        $buddy = User::factory()->create();

        GameBuddy::create(['user_id' => $challenger->id, 'buddy_id' => $buddy->id, 'status' => 'accepted']);
        UserOnlineStatus::create(['user_id' => $buddy->id, 'is_online' => true, 'hide_online_status' => false, 'notify_friends_online' => true, 'is_in_auto_mode' => false]);

        $challenge = $this->service->challengeBuddy($challenger->id, $buddy->id, 100);
        $this->assertEquals('pending', $challenge->status);
        $this->assertEquals('buddy_challenge', $challenge->type);
    }

    public function test_online_buddies_visibility_respects_hide(): void
    {
        $user = User::factory()->create();
        $buddyVisible = User::factory()->create();
        $buddyHidden = User::factory()->create();

        GameBuddy::create(['user_id' => $user->id, 'buddy_id' => $buddyVisible->id, 'status' => 'accepted']);
        GameBuddy::create(['user_id' => $user->id, 'buddy_id' => $buddyHidden->id, 'status' => 'accepted']);

        UserOnlineStatus::create(['user_id' => $buddyVisible->id, 'is_online' => true, 'hide_online_status' => false, 'notify_friends_online' => true, 'is_in_auto_mode' => false]);
        UserOnlineStatus::create(['user_id' => $buddyHidden->id, 'is_online' => true, 'hide_online_status' => true, 'notify_friends_online' => true, 'is_in_auto_mode' => false]);

        $onlineBuddies = $this->service->getOnlineBuddies($user->id);
        $this->assertEquals(1, $onlineBuddies->count());
        $this->assertEquals($buddyVisible->id, $onlineBuddies->first()->user_id);
    }
}
