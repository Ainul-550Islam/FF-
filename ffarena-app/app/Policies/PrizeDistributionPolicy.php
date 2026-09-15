<?php

namespace App\Policies;

use App\Models\User;

/**
 * Prize distribution is a platform financial operation. Only admins may
 * view, configure, calculate, approve, process or cancel a distribution —
 * tournament organizers and players have no access, matching the Phase 08
 * rule that only admins verify/refund payments.
 */
class PrizeDistributionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user): bool
    {
        return $user->isAdmin();
    }

    public function configure(User $user): bool
    {
        return $user->isAdmin();
    }

    public function calculate(User $user): bool
    {
        return $user->isAdmin();
    }

    public function approve(User $user): bool
    {
        return $user->isAdmin();
    }

    public function process(User $user): bool
    {
        return $user->isAdmin();
    }

    public function cancel(User $user): bool
    {
        return $user->isAdmin();
    }

    public function adjust(User $user): bool
    {
        return $user->isAdmin();
    }
}
