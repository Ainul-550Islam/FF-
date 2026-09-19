<?php
namespace App\Http\Controllers\Api\V1\Gameberry;
use App\Http\Controllers\Controller;
use App\Services\Gameberry\NotificationService;
use Illuminate\Http\Request;
class NotificationApiController extends Controller
{
    protected NotificationService $notificationService;
    public function __construct(NotificationService $notificationService) { $this->notificationService = $notificationService; }
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $notifications = \App\Models\FriendNotification::with('friend')->where('user_id', $userId)->orderByDesc('created_at')->get();
        return response()->json(['success' => true, 'data' => ['notifications' => $notifications, 'unread_count' => $this->notificationService->getUnreadCount($userId)]]);
    }
    public function markRead(Request $request)
    {
        $userId = $request->user()->id;
        $count = $this->notificationService->markAllRead($userId);
        return response()->json(['success' => true, 'message' => "Marked {$count} as read"]);
    }
}
