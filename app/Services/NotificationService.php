<?php

namespace App\Services;

use App\Mail\UserNotification;
use App\Models\Notification;
use App\Models\User;
use App\Services\Push\PushDispatcher;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Mail;

/**
 * In-app + email + push notifications (Phase 11/19).
 *
 * The single authority for creating notifications. `send()` always persists
 * the in-app row (single insert — safe inside callers' transactions), then
 * attempts a best-effort email and a best-effort push fan-out — neither can
 * ever fail the originating action. Recipients, content and links are always
 * server-derived; clients can only mark their own notifications read.
 */
class NotificationService
{
    public function __construct(
        protected PushDispatcher $push,
    ) {}

    /**
     * Create a notification for one user (in-app row + best-effort email +
     * best-effort push).
     */
    public function send(
        User $recipient,
        string $type,
        string $title,
        string $body,
        ?string $link = null,
        array $data = [],
    ): Notification {
        $notification = new Notification();
        $notification->user_id = $recipient->id;
        $notification->type = $type;
        $notification->title = trim($title);
        $notification->body = trim($body);
        $notification->link = $link;
        $notification->data = $data;
        $notification->save();

        $this->email($recipient, $type, $title, $body, $link);
        $this->push->sendToUser($recipient, $notification);

        return $notification;
    }

    /**
     * Send the same notification to many users, de-duplicating by id.
     *
     * @param  iterable<User>  $recipients
     */
    public function sendToMany(
        iterable $recipients,
        string $type,
        string $title,
        string $body,
        ?string $link = null,
        array $data = [],
    ): int {
        $sent = 0;
        $seen = [];

        foreach ($recipients as $recipient) {
            if (! $recipient instanceof User) {
                continue;
            }

            if (isset($seen[$recipient->id])) {
                continue;
            }

            $seen[$recipient->id] = true;

            $this->send($recipient, $type, $title, $body, $link, $data);

            $sent++;
        }

        return $sent;
    }

    /**
     * The user's notifications, newest first.
     */
    public function forUser(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Number of unread notifications (for the navigation badge).
     */
    public function unreadCount(User $user): int
    {
        return Notification::where('user_id', $user->id)->unread()->count();
    }

    /**
     * Mark a notification read (ownership enforced server-side).
     */
    public function markRead(Notification $notification, User $user): Notification
    {
        if ($notification->user_id !== $user->id) {
            throw new DomainException('You cannot read another user\'s notification.');
        }

        if ($notification->read_at === null) {
            $notification->read_at = now();
            $notification->save();
        }

        return $notification;
    }

    /**
     * Mark every notification read for a user.
     */
    public function markAllRead(User $user): int
    {
        return Notification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Build a route link safely (null when the route is unavailable), so a
     * missing route can never break a business flow.
     */
    public static function link(string $name, array $params = []): ?string
    {
        try {
            return route($name, $params);
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Best-effort email delivery. Never throws into the caller.
     */
    protected function email(User $recipient, string $type, string $title, string $body, ?string $link): void
    {
        if (! config('notifications.email_enabled', true)) {
            return;
        }

        $address = trim((string) $recipient->email);

        if ($address === '') {
            return;
        }

        try {
            Mail::to($address)->send(new UserNotification($type, $title, $body, $link));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
