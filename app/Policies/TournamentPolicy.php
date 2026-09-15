<?php

namespace App\Policies;

use App\Models\Tournament;
use App\Models\User;

class TournamentPolicy
{
    /**
     * Only organizers and admins may create tournaments.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isOrganizer();
    }

    /**
     * Ownership gate shared by every lifecycle action: only the owning
     * organizer or an admin may act on a tournament.
     */
    public function update(User $user, Tournament $tournament): bool
    {
        return $user->isAdmin() || $tournament->organizer_id === $user->id;
    }

    public function publish(User $user, Tournament $tournament): bool
    {
        return $this->update($user, $tournament);
    }

    public function closeRegistration(User $user, Tournament $tournament): bool
    {
        return $this->update($user, $tournament);
    }

    public function start(User $user, Tournament $tournament): bool
    {
        return $this->update($user, $tournament);
    }

    public function complete(User $user, Tournament $tournament): bool
    {
        return $this->update($user, $tournament);
    }

    public function cancel(User $user, Tournament $tournament): bool
    {
        return $this->update($user, $tournament);
    }

    /**
     * Managing scoring rules is a privileged tournament operation: only the
     * owning organizer or an admin. Participants can never change rules.
     */
    public function manageScoring(User $user, Tournament $tournament): bool
    {
        return $this->update($user, $tournament);
    }
}
