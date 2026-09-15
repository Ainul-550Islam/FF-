<?php

namespace App\Policies;

use App\Models\LoginEvent;
use App\Models\User;

/**
 * Login history is private to the account owner; admins may view any user's
 * history through account administration.
 */
class LoginEventPolicy
{
    public function viewAny(User $actor): bool
    {
        return true; // the controller scopes to the authenticated user
    }

    public function view(User $actor, LoginEvent $event): bool
    {
        return $event->user_id === $actor->id || $actor->isAdmin();
    }
}
