<?php
namespace App\Policies;
use App\Models\User;
class UserPolicy
{
    public function view(User $auth, User $target): bool { return $auth->id === $target->id || $auth->isStaff(); }
    public function update(User $auth, User $target): bool { return $auth->id === $target->id || $auth->isAdmin(); }
}
