<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        try {
            $notifications = $user->notifications()->orderBy('created_at','desc')->paginate(20);
            return response()->json(['data'=>$notifications->items(),'meta'=>['total'=>$notifications->total(),'unread'=>$user->unreadNotifications()->count()]]);
        } catch (\Throwable $e) {
            return response()->json(['data'=>[],'meta'=>['total'=>0,'unread'=>0]]);
        }
    }

    public function unreadCount(Request $request)
    {
        try {
            $count = $request->user()->unreadNotifications()->count();
        } catch (\Throwable $e) {
            $count = 0;
        }
        return response()->json(['unread_count'=>$count]);
    }

    public function markRead(Request $request, $notification)
    {
        try {
            $n = $request->user()->notifications()->where('id',$notification)->first();
            if ($n) $n->markAsRead();
        } catch (\Throwable $e) {}
        return response()->json(['message'=>'Marked as read']);
    }

    public function markAllRead(Request $request)
    {
        try { $request->user()->unreadNotifications->markAsRead(); } catch (\Throwable $e) {}
        return response()->json(['message'=>'All marked as read']);
    }
}
