<?php

namespace App\Policies;

use App\Models\SupportTicket;
use App\Models\User;

/**
 * Support authorization (Phase 13).
 *
 * Players access only their own tickets. Organizers additionally access
 * tickets related to their own tournaments. Moderators and admins work the
 * global support queue. Internal notes and assignment/status changes are
 * platform-staff only.
 */
class SupportTicketPolicy
{
    /**
     * Staff for a ticket: platform staff, or the organizer of the ticket's
     * tournament (when it relates to one).
     */
    public function isTicketStaff(User $user, SupportTicket $ticket): bool
    {
        if ($user->isAdmin() || $user->isModerator()) {
            return true;
        }

        return $ticket->tournament_id !== null
            && $ticket->tournament !== null
            && $ticket->tournament->organizer_id === $user->id;
    }

    public function view(User $user, SupportTicket $ticket): bool
    {
        return $ticket->user_id === $user->id || $this->isTicketStaff($user, $ticket);
    }

    public function viewAny(User $user): bool
    {
        return $user->isStaff() || $user->isOrganizer();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function reply(User $user, SupportTicket $ticket): bool
    {
        return $this->view($user, $ticket);
    }

    public function close(User $user, SupportTicket $ticket): bool
    {
        return $ticket->user_id === $user->id || $this->isTicketStaff($user, $ticket);
    }

    public function reopen(User $user, SupportTicket $ticket): bool
    {
        return $ticket->user_id === $user->id || $this->isTicketStaff($user, $ticket);
    }

    public function assign(User $user, SupportTicket $ticket): bool
    {
        return $user->isStaff();
    }

    public function changeStatus(User $user, SupportTicket $ticket): bool
    {
        return $user->isStaff();
    }

    public function addInternalNote(User $user, SupportTicket $ticket): bool
    {
        return $user->isStaff();
    }

    public function export(User $user): bool
    {
        return $user->isStaff();
    }
}
