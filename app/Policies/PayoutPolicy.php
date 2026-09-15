<?php

namespace App\Policies;

use App\Models\Payout;
use App\Models\User;

/**
 * Payout authorization (Phase 09).
 *
 * Only admins may list and act on payouts. A recipient may view their own
 * payout history; nobody may view another user's payouts.
 */
class PayoutPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Payout $payout): bool
    {
        return $user->isAdmin() || $payout->recipient_user_id === $user->id;
    }

    public function approve(User $user, Payout $payout): bool
    {
        return $user->isAdmin();
    }

    public function process(User $user, Payout $payout): bool
    {
        return $user->isAdmin();
    }

    public function complete(User $user, Payout $payout): bool
    {
        return $user->isAdmin();
    }

    public function fail(User $user, Payout $payout): bool
    {
        return $user->isAdmin();
    }

    public function cancel(User $user, Payout $payout): bool
    {
        return $user->isAdmin();
    }
}
