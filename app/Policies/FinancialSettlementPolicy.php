<?php

namespace App\Policies;

use App\Models\User;

/**
 * Financial settlement snapshots are admin-only (Phase 09).
 */
class FinancialSettlementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user): bool
    {
        return $user->isAdmin();
    }

    public function finalize(User $user): bool
    {
        return $user->isAdmin();
    }
}
