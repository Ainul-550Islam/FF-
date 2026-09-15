<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\NotificationService;
use DomainException;
use Illuminate\Http\Request;

/**
 * The authenticated user's notification inbox (Phase 11).
 *
 * Listing, read-state changes and the unread badge all operate strictly on
 * the authenticated user's own notifications (enforced by the policy and
 * again by NotificationService).
 */
class NotificationController extends Controller
{
    public function __construct(
        protected NotificationService $notifications,
    ) {
    }

    public function index()
    {
        $this->authorize('viewAny', Notification::class);

        $items = $this->notifications->forUser(
            auth()->user(),
            (int) config('notifications.per_page', 20)
        );

        return view('notifications.index', compact('items'));
    }

    public function markRead(Notification $notification)
    {
        $this->authorize('markRead', $notification);

        try {
            $this->notifications->markRead($notification, auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Notification marked as read.');
    }

    public function markAllRead()
    {
        $this->authorize('markAllRead', Notification::class);

        $this->notifications->markAllRead(auth()->user());

        return back()->with('success', 'All notifications marked as read.');
    }
}
