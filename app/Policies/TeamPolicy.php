<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    /**
     * A user can act on a team when they are an admin, the tournament
     * organizer, or the team captain.
     */
    public function manage(User $user, Team $team): bool
    {
        return $user->isAdmin()
            || $team->tournament->organizer_id === $user->id
            || $team->isCaptain($user);
    }

    /**
     * Only an authorized actor may view/pay for a team's entry fee.
     */
    public function pay(User $user, Team $team): bool
    {
        return $this->manage($user, $team);
    }

    /**
     * Only an authorized actor may submit a score on behalf of a team.
     */
    public function submitScore(User $user, Team $team): bool
    {
        return $this->manage($user, $team);
    }

    /**
     * Only an authorized actor may view team-level details.
     */
    public function view(User $user, Team $team): bool
    {
        return $this->manage($user, $team);
    }

    /**
     * Only an authorized actor may withdraw a team. The lifecycle rule that
     * forbids withdrawal after the tournament goes live is enforced in the
     * controller/service — not here — because it is a state concern.
     */
    public function withdraw(User $user, Team $team): bool
    {
        return $this->manage($user, $team);
    }

    /**
     * Roster editing (add/remove members, edit team profile) is restricted
     * to the team captain and admins. Tournament organizers manage the
     * tournament, not individual team rosters — this keeps competitive
     * integrity (an organizer cannot silently alter a roster).
     */
    public function manageRoster(User $user, Team $team): bool
    {
        return $user->isAdmin() || $team->isCaptain($user);
    }

    public function addMember(User $user, Team $team): bool
    {
        return $this->manageRoster($user, $team);
    }

    /**
     * Only the team captain (or an admin) may check the team in.
     */
    public function checkIn(User $user, Team $team): bool
    {
        return $user->isAdmin() || $team->isCaptain($user);
    }

    public function removeMember(User $user, Team $team): bool
    {
        return $this->manageRoster($user, $team);
    }

    public function updateProfile(User $user, Team $team): bool
    {
        return $this->manageRoster($user, $team);
    }
}
