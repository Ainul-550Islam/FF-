<?php

namespace App\Policies;

use App\Models\GameMatch;
use App\Models\User;

class GameMatchPolicy
{
    /**
     * Privileged match management (publish room, set winner, dispute, resolve,
     * correct results). Only the owning organizer or an admin may do this.
     * Players can NEVER manage, dispute or resolve matches.
     */
    public function manage(User $user, GameMatch $match): bool
    {
        return $user->isAdmin()
            || $match->tournament->organizer_id === $user->id;
    }

    /**
     * Entering the disputed state is a privileged bracket operation.
     */
    public function dispute(User $user, GameMatch $match): bool
    {
        return $this->manage($user, $match);
    }

    /**
     * Resolving a dispute (including correcting a winner) is privileged and
     * audited via the same organizer/admin gate.
     */
    public function resolve(User $user, GameMatch $match): bool
    {
        return $this->manage($user, $match);
    }

    /**
     * Opening a Phase 07 dispute: staff (admin/moderator/organizer) or the
     * captain of a participating team. The dispute window and other business
     * rules are enforced by DisputeService.
     */
    public function openDispute(User $user, GameMatch $match): bool
    {
        if ($user->isAdmin() || $user->isModerator() || $match->tournament->organizer_id === $user->id) {
            return true;
        }

        return $match->participantTeamFor($user) !== null;
    }
}
