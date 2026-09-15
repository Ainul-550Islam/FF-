<?php

namespace App\Policies;

use App\Models\AntiCheatIncident;
use App\Models\User;

/**
 * Anti-cheat incident authorization (Phase 10).
 *
 * Admins and moderators may review and resolve incidents. Organizers may
 * view incidents in their own tournaments but never resolve them. Players
 * may only view incidents they reported — never another player's case.
 */
class AntiCheatIncidentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isModerator() || $user->isOrganizer();
    }

    public function view(User $user, AntiCheatIncident $incident): bool
    {
        if ($user->isAdmin() || $user->isModerator()) {
            return true;
        }

        if ($user->isOrganizer() && $incident->tournament->organizer_id === $user->id) {
            return true;
        }

        return $incident->reporter_user_id === $user->id;
    }

    public function review(User $user, AntiCheatIncident $incident): bool
    {
        return $user->isAdmin() || $user->isModerator();
    }

    public function resolve(User $user, AntiCheatIncident $incident): bool
    {
        return $user->isAdmin() || $user->isModerator();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isModerator();
    }
}
