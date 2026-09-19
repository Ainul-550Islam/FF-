<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
class NotificationController extends Controller
{
    public function __construct() { $this->middleware(['auth','active']); }
    public function index(Request $request)
    {
        $notifications = $request->user()->notifications()->orderBy('created_at','desc')->paginate(20);
        return view('notifications.index', compact('notifications'));
    }
    public function markRead(Request $request, $notification) { return back()->with('success','Marked read'); }
    public function markAllRead(Request $request) { return back()->with('success','All marked read'); }
}
