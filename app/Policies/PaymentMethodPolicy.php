<?php
namespace App\Policies;
use App\Models\User;
use App\Models\PaymentMethod;
class PaymentMethodPolicy
{
    public function update(User $user, PaymentMethod $method): bool { return $user->id === $method->user_id || $user->isAdmin(); }
    public function delete(User $user, PaymentMethod $method): bool { return $user->id === $method->user_id || $user->isAdmin(); }
}
