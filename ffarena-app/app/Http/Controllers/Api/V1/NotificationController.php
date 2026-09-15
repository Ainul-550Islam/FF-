<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\NotificationResource;
use App\Models\Notification;
use App\Services\NotificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 15 — the caller's own notifications (ownership enforced by querying
 * through the user relationship).
 */
class NotificationController extends Controller
{
    public function __construct(
        protected NotificationService $notifications,
    ) {
    }

    /**
     * GET /api/v1/me/notifications
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->query('per_page', 20)));

        $notifications = $this->notifications->forUser($request->user(), $perPage);

        return ApiResponse::data(
            NotificationResource::collection($notifications),
            [
                'unread_count' => $this->notifications->unreadCount($request->user()),
                'pagination' => [
                    'current_page' => $notifications->currentPage(),
                    'last_page' => $notifications->lastPage(),
                    'per_page' => $notifications->perPage(),
                    'total' => $notifications->total(),
                ],
            ]
        );
    }

    /**
     * GET /api/v1/me/notifications/unread-count
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::data([
            'unread_count' => $this->notifications->unreadCount($request->user()),
        ]);
    }

    /**
     * POST /api/v1/me/notifications/{notification}/read
     */
    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        if ($notification->user_id !== $request->user()->id) {
            return ApiResponse::error('not_found', 'Notification not found.', [], 404);
        }

        $this->notifications->markRead($notification, $request->user());

        return ApiResponse::data(new NotificationResource($notification->fresh()));
    }

    /**
     * POST /api/v1/me/notifications/read-all
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $count = $this->notifications->markAllRead($request->user());

        return ApiResponse::data(['marked_read' => $count]);
    }
}
