<?php

namespace App\Policies;

use App\Models\MobileDevice;
use App\Models\User;

/**
 * Phase 18 — mobile push devices are strictly owner-only. There is no staff
 * override by design: nobody but the device owner may read or remove a
 * registered push token reference.
 */
class MobileDevicePolicy
{
    public function viewAny(User $user): bool
    {
        return true; // scoped to the caller's own rows in the controller
    }

    public function view(User $user, MobileDevice $device): bool
    {
        return $device->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function delete(User $user, MobileDevice $device): bool
    {
        return $device->user_id === $user->id;
    }
}
