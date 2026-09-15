<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    /**
     * Only admins, the payment's tournament organizer, or the paying team's
     * captain may view a payment.
     */
    public function view(User $user, Payment $payment): bool
    {
        return $user->isAdmin()
            || $payment->tournament->organizer_id === $user->id
            || $payment->team->isCaptain($user);
    }

    /**
     * Only admins may verify/reject/refund payments.
     */
    public function verify(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Only admins may issue refunds (authorized financial operation).
     */
    public function refund(User $user, Payment $payment): bool
    {
        return $user->isAdmin();
    }
}
