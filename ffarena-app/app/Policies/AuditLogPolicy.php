<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

/**
 * The audit trail is admin-only and read-only. There are deliberately no
 * create/update/delete abilities here: rows are written by AuditLogService
 * and the model itself refuses mutation.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $user->isAdmin();
    }

    public function export(User $user): bool
    {
        return $user->isAdmin();
    }
}
