<?php

namespace App\Http\Controllers\Gameberry;

use App\Http\Controllers\Controller;
use App\Models\FriendNotification;
use App\Services\Gameberry\NotificationService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $notifications = FriendNotification::with('friend')->where('user_id', $userId)->orderByDesc('created_at')->get();
        $unreadCount = $this->notificationService->getUnreadCount($userId);

        return view('gameberry.social.notifications', compact('notifications', 'unreadCount'));
    }

    public function markRead(Request $request)
    {
        $userId = $request->user()->id;
        $count = $this->notificationService->markAllRead($userId);

        return redirect()->back()->with('success', "Marked {$count} notifications as read");
    }
}
