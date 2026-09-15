<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Services\SupportTicketService;
use DomainException;
use Illuminate\Http\Request;

/**
 * User-facing support tickets (Phase 13).
 *
 * Every action is scoped to the authenticated user's own tickets via
 * SupportTicketPolicy — there is no cross-user access and no route relies on
 * model binding alone.
 */
class SupportController extends Controller
{
    public function __construct(
        protected SupportTicketService $tickets,
    ) {
    }

    /**
     * The authenticated user's tickets.
     */
    public function index()
    {
        $tickets = $this->tickets->forUser(auth()->user(), 15);

        return view('support.index', compact('tickets'));
    }

    /**
     * New-ticket form.
     */
    public function create()
    {
        return view('support.create', [
            'categories' => SupportTicket::CATEGORIES,
            'priorities' => SupportTicket::PRIORITIES,
        ]);
    }

    /**
     * Store a new ticket.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'subject' => 'required|string|max:255',
            'category' => 'required|in:' . implode(',', SupportTicket::CATEGORIES),
            'priority' => 'nullable|in:' . implode(',', SupportTicket::PRIORITIES),
            'message' => 'required|string|max:10000',
        ]);

        $ticket = $this->tickets->create($request->user(), $data);

        return redirect()
            ->route('support.tickets.show', $ticket)
            ->with('success', 'Support ticket created.');
    }

    /**
     * Show one of the user's tickets (owner or authorized staff/organizer).
     * Internal notes are never loaded for non-staff viewers.
     */
    public function show(SupportTicket $ticket)
    {
        $this->authorize('view', $ticket);

        $user = auth()->user();

        $messages = $ticket->messages()->with('author:id,name,role')->get();
        $internalNotes = $user->isStaff()
            ? $ticket->internalNotes()->with('author:id,name')->get()
            : collect();
        $latestId = $this->tickets->latestMessageId($ticket);
        $staffViewer = $user->isStaff();

        return view('support.show', compact('ticket', 'messages', 'internalNotes', 'latestId', 'staffViewer'));
    }

    /**
     * Reply to a ticket (owner or staff/organizer).
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
     * Close a ticket by its owner.
     */
    public function close(SupportTicket $ticket)
    {
        $this->authorize('close', $ticket);

        try {
            $this->tickets->closeByUser(auth()->user(), $ticket);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Ticket closed.');
    }

    /**
     * Reopen a resolved/closed ticket.
     */
    public function reopen(SupportTicket $ticket)
    {
        $this->authorize('reopen', $ticket);

        try {
            $this->tickets->reopen(auth()->user(), $ticket);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Ticket reopened.');
    }

    /**
     * Lightweight JSON poll of new messages after a cursor (Phase 12 style).
     * Authorization is identical to viewing the ticket; internal notes are
     * never included.
     */
    public function messages(SupportTicket $ticket, Request $request)
    {
        $this->authorize('view', $ticket);

        $after = (int) $request->query('after', 0);

        $messages = $this->tickets->messagesAfter($ticket, $after);

        return response()->json([
            'status' => $ticket->fresh()->status,
            'latest' => $this->tickets->latestMessageId($ticket),
            'messages' => $messages->map(fn ($m) => [
                'id' => $m->id,
                'body' => $m->body,
                'author' => $m->author?->name ?? 'System',
                'staff' => (bool) ($m->author?->isStaff() ?? false),
                'at' => optional($m->created_at)->toIso8601String(),
            ])->values(),
        ]);
    }
}
