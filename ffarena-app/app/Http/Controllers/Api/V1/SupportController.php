<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SupportMessageResource;
use App\Http\Resources\Api\V1\SupportTicketResource;
use App\Models\SupportTicket;
use App\Services\SupportTicketService;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 15 — support tickets. A user only ever sees their own tickets;
 * staff can additionally see all tickets (authorization via policy/service).
 */
class SupportController extends Controller
{
    public function __construct(
        protected SupportTicketService $tickets,
    ) {
    }

    /**
     * GET /api/v1/me/support
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->query('per_page', 15)));

        $tickets = $this->tickets->forUser($request->user(), $perPage);

        return ApiResponse::data(
            SupportTicketResource::collection($tickets),
            [
                'pagination' => [
                    'current_page' => $tickets->currentPage(),
                    'last_page' => $tickets->lastPage(),
                    'per_page' => $tickets->perPage(),
                    'total' => $tickets->total(),
                ],
            ]
        );
    }

    /**
     * POST /api/v1/me/support
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => 'required|string|max:255',
            'category' => 'required|string|max:30',
            'priority' => 'nullable|string|max:12',
            'message' => 'required|string|max:5000',
        ]);

        try {
            $ticket = $this->tickets->create($request->user(), $data);
        } catch (DomainException $e) {
            return ApiResponse::error('ticket_refused', $e->getMessage(), [], 422);
        }

        return ApiResponse::created(new SupportTicketResource($ticket));
    }

    /**
     * GET /api/v1/me/support/{ticket}
     */
    public function show(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);

        return ApiResponse::data(new SupportTicketResource($ticket));
    }

    /**
     * GET /api/v1/me/support/{ticket}/messages
     */
    public function messages(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);

        $afterId = (int) $request->query('after', 0);

        $messages = $this->tickets->messagesAfter($ticket, $afterId)->load('author');

        return ApiResponse::data([
            'messages' => SupportMessageResource::collection($messages),
            'latest_message_id' => $this->tickets->latestMessageId($ticket),
        ]);
    }

    /**
     * POST /api/v1/me/support/{ticket}/messages
     */
    public function reply(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorize('reply', $ticket);

        $data = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        try {
            $message = $this->tickets->reply($request->user(), $ticket, $data['body']);
        } catch (DomainException $e) {
            return ApiResponse::error('reply_refused', $e->getMessage(), [], 422);
        }

        return ApiResponse::created(new SupportMessageResource($message->load('author')));
    }
}
