<?php

namespace App\Policies;

use App\Models\User;

/**
 * Prize-tier configuration is admin-only (Phase 09).
 */
class PrizeTierPolicy
{
    public function configure(User $user): bool
    {
        return $user->isAdmin();
    }
}
