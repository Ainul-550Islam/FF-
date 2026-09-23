<?php

namespace App\Policies;

use App\Models\Restriction;
use App\Models\RiskProfile;
use App\Models\User;

/**
 * Authorization for fraud/risk data (Phase 10).
 *
 * Admins manage everything. Moderators may view risk summaries and events
 * (but never modify restrictions, scores or verification). Users may view
 * only their own risk summary, never another user's risk/device/IP data.
 */
class RiskProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isModerator();
    }

    public function view(User $user, RiskProfile $profile): bool
    {
        return $user->isAdmin()
            || $user->isModerator()
            || $profile->user_id === $user->id;
    }

    public function viewUser(User $user, User $subject): bool
    {
        return $user->isAdmin()
            || $user->isModerator()
            || $subject->id === $user->id;
    }

    public function viewEvents(User $user): bool
    {
        return $user->isAdmin() || $user->isModerator();
    }

    public function manageRestrictions(User $user): bool
    {
        return $user->isAdmin();
    }

    public function liftRestriction(User $user, Restriction $restriction): bool
    {
        return $user->isAdmin();
    }
}
