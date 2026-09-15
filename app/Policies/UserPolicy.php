<?php

namespace App\Policies;

use App\Models\User;

/**
 * Authorization for profile, settings, sessions, connected accounts and the
 * account lifecycle (Phase 14). Registered under the `User` model by
 * convention.
 *
 * A player can only act on their own account; admins may act on any account
 * for the operations listed below. Organizers and moderators get no special
 * account administration.
 */
class UserPolicy
{
    /**
     * Whether a viewer may see the target's public profile. Guests may see
     * `public` profiles; `registered` requires a signed-in viewer; `private`
     * restricts to the owner and staff.
     */
    public function viewProfile(?User $viewer, User $target): bool
    {
        if ($viewer !== null && $viewer->id === $target->id) {
            return true;
        }

        if ($viewer !== null && $viewer->isStaff()) {
            return true;
        }

        return match ($target->privacy ?? 'public') {
            'private' => false,
            'registered' => $viewer !== null,
            default => true,
        };
    }

    /**
     * Edit the profile (self, or admin).
     */
    public function updateProfile(User $actor, User $target): bool
    {
        return $actor->id === $target->id || $actor->isAdmin();
    }

    /**
     * Manage security settings, sessions, connected accounts (self).
     */
    public function manageSecurity(User $actor, User $target): bool
    {
        return $actor->id === $target->id;
    }

    /**
     * Manage sessions (self or admin).
     */
    public function manageSessions(User $actor, User $target): bool
    {
        return $actor->id === $target->id || $actor->isAdmin();
    }

    /**
     * View login history (self or admin).
     */
    public function viewLoginHistory(User $actor, User $target): bool
    {
        return $actor->id === $target->id || $actor->isAdmin();
    }

    /**
     * Deactivate / reactivate (self or admin).
     */
    public function deactivate(User $actor, User $target): bool
    {
        return $actor->id === $target->id || $actor->isAdmin();
    }

    public function reactivate(User $actor, User $target): bool
    {
        return $actor->id === $target->id || $actor->isAdmin();
    }

    /**
     * Request/cancel deletion (self only).
     */
    public function requestDeletion(User $actor, User $target): bool
    {
        return $actor->id === $target->id;
    }

    /**
     * Admin-only account administration.
     */
    public function adminAccounts(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function adminAccount(User $actor, User $target): bool
    {
        return $actor->isAdmin();
    }

    public function adminRevokeSessions(User $actor, User $target): bool
    {
        return $actor->isAdmin();
    }

    public function adminDeactivate(User $actor, User $target): bool
    {
        return $actor->isAdmin();
    }

    public function adminDelete(User $actor, User $target): bool
    {
        return $actor->isAdmin();
    }
}
