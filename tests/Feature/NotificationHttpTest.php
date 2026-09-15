<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Phase 11 — HTTP authorization for the notification inbox (IDOR / role
 * escalation), read-state routes and the unread badge.
 */
class NotificationHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function service(): NotificationService
    {
        return app(NotificationService::class);
    }

    public function test_guest_is_redirected_from_notifications(): void
    {
        $this->get('/notifications')->assertRedirect('/login');
    }

    public function test_authenticated_user_sees_their_inbox(): void
    {
        Mail::fake();
        config(['notifications.email_enabled' => false]);

        $user = $this->makeUser();
        $this->service()->send($user, 'system', 'Hello', 'Body');

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Notifications')
            ->assertSee('Hello');
    }

    public function test_user_cannot_see_another_users_notifications(): void
    {
        Mail::fake();
        config(['notifications.email_enabled' => false]);

        $owner = $this->makeUser();
        $intruder = $this->makeUser();

        $notification = $this->service()->send($owner, 'system', 'Private', 'Secret body.');

        // The intruder's inbox must not contain the owner's notification.
        $this->actingAs($intruder)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertDontSee('Private');
    }

    public function test_user_cannot_mark_another_users_notification_read(): void
    {
        Mail::fake();
        config(['notifications.email_enabled' => false]);

        $owner = $this->makeUser();
        $intruder = $this->makeUser();

        $notification = $this->service()->send($owner, 'system', 'Private', 'Body');

        $this->actingAs($intruder)
            ->post(route('notifications.read', $notification))
            ->assertStatus(403);

        $this->assertFalse($notification->fresh()->isRead());
    }

    public function test_owner_can_mark_read_via_http(): void
    {
        Mail::fake();
        config(['notifications.email_enabled' => false]);

        $owner = $this->makeUser();
        $notification = $this->service()->send($owner, 'system', 'Hello', 'Body');

        $this->actingAs($owner)
            ->from(route('notifications.index'))
            ->post(route('notifications.read', $notification))
            ->assertRedirect(route('notifications.index'));

        $this->assertTrue($notification->fresh()->isRead());
    }

    public function test_owner_can_mark_all_read_via_http(): void
    {
        Mail::fake();
        config(['notifications.email_enabled' => false]);

        $owner = $this->makeUser();
        $this->service()->send($owner, 'system', 'A', '1');
        $this->service()->send($owner, 'system', 'B', '2');

        $this->actingAs($owner)
            ->from(route('notifications.index'))
            ->post(route('notifications.readAll'))
            ->assertRedirect(route('notifications.index'));

        $this->assertSame(0, $this->service()->unreadCount($owner));
    }

    public function test_badge_count_is_exposed_to_layout(): void
    {
        Mail::fake();
        config(['notifications.email_enabled' => false]);

        $owner = $this->makeUser();
        $this->service()->send($owner, 'system', 'A', '1');

        $this->actingAs($owner)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Notifications');
    }

    public function test_non_existent_notification_returns_404(): void
    {
        $this->actingAs($this->makeUser())
            ->post(route('notifications.read', 999999))
            ->assertNotFound();
    }
}
