<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\User;
use App\Services\SupportTicketService;
use App\Support\CsvExport;
use DomainException;
use Illuminate\Http\Request;

/**
 * Staff support queue (Phase 13).
 *
 * Admins and moderators work the global queue; organizers are scoped to the
 * tickets of their own tournaments. Assignment, status changes and internal
 * notes are platform-staff only. Internal notes are never shown to the
 * ticket requester.
 */
class AdminSupportController extends Controller
{
    public function __construct(
        protected SupportTicketService $tickets,
    ) {}

    /**
     * The staff queue, filterable and scoped for organizers.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', SupportTicket::class);

        $user = $request->user();

        // Organizers (who are not platform staff) are scoped to their own
        // tournaments' tickets.
        $scopedOrganizer = ($user->isOrganizer() && ! $user->isStaff()) ? $user : null;

        $filters = $this->filters($request);

        $tickets = $this->tickets->queue($filters, $scopedOrganizer, 20);

        $staff = User::whereIn('role', ['admin', 'moderator'])
            ->orderBy('name')
            ->get(['id', 'name', 'role']);

        return view('admin.support', compact('tickets', 'filters', 'staff'));
    }

    /**
     * Ticket detail with the full conversation and internal notes.
     */
    public function show(SupportTicket $ticket)
    {
        $this->authorize('view', $ticket);

        $messages = $ticket->messages()->with('author:id,name,role')->get();
        $internalNotes = $ticket->internalNotes()->with('author:id,name')->get();
        $latestId = $this->tickets->latestMessageId($ticket);

        $staff = User::whereIn('role', ['admin', 'moderator'])
            ->orderBy('name')
            ->get(['id', 'name', 'role']);

        return view('admin.support_ticket', compact('ticket', 'messages', 'internalNotes', 'latestId', 'staff'));
    }

    /**
     * Assign (or unassign) the ticket to a staff member.
     */
    public function assign(Request $request, SupportTicket $ticket)
    {
        $this->authorize('assign', $ticket);

        $data = $request->validate([
            'assignee_id' => 'nullable|integer|exists:users,id',
        ]);

        $assignee = ! empty($data['assignee_id'])
            ? User::findOrFail((int) $data['assignee_id'])
            : null;

        try {
            $this->tickets->assign($request->user(), $ticket, $assignee);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Ticket assignment updated.');
    }

    /**
     * Change status through the validated transition map.
     */
    public function status(Request $request, SupportTicket $ticket)
    {
        $this->authorize('changeStatus', $ticket);

        $data = $request->validate([
            'status' => 'required|in:'.implode(',', SupportTicket::STATUSES),
            'note' => 'nullable|string|max:10000',
        ]);

        try {
            $this->tickets->changeStatus($request->user(), $ticket, $data['status'], $data['note'] ?? null);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Ticket status updated.');
    }

    /**
     * Add a staff-only internal note.
     */
    public function internalNote(Request $request, SupportTicket $ticket)
    {
        $this->authorize('addInternalNote', $ticket);

        $data = $request->validate([
            'body' => 'required|string|max:10000',
        ]);

        try {
            $this->tickets->addInternalNote($request->user(), $ticket, $data['body']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Internal note added.');
    }

    /**
     * Reply as staff.
     */
    public function reply(Request $request, SupportTicket $ticket)
    {
        $this->authorize('reply', $ticket);

        $data = $request->validate([
            'body' => 'required|string|max:10000',
        ]);

        try {
            $this->tickets->reply($request->user(), $ticket, $data['body']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Reply sent.');
    }

    /**
     * CSV export of the (scoped) support queue.
     */
    public function export(Request $request)
    {
        $this->authorize('export', SupportTicket::class);

        $user = $request->user();

        $scopedOrganizer = ($user->isOrganizer() && ! $user->isStaff()) ? $user : null;

        $filters = $this->filters($request);

        $headers = [
            'id', 'status', 'category', 'priority', 'subject',
            'requester', 'assignee', 'tournament', 'created_at', 'resolved_at',
            'closed_at', 'reopened_count',
        ];

        return CsvExport::download('support-tickets', $headers, function () use ($filters, $scopedOrganizer) {
            $query = SupportTicket::query()
                ->with(['user:id,name,username', 'assignee:id,name', 'tournament:id,name'])
                ->orderByDesc('id');

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

            foreach ($query->cursor() as $ticket) {
                yield [
                    $ticket->id,
                    $ticket->status,
                    $ticket->category,
                    $ticket->priority,
                    $ticket->subject,
                    $ticket->user?->name,
                    $ticket->assignee?->name,
                    $ticket->tournament?->name,
                    optional($ticket->created_at)->toIso8601String(),
                    optional($ticket->resolved_at)->toIso8601String(),
                    optional($ticket->closed_at)->toIso8601String(),
                    $ticket->reopened_count,
                ];
            }
        });
    }

    /**
     * Whitelisted queue filters.
     *
     * @return array<string, mixed>
     */
    protected function filters(Request $request): array
    {
        return [
            'status' => $request->query('status'),
            'priority' => $request->query('priority'),
            'category' => $request->query('category'),
            'assigned_to' => $request->query('assigned_to'),
        ];
    }
}
