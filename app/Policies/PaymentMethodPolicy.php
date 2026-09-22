<?php

namespace App\Policies;

use App\Models\PaymentMethod;
use App\Models\User;

/**
 * Saved payment methods are private to their owner (Phase 14). Admins may
 * inspect them through the account-administration screen.
 */
class PaymentMethodPolicy
{
    public function viewAny(User $actor): bool
    {
        return true; // authenticated users manage their own list
    }

    public function create(User $actor): bool
    {
        return true;
    }

    public function delete(User $actor, PaymentMethod $method): bool
    {
        return $method->user_id === $actor->id || $actor->isAdmin();
    }

    public function setDefault(User $actor, PaymentMethod $method): bool
    {
        return $method->user_id === $actor->id || $actor->isAdmin();
    }

    /**
     * Legacy helper kept for the pre-Phase14 payment-method screens
     * (AccountSecurityController authorizes `update` on the method).
     */
    public function update(User $actor, PaymentMethod $method): bool
    {
        return $method->user_id === $actor->id || $actor->isAdmin();
    }
}
