<?php

namespace App\Services;

use App\Models\LiveEvent;
use App\Models\Notification;
use App\Models\SupportInternalNote;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Support ticket system (Phase 13).
 *
 * The single authority for creating and mutating tickets. Every status
 * change goes through the validated transition map; assignee and status are
 * never mass-assignable; internal notes are kept strictly separate from
 * user-visible messages and are never exposed to the requester.
 *
 * Integrates Phase 11 (notifications) and Phase 12 (staff-only live events)
 * — it never builds a second notification or realtime system.
 */
class SupportTicketService
{
    public function __construct(
        protected NotificationService $notifications,
        protected LiveEventService $live,
        protected AuditLogService $audit,
    ) {}

    /**
     * Create a ticket from an authenticated user, seeding the first message.
     */
    public function create(User $user, array $data): SupportTicket
    {
        $ticket = new SupportTicket();
        $ticket->user_id = $user->id;
        $ticket->subject = trim($data['subject']);
        $ticket->category = in_array($data['category'], SupportTicket::CATEGORIES, true)
            ? $data['category']
            : 'general';
        $ticket->priority = in_array($data['priority'] ?? null, SupportTicket::PRIORITIES, true)
            ? $data['priority']
            : SupportTicket::PRIORITY_NORMAL;
        $ticket->status = SupportTicket::STATUS_OPEN;
        $ticket->last_activity_at = now();
        $ticket->save();

        $this->appendMessage($ticket, $user, trim($data['message']));

        $this->notifications->send(
            $user,
            Notification::TYPE_SUPPORT_CREATED,
            'Support ticket created',
            'Your ticket "'.$ticket->subject.'" has been created. We will reply as soon as possible.',
            self::ticketLink($ticket),
            ['ticket_id' => $ticket->id],
        );

        // Staff-only realtime signal (never exposes the ticket body).
        $this->live->recordQuietly(null, $user, LiveEvent::TYPE_SUPPORT_CREATED, [
            'ticket_id' => $ticket->id,
            'category' => $ticket->category,
            'priority' => $ticket->priority,
        ]);

        $this->audit->recordQuietly($user, 'support.created', 'support_ticket', $ticket->id, [
            'metadata' => ['category' => $ticket->category, 'priority' => $ticket->priority],
        ]);

        // Phase 15 — outbound webhook (best-effort; subject/body are never
        // included).
        app(WebhookDispatcher::class)->dispatchQuietly('support.ticket.created', [
            'ticket_id' => $ticket->id,
            'category' => $ticket->category,
            'priority' => $ticket->priority,
        ]);

        return $ticket;
    }

    /**
     * Append a reply. The caller must be authorized (owner, staff, or the
     * ticket tournament's organizer) — authorization lives in the policy.
     */
    public function reply(User $author, SupportTicket $ticket, string $body): SupportMessage
    {
        if ($ticket->isClosed()) {
            throw new DomainException('This ticket is closed. Reopen it to continue the conversation.');
        }

        $message = $this->appendMessage($ticket, $author, trim($body));

        $staff = $author->isStaff();

        // Reflect who now needs to act.
        $ticket->status = $staff
            ? SupportTicket::STATUS_WAITING_ON_USER
            : SupportTicket::STATUS_WAITING_ON_STAFF;
        $ticket->last_activity_at = now();
        $ticket->save();

        if ($staff) {
            $this->notifications->send(
                $ticket->user,
                Notification::TYPE_SUPPORT_REPLY,
                'Support replied to your ticket',
                'A staff member replied to "'.$ticket->subject.'".',
                self::ticketLink($ticket),
                ['ticket_id' => $ticket->id],
            );
        } elseif ($ticket->assigned_to !== null && $ticket->assignee !== null) {
            $this->notifications->send(
                $ticket->assignee,
                Notification::TYPE_SUPPORT_REPLY,
                'User replied to a ticket',
                $author->name.' replied to "'.$ticket->subject.'".',
                self::ticketLink($ticket),
                ['ticket_id' => $ticket->id],
            );
        }

        $this->live->recordQuietly($ticket->tournament, $author, LiveEvent::TYPE_SUPPORT_MESSAGE, [
            'ticket_id' => $ticket->id,
            'staff' => $staff,
        ]);

        $this->audit->recordQuietly($author, 'support.replied', 'support_ticket', $ticket->id, [
            'metadata' => ['staff' => $staff],
        ]);

        return $message;
    }

    /**
     * Add a staff-only internal note (never visible to the requester).
     */
    public function addInternalNote(User $staff, SupportTicket $ticket, string $body): SupportInternalNote
    {
        if (! $staff->isStaff()) {
            throw new DomainException('Only staff may add internal notes.');
        }

        $note = new SupportInternalNote();
        $note->ticket_id = $ticket->id;
        $note->author_user_id = $staff->id;
        $note->body = trim($body);
        $note->save();

        $ticket->last_activity_at = now();
        $ticket->save();

        // Note content is sensitive — never mirrored into audit/live payloads.
        $this->audit->recordQuietly($staff, 'support.internal_note', 'support_ticket', $ticket->id);

        return $note;
    }

    /**
     * Assign (or unassign) a staff member to a ticket. Staff only.
     */
    public function assign(User $actor, SupportTicket $ticket, ?User $assignee): SupportTicket
    {
        if (! $actor->isStaff()) {
            throw new DomainException('Only staff may assign tickets.');
        }

        if ($assignee !== null && ! $assignee->isStaff()) {
            throw new DomainException('Tickets can only be assigned to staff members.');
        }

        $ticket->assigned_to = $assignee?->id;
        $ticket->last_activity_at = now();

        if ($assignee !== null && $ticket->status === SupportTicket::STATUS_OPEN) {
            $ticket->status = SupportTicket::STATUS_PENDING;
        }

        $ticket->save();

        if ($assignee !== null) {
            $this->notifications->send(
                $assignee,
                Notification::TYPE_SUPPORT_ASSIGNED,
                'Ticket assigned to you',
                'You were assigned "'.$ticket->subject.'".',
                self::ticketLink($ticket),
                ['ticket_id' => $ticket->id],
            );

            $this->notifications->send(
                $ticket->user,
                Notification::TYPE_SUPPORT_ASSIGNED,
                'Your ticket was assigned',
                'A staff member is now handling "'.$ticket->subject.'".',
                self::ticketLink($ticket),
                ['ticket_id' => $ticket->id],
            );
        }

        $this->live->recordQuietly($ticket->tournament, $actor, LiveEvent::TYPE_SUPPORT_ASSIGNED, [
            'ticket_id' => $ticket->id,
            'assignee' => $assignee?->id,
        ]);

        $this->audit->recordQuietly($actor, 'support.assigned', 'support_ticket', $ticket->id, [
            'metadata' => ['assignee_id' => $assignee?->id],
        ]);

        return $ticket;
    }

    /**
     * Change a ticket's status through the validated transition map.
     * Staff only. A note may be appended as a user-visible message.
     */
    public function changeStatus(User $actor, SupportTicket $ticket, string $status, ?string $note = null): SupportTicket
    {
        if (! $actor->isStaff()) {
            throw new DomainException('Only staff may change ticket status.');
        }

        if (! in_array($status, SupportTicket::STATUSES, true)) {
            throw new DomainException('Unknown ticket status.');
        }

        if (! $ticket->canTransitionTo($status)) {
            throw new DomainException("Cannot move this ticket from {$ticket->status} to {$status}.");
        }

        $from = $ticket->status;

        $ticket->status = $status;
        $ticket->last_activity_at = now();

        if ($status === SupportTicket::STATUS_RESOLVED) {
            $ticket->resolved_at = now();
            $ticket->closed_at = null;
        } elseif ($status === SupportTicket::STATUS_CLOSED) {
            $ticket->closed_at = now();
        } elseif ($status === SupportTicket::STATUS_OPEN) {
            $ticket->resolved_at = null;
            $ticket->closed_at = null;
        }

        $ticket->save();

        if (trim((string) $note) !== '') {
            $this->appendMessage($ticket, $actor, trim((string) $note));
        }

        $type = $status === SupportTicket::STATUS_RESOLVED
            ? Notification::TYPE_SUPPORT_RESOLVED
            : Notification::TYPE_SUPPORT_STATUS;

        $this->notifications->send(
            $ticket->user,
            $type,
            'Support ticket update',
            'Your ticket "'.$ticket->subject.'" is now '.$ticket->statusLabel().'.',
            self::ticketLink($ticket),
            ['ticket_id' => $ticket->id, 'status' => $status],
        );

        $this->live->recordQuietly($ticket->tournament, $actor, LiveEvent::TYPE_SUPPORT_STATUS_CHANGED, [
            'ticket_id' => $ticket->id,
            'status' => $status,
        ]);

        $this->audit->recordQuietly($actor, 'support.status_changed', 'support_ticket', $ticket->id, [
            'before' => ['status' => $from],
            'after' => ['status' => $status],
        ]);

        return $ticket;
    }

    /**
     * Close a ticket by its owner (must not already be resolved/closed).
     */
    public function closeByUser(User $user, SupportTicket $ticket): SupportTicket
    {
        if ($ticket->user_id !== $user->id) {
            throw new DomainException('Only the ticket owner may close it.');
        }

        if ($ticket->isClosed() || $ticket->isResolved()) {
            throw new DomainException('This ticket is already closed.');
        }

        $from = $ticket->status;

        $ticket->status = SupportTicket::STATUS_CLOSED;
        $ticket->closed_at = now();
        $ticket->last_activity_at = now();
        $ticket->save();

        if ($ticket->assigned_to !== null && $ticket->assignee !== null) {
            $this->notifications->send(
                $ticket->assignee,
                Notification::TYPE_SUPPORT_STATUS,
                'Ticket closed by user',
                $user->name.' closed "'.$ticket->subject.'".',
                self::ticketLink($ticket),
                ['ticket_id' => $ticket->id],
            );
        }

        $this->live->recordQuietly($ticket->tournament, $user, LiveEvent::TYPE_SUPPORT_STATUS_CHANGED, [
            'ticket_id' => $ticket->id,
            'status' => SupportTicket::STATUS_CLOSED,
        ]);

        $this->audit->recordQuietly($user, 'support.status_changed', 'support_ticket', $ticket->id, [
            'before' => ['status' => $from],
            'after' => ['status' => SupportTicket::STATUS_CLOSED],
        ]);

        return $ticket;
    }

    /**
     * Reopen a resolved/closed ticket (owner or staff, authorized upstream).
     */
    public function reopen(User $actor, SupportTicket $ticket): SupportTicket
    {
        if (! $ticket->isResolved() && ! $ticket->isClosed()) {
            throw new DomainException('Only resolved or closed tickets can be reopened.');
        }

        $wasClosed = $ticket->isClosed();

        $ticket->status = SupportTicket::STATUS_OPEN;
        $ticket->resolved_at = null;
        $ticket->closed_at = null;
        $ticket->last_activity_at = now();

        if ($wasClosed) {
            $ticket->reopened_count = $ticket->reopened_count + 1;
        }

        $ticket->save();

        if ($actor->isStaff()) {
            $this->notifications->send(
                $ticket->user,
                Notification::TYPE_SUPPORT_REOPENED,
                'Ticket reopened',
                'Your ticket "'.$ticket->subject.'" was reopened.',
                self::ticketLink($ticket),
                ['ticket_id' => $ticket->id],
            );
        } elseif ($ticket->assigned_to !== null && $ticket->assignee !== null) {
            $this->notifications->send(
                $ticket->assignee,
                Notification::TYPE_SUPPORT_REOPENED,
                'Ticket reopened',
                $actor->name.' reopened "'.$ticket->subject.'".',
                self::ticketLink($ticket),
                ['ticket_id' => $ticket->id],
            );
        }

        $this->live->recordQuietly($ticket->tournament, $actor, LiveEvent::TYPE_SUPPORT_STATUS_CHANGED, [
            'ticket_id' => $ticket->id,
            'status' => SupportTicket::STATUS_OPEN,
        ]);

        $this->audit->recordQuietly($actor, 'support.reopened', 'support_ticket', $ticket->id);

        return $ticket;
    }

    /**
     * A user's own tickets, newest first.
     */
    public function forUser(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return SupportTicket::query()
            ->with('assignee:id,name')
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity_at')
            ->paginate($perPage);
    }

    /**
     * The staff queue, optionally scoped to a single organizer's tournaments.
     */
    public function queue(array $filters, ?User $scopedOrganizer = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = SupportTicket::query()
            ->with(['user:id,name,username', 'assignee:id,name', 'tournament:id,name'])
            ->orderByDesc('last_activity_at');

        if ($scopedOrganizer !== null) {
            $query->whereHas('tournament', fn ($q) => $q->where('organizer_id', $scopedOrganizer->id));
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (! empty($filters['assigned_to'])) {
            $query->where('assigned_to', (int) $filters['assigned_to']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Messages with id > $afterId (ascending), for lightweight polling on the
     * ticket page. The caller enforces ticket-view authorization.
     *
     * @return Collection<int, SupportMessage>
     */
    public function messagesAfter(SupportTicket $ticket, int $afterId): Collection
    {
        return SupportMessage::query()
            ->where('ticket_id', $ticket->id)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->get();
    }

    /**
     * The latest message id for a ticket (polling cursor).
     */
    public function latestMessageId(SupportTicket $ticket): int
    {
        return (int) (SupportMessage::where('ticket_id', $ticket->id)->max('id') ?? 0);
    }

    /**
     * Build the ticket route link safely (null when unavailable).
     */
    public static function ticketLink(SupportTicket $ticket): ?string
    {
        try {
            return route('support.tickets.show', $ticket);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Append a message row (server-authored; never mass-assigned).
     */
    protected function appendMessage(SupportTicket $ticket, User $author, string $body): SupportMessage
    {
        $message = new SupportMessage();
        $message->ticket_id = $ticket->id;
        $message->user_id = $author->id;
        $message->body = $body;
        $message->save();

        return $message;
    }
}
