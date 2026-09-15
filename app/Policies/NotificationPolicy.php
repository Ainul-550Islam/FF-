<?php

namespace App\Policies;

use App\Models\Notification;
use App\Models\User;

/**
 * Notification authorization (Phase 11).
 *
 * A user can only ever see and mark-read their own notifications. There is
 * no staff override that exposes another user's inbox.
 */
class NotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // the inbox is always the authenticated user's own
    }

    public function view(User $user, Notification $notification): bool
    {
        return $notification->user_id === $user->id;
    }

    public function markRead(User $user, Notification $notification): bool
    {
        return $notification->user_id === $user->id;
    }

    public function markAllRead(User $user): bool
    {
        return true; // acts only on the authenticated user's own rows
    }
}
