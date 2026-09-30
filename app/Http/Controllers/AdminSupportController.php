<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\User;
use App\Services\SupportTicketService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminSupportController extends Controller
{
    public function index(Request $request, SupportTicketService $service): View
    {
        $filters = $request->all();
        $tickets = $service->queue($filters);
        $staff = User::whereIn('role', ['admin', 'moderator'])->orderBy('name')->get();

        return view('admin.support', compact('tickets', 'filters', 'staff'));
    }

    public function userIndex(Request $request, SupportTicketService $service): View
    {
        $tickets = $service->forUser($request->user());

        return view('support.index', compact('tickets'));
    }

    public function create(): View
    {
        $categories = SupportTicket::CATEGORIES;
        $priorities = SupportTicket::PRIORITIES;

        return view('support.create', compact('categories', 'priorities'));
    }

    public function store(Request $request, SupportTicketService $service): RedirectResponse
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'category' => 'required|string',
            'priority' => 'nullable|string',
            'message' => 'required|string|max:5000',
        ]);

        $ticket = $service->create($request->user(), $validated);

        return redirect()->route('support.tickets.show', $ticket)->with('success', 'Ticket created.');
    }

    public function show(SupportTicket $ticket): View
    {
        $ticket->load(['user', 'assignee']);
        $messages = $ticket->messages()->with('author')->orderBy('id')->get();
        $internalNotes = $ticket->internalNotes()->with('author')->orderBy('id')->get();
        $staff = User::whereIn('role', ['admin', 'moderator'])->orderBy('name')->get();

        return view('admin.support_ticket', compact('ticket', 'messages', 'internalNotes', 'staff'));
    }

    public function userShow(Request $request, SupportTicket $ticket, SupportTicketService $service): View
    {
        $this->authorizeTicketAccess($request, $ticket);

        $ticket->load(['tournament', 'assignee']);
        $messages = $ticket->messages()->with('author')->orderBy('id')->get();
        $latestId = $service->latestMessageId($ticket);

        return view('support.show', compact('ticket', 'messages', 'latestId'));
    }

    public function messages(Request $request, SupportTicket $ticket, SupportTicketService $service): JsonResponse
    {
        $this->authorizeTicketAccess($request, $ticket);

        $afterId = (int) ($request->query('after_id') ?? $request->query('after') ?? 0);
        $messages = $service->messagesAfter($ticket, $afterId);

        return response()->json([
            'messages' => $messages,
            'status' => $ticket->status,
        ]);
    }

    public function reply(Request $request, SupportTicket $ticket, SupportTicketService $service): RedirectResponse
    {
        $request->validate(['body' => 'required|string|max:5000']);

        $service->reply($request->user(), $ticket, (string) $request->input('body'));

        return back()->with('success', 'Reply posted.');
    }

    public function userReply(Request $request, SupportTicket $ticket, SupportTicketService $service): RedirectResponse
    {
        $this->authorizeTicketAccess($request, $ticket);

        $request->validate(['body' => 'required|string|max:5000']);

        $service->reply($request->user(), $ticket, (string) $request->input('body'));

        return back()->with('success', 'Reply posted.');
    }

    public function close(Request $request, SupportTicket $ticket, SupportTicketService $service): RedirectResponse
    {
        $this->authorizeTicketAccess($request, $ticket);

        $service->closeByUser($request->user(), $ticket);

        return back()->with('success', 'Ticket closed.');
    }

    public function reopen(Request $request, SupportTicket $ticket, SupportTicketService $service): RedirectResponse
    {
        $this->authorizeTicketAccess($request, $ticket);

        $service->reopen($request->user(), $ticket);

        return back()->with('success', 'Ticket reopened.');
    }

    public function internalNote(Request $request, SupportTicket $ticket, SupportTicketService $service): RedirectResponse
    {
        $request->validate(['body' => 'required|string|max:5000']);

        $service->addInternalNote($request->user(), $ticket, (string) $request->input('body'));

        return back()->with('success', 'Internal note added.');
    }

    public function assign(Request $request, SupportTicket $ticket, SupportTicketService $service): RedirectResponse
    {
        $assigneeId = $request->input('assignee_id');
        $assignee = $assigneeId ? User::findOrFail($assigneeId) : null;

        try {
            $service->assign($request->user(), $ticket, $assignee);

            return back()->with('success', 'Ticket assigned.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function status(Request $request, SupportTicket $ticket, SupportTicketService $service): RedirectResponse
    {
        $request->validate([
            'status' => 'required|string|in:'.implode(',', SupportTicket::STATUSES),
            'note' => 'nullable|string|max:1000',
        ]);

        try {
            $service->changeStatus(
                $request->user(),
                $ticket,
                (string) $request->input('status'),
                $request->input('note'),
            );

            return back()->with('success', 'Ticket status updated.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function export(Request $request): StreamedResponse
    {
        $tickets = SupportTicket::query()->with(['user', 'assignee'])->orderBy('id')->get();

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="support-tickets-export.csv"',
        ];

        return response()->stream(function () use ($tickets) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Subject', 'Status', 'Priority', 'User', 'Assignee', 'Created At']);

            foreach ($tickets as $t) {
                fputcsv($handle, [
                    $t->id,
                    $t->subject,
                    $t->status,
                    $t->priority,
                    $t->user?->name ?? 'Unknown',
                    $t->assignee?->name ?? 'Unassigned',
                    $t->created_at?->toISOString(),
                ]);
            }

            fclose($handle);
        }, 200, $headers);
    }

    protected function authorizeTicketAccess(Request $request, SupportTicket $ticket): void
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        if ($user->isStaff() || $ticket->user_id === $user->id) {
            return;
        }

        if ($ticket->tournament_id) {
            $ticket->loadMissing('tournament');
            if ($ticket->tournament && $ticket->tournament->organizer_id === $user->id) {
                return;
            }
        }

        abort(403);
    }
}
