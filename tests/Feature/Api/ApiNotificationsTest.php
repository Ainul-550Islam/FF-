<?php

namespace Tests\Feature\Api;

use App\Models\Notification;
use App\Services\NotificationService;

/**
 * Phase 15 — notifications: ownership, unread counts, mark read, and IDOR
 * protection.
 */
class ApiNotificationsTest extends ApiTestCase
{
    protected function notify($user, string $type = Notification::TYPE_SYSTEM, bool $read = false): Notification
    {
        return app(NotificationService::class)->send(
            $user,
            $type,
            'Title',
            'Body',
            null,
            [],
        );
    }

    public function test_notifications_list_is_own_only(): void
    {
        $user = $this->user();
        $other = $this->user();

        $mine = $this->notify($user);
        $this->notify($other);

        $res = $this->asUser($user, ['notifications:read'])->getJson('/api/v1/me/notifications');

        $res->assertStatus(200);
        $this->assertSame([$mine->id], array_column($res->json('data'), 'id'));
    }

    public function test_unread_count_and_mark_read(): void
    {
        $user = $this->user();
        $notification = $this->notify($user);

        $this->asUser($user, ['notifications:read'])->getJson('/api/v1/me/notifications/unread-count')
            ->assertJsonPath('data.unread_count', 1);

        $this->asUser($user, ['notifications:write'])
            ->postJson('/api/v1/me/notifications/'.$notification->id.'/read')
            ->assertStatus(200)
            ->assertJsonPath('data.read', true);

        $this->asUser($user, ['notifications:read'])->getJson('/api/v1/me/notifications/unread-count')
            ->assertJsonPath('data.unread_count', 0);
    }

    public function test_mark_read_idor_is_blocked(): void
    {
        $user = $this->user();
        $other = $this->user();
        $notification = $this->notify($other);

        $this->asUser($user, ['notifications:write'])
            ->postJson('/api/v1/me/notifications/'.$notification->id.'/read')
            ->assertStatus(404);

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_mark_all_read_only_affects_own(): void
    {
        $user = $this->user();
        $other = $this->user();
        $this->notify($user);
        $this->notify($user);
        $this->notify($other);

        $res = $this->asUser($user, ['notifications:write'])->postJson('/api/v1/me/notifications/read-all');
        $res->assertStatus(200)->assertJsonPath('data.marked_read', 2);

        $this->assertSame(0, Notification::where('user_id', $user->id)->whereNull('read_at')->count());
        $this->assertSame(1, Notification::where('user_id', $other->id)->whereNull('read_at')->count());
    }
}
