<?php

namespace App\Http\Controllers\Gameberry;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\SocialService;
use App\Models\GameBuddy;
use Illuminate\Http\Request;

class SocialController extends Controller
{
    protected SocialService $socialService;

    public function __construct(SocialService $socialService)
    {
        $this->socialService = $socialService;
    }

    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $buddies = $this->socialService->getBuddies($userId);
        $pending = $this->socialService->getPendingRequests($userId);
        $onlineBuddies = $this->socialService->getOnlineBuddies($userId);
        $stats = $this->socialService->getSocialStats($userId);
        $notifications = $this->socialService->getFriendNotifications($userId, 20);
        $onlineStatus = \App\Models\UserOnlineStatus::firstOrCreate(
            ['user_id' => $userId],
            ['is_online' => false, 'hide_online_status' => false, 'notify_friends_online' => true, 'is_in_auto_mode' => false]
        );

        return view('gameberry.social.index', compact('buddies', 'pending', 'onlineBuddies', 'stats', 'notifications', 'onlineStatus'));
    }

    public function addBuddy(Request $request)
    {
        $request->validate([
            'buddy_id' => 'required|exists:users,id',
        ]);

        $userId = $request->user()->id;
        try {
            $buddy = $this->socialService->addBuddy($userId, $request->buddy_id);
            return redirect()->back()->with('success', 'Buddy request sent - max 25');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function acceptBuddy(Request $request, int $buddyId)
    {
        $userId = $request->user()->id;
        try {
            // Find pending request where buddy_id is current user and user_id is buddyId
            $pending = GameBuddy::where('user_id', $buddyId)->where('buddy_id', $userId)->where('status', 'pending')->first();
            if (!$pending) {
                throw new \Exception('Request not found');
            }
            $this->socialService->acceptBuddy($userId, $pending->id);
            return redirect()->back()->with('success', 'Buddy request accepted');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function removeBuddy(Request $request, int $buddyId)
    {
        $userId = $request->user()->id;
        try {
            $this->socialService->removeBuddy($userId, $buddyId);
            return redirect()->back()->with('success', 'Buddy removed');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function onlineStatus(Request $request)
    {
        $request->validate([
            'is_online' => 'boolean',
            'game' => 'nullable|string',
            'table_code' => 'nullable|string',
        ]);

        $userId = $request->user()->id;
        $status = $this->socialService->updateOnlineStatus($userId, $request->boolean('is_online', true), $request->game, $request->table_code);
        return redirect()->back()->with('success', $status->is_online ? 'Online' : 'Offline');
    }

    public function hideOnlineStatus(Request $request)
    {
        $request->validate([
            'hide' => 'required|boolean',
        ]);

        $userId = $request->user()->id;
        $status = $this->socialService->setHideOnlineStatus($userId, $request->boolean('hide'));
        return redirect()->back()->with('success', $status->hide_online_status ? 'Online status hidden' : 'Online status visible');
    }

    public function notifyFriends(Request $request)
    {
        $request->validate([
            'notify' => 'required|boolean',
        ]);

        $userId = $request->user()->id;
        $status = $this->socialService->setNotifyFriendsOnline($userId, $request->boolean('notify'));
        return redirect()->back()->with('success', $status->notify_friends_online ? 'Friends will be notified when you come online' : 'Friend notifications disabled');
    }

    public function autoMode(Request $request)
    {
        $request->validate([
            'auto_on' => 'required|boolean',
            'reason' => 'in:disconnect,afk,manual',
        ]);

        $userId = $request->user()->id;
        $status = $this->socialService->setAutoMode($userId, $request->boolean('auto_on'), $request->get('reason', 'disconnect'));
        return redirect()->back()->with('success', $status->is_in_auto_mode ? 'Auto mode ON - will auto-play on disconnect' : 'Auto mode OFF');
    }

    public function challenge(Request $request)
    {
        $request->validate([
            'buddy_id' => 'required|exists:users,id',
            'bet_amount' => 'integer|min:100|max:100000',
        ]);

        $userId = $request->user()->id;
        try {
            $challenge = $this->socialService->challengeBuddy($userId, $request->buddy_id, $request->get('bet_amount', 100));
            return redirect()->back()->with('success', 'Challenge sent! Challenge button pressed');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function challenges(Request $request)
    {
        $userId = $request->user()->id;
        $sent = \App\Models\Challenge::with(['challenged'])->where('challenger_id', $userId)->orderByDesc('created_at')->get();
        $received = \App\Models\Challenge::with(['challenger'])->where('challenged_id', $userId)->orderByDesc('created_at')->get();
        return view('gameberry.social.challenges', compact('sent', 'received'));
    }

    public function acceptChallenge(Request $request, int $challengeId)
    {
        $userId = $request->user()->id;
        try {
            $challenge = \App\Models\Challenge::where('id', $challengeId)->where('challenged_id', $userId)->firstOrFail();
            $challenge->accept();
            return redirect()->back()->with('success', 'Challenge accepted!');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function denyChallenge(Request $request, int $challengeId)
    {
        $userId = $request->user()->id;
        try {
            $challenge = \App\Models\Challenge::where('id', $challengeId)->where('challenged_id', $userId)->firstOrFail();
            $challenge->deny();
            return redirect()->back()->with('success', 'Challenge denied');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}
