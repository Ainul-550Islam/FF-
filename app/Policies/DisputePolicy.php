<?php

namespace App\Policies;

use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\User;

class DisputePolicy
{
    /**
     * Staff for a dispute: platform staff (admin/moderator) or the owning
     * tournament organizer.
     */
    public function isStaff(User $user, Dispute $dispute): bool
    {
        return $user->isAdmin()
            || $user->isModerator()
            || $dispute->tournament->organizer_id === $user->id;
    }

    /**
     * A participant in the dispute: the opener or the captain of a
     * participating team.
     */
    public function isParticipant(User $user, Dispute $dispute): bool
    {
        if ($dispute->opened_by === $user->id) {
            return true;
        }

        return $dispute->match->participantTeamFor($user) !== null;
    }

    /**
     * Viewing a dispute: staff or an involved participant.
     */
    public function view(User $user, Dispute $dispute): bool
    {
        return $this->isStaff($user, $dispute) || $this->isParticipant($user, $dispute);
    }

    /**
     * Adding evidence: anyone who can view the dispute (the service further
     * rejects submissions once the dispute is terminal).
     */
    public function addEvidence(User $user, Dispute $dispute): bool
    {
        return $this->view($user, $dispute);
    }

    /**
     * Viewing evidence: same gate as viewing the dispute, plus the evidence
     * must belong to it.
     */
    public function viewEvidence(User $user, Dispute $dispute, DisputeEvidence $evidence): bool
    {
        return $evidence->dispute_id === $dispute->id && $this->view($user, $dispute);
    }

    /**
     * All privileged moderation actions (review, assign, resolve, reject,
     * staff cancel, evidence removal) are staff-only.
     */
    public function manage(User $user, Dispute $dispute): bool
    {
        return $this->isStaff($user, $dispute);
    }

    public function review(User $user, Dispute $dispute): bool
    {
        return $this->manage($user, $dispute);
    }

    public function assign(User $user, Dispute $dispute): bool
    {
        return $this->manage($user, $dispute);
    }

    public function resolve(User $user, Dispute $dispute): bool
    {
        return $this->manage($user, $dispute);
    }

    public function reject(User $user, Dispute $dispute): bool
    {
        return $this->manage($user, $dispute);
    }

    public function removeEvidence(User $user, Dispute $dispute, DisputeEvidence $evidence): bool
    {
        return $evidence->dispute_id === $dispute->id && $this->manage($user, $dispute);
    }

    /**
     * Cancelling: staff, or the opener of their own still-open dispute.
     */
    public function cancel(User $user, Dispute $dispute): bool
    {
        return $this->isStaff($user, $dispute) || $dispute->opened_by === $user->id;
    }
}
