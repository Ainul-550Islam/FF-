<?php

namespace Tests\Feature;

use App\Mail\UserNotification;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use DomainException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Phase 11 — notification service core: creation, delivery, read state,
 * ownership enforcement and best-effort email semantics.
 */
class NotificationServiceTest extends TestCase
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

    public function test_send_persists_an_in_app_notification(): void
    {
        Mail::fake();

        $user = $this->makeUser();

        $notification = $this->service()->send(
            $user,
            Notification::TYPE_PAYMENT_VERIFIED,
            'Payment verified',
            'Your payment was verified.',
            '/wallet',
            ['payment_id' => 5],
        );

        $this->assertSame($user->id, $notification->user_id);
        $this->assertSame(Notification::TYPE_PAYMENT_VERIFIED, $notification->type);
        $this->assertSame('Payment verified', $notification->title);
        $this->assertSame('Your payment was verified.', $notification->body);
        $this->assertSame('/wallet', $notification->link);
        $this->assertSame(['payment_id' => 5], $notification->data);
        $this->assertFalse($notification->isRead());
        $this->assertSame(1, Notification::where('user_id', $user->id)->count());
    }

    public function test_email_is_sent_best_effort_when_enabled(): void
    {
        Mail::fake();
        config(['notifications.email_enabled' => true]);

        $user = $this->makeUser();

        $this->service()->send($user, 'system', 'Hello', 'A test body.');

        Mail::assertSent(UserNotification::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email) && $mail->subject === 'Hello';
        });
    }

    public function test_email_is_skipped_when_disabled_but_in_app_remains(): void
    {
        Mail::fake();
        config(['notifications.email_enabled' => false]);

        $user = $this->makeUser();

        $this->service()->send($user, 'system', 'Hello', 'A test body.');

        Mail::assertNothingSent();
        $this->assertSame(1, Notification::where('user_id', $user->id)->count());
    }

    public function test_email_failure_never_breaks_the_notification(): void
    {
        Mail::fake();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));
        config(['notifications.email_enabled' => true]);

        $user = $this->makeUser();

        // Must not throw, and the in-app row must still exist.
        $this->service()->send($user, 'system', 'Hello', 'A test body.');

        $this->assertSame(1, Notification::where('user_id', $user->id)->count());
    }

    public function test_send_to_many_deduplicates_recipients(): void
    {
        Mail::fake();
        config(['notifications.email_enabled' => false]);

        $user = $this->makeUser();

        $count = $this->service()->sendToMany([$user, $user, $user], 'system', 'Hi', 'Body');

        $this->assertSame(1, $count);
        $this->assertSame(1, Notification::where('user_id', $user->id)->count());
    }

    public function test_send_to_many_skips_non_user_entries(): void
    {
        Mail::fake();
        config(['notifications.email_enabled' => false]);

        $user = $this->makeUser();

        $count = $this->service()->sendToMany([$user, null, 'nope'], 'system', 'Hi', 'Body');

        $this->assertSame(1, $count);
    }

    public function test_mark_read_requires_ownership(): void
    {
        Mail::fake();
        config(['notifications.email_enabled' => false]);

        $owner = $this->makeUser();
        $intruder = $this->makeUser();

        $notification = $this->service()->send($owner, 'system', 'Hi', 'Body');

        $this->expectException(DomainException::class);
        $this->service()->markRead($notification, $intruder);
    }

    public function test_mark_read_is_idempotent(): void
    {
        Mail::fake();
        config(['notifications.email_enabled' => false]);

        $owner = $this->makeUser();
        $notification = $this->service()->send($owner, 'system', 'Hi', 'Body');

        $this->service()->markRead($notification, $owner);
        $this->service()->markRead($notification, $owner);

        $this->assertTrue($notification->fresh()->isRead());
    }

    public function test_mark_all_read_only_affects_own_rows(): void
    {
        Mail::fake();
        config(['notifications.email_enabled' => false]);

        $user = $this->makeUser();
        $other = $this->makeUser();

        $this->service()->send($user, 'system', 'A', '1');
        $this->service()->send($user, 'system', 'B', '2');
        $this->service()->send($other, 'system', 'C', '3');

        $this->assertSame(2, $this->service()->markAllRead($user));

        $this->assertSame(0, $this->service()->unreadCount($user));
        $this->assertSame(1, $this->service()->unreadCount($other));
    }

    public function test_unread_count_and_pagination(): void
    {
        Mail::fake();
        config(['notifications.email_enabled' => false]);

        $user = $this->makeUser();

        foreach (range(1, 5) as $i) {
            $this->service()->send($user, 'system', 'Title '.$i, 'Body '.$i);
        }

        $this->assertSame(5, $this->service()->unreadCount($user));

        $page = $this->service()->forUser($user, 3);

        $this->assertCount(3, $page->items());
        $this->assertSame(5, $page->total());
        $this->assertSame('Title 5', $page->items()[0]->title); // newest first
    }

    public function test_link_helper_returns_null_for_missing_route(): void
    {
        $this->assertSame('http://localhost/wallet', NotificationService::link('wallet.index'));
        $this->assertNull(NotificationService::link('route.that.does.not.exist'));
    }

    public function test_mass_assignment_is_guarded(): void
    {
        $user = $this->makeUser();

        $notification = new Notification();

        try {
            $notification->fill([
                'user_id' => $user->id,
                'type' => 'system',
                'title' => 'Injected',
                'body' => 'Injected',
            ]);
            $this->fail('Notification accepted mass assignment.');
        } catch (MassAssignmentException $e) {
            $this->addToAssertionCount(1);
        }
    }
}
