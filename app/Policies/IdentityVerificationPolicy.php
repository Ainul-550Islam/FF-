<?php

namespace App\Policies;

use App\Models\User;

/**
 * Identity-verification authorization (Phase 10).
 *
 * A user may request verification only for themselves and can never mark
 * themselves verified. Only admins approve/reject verification.
 */
class IdentityVerificationPolicy
{
    public function request(User $user): bool
    {
        return true; // self-service, always the authenticated user
    }

    public function verify(User $user): bool
    {
        return $user->isAdmin();
    }

    public function reject(User $user): bool
    {
        return $user->isAdmin();
    }
}
