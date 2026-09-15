<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserIdentity;

/**
 * Connected-account identities (google, phone) are private to their owner;
 * admins may view them through account administration.
 */
class UserIdentityPolicy
{
    public function viewAny(User $actor): bool
    {
        return true; // controller scopes to the authenticated user
    }

    public function view(User $actor, UserIdentity $identity): bool
    {
        return $identity->user_id === $actor->id || $actor->isAdmin();
    }

    public function unlink(User $actor, UserIdentity $identity): bool
    {
        return $identity->user_id === $actor->id || $actor->isAdmin();
    }
}
