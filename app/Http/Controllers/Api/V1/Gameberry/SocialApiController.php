<?php

namespace App\Http\Controllers\Api\V1\Gameberry;

use App\Http\Controllers\Controller;
use App\Models\Challenge;
use App\Models\GameBuddy;
use App\Services\Gameberry\SocialService;
use Illuminate\Http\Request;

class SocialApiController extends Controller
{
    protected SocialService $socialService;

    public function __construct(SocialService $socialService)
    {
        $this->socialService = $socialService;
    }

    public function buddies(Request $request)
    {
        $userId = $request->user()->id;
        $buddies = $this->socialService->getBuddies($userId);
        $online = $this->socialService->getOnlineBuddies($userId);
        $stats = $this->socialService->getSocialStats($userId);

        return response()->json(['success' => true, 'data' => ['buddies' => $buddies, 'online_buddies' => $online, 'stats' => $stats]]);
    }

    public function addBuddy(Request $request)
    {
        $request->validate(['buddy_id' => 'required|exists:users,id']);
        $userId = $request->user()->id;
        try {
            $buddy = $this->socialService->addBuddy($userId, $request->buddy_id);

            return response()->json(['success' => true, 'data' => $buddy, 'message' => 'Buddy request sent max 25'], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function acceptBuddy(Request $request, int $buddyId)
    {
        $userId = $request->user()->id;
        try {
            $pending = GameBuddy::where('user_id', $buddyId)->where('buddy_id', $userId)->where('status', 'pending')->first();
            if (! $pending) {
                throw new \Exception('Request not found');
            }
            $buddy = $this->socialService->acceptBuddy($userId, $pending->id);

            return response()->json(['success' => true, 'data' => $buddy]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function removeBuddy(Request $request, int $buddyId)
    {
        $userId = $request->user()->id;
        try {
            $this->socialService->removeBuddy($userId, $buddyId);

            return response()->json(['success' => true, 'message' => 'Removed']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function onlineStatus(Request $request)
    {
        $request->validate(['is_online' => 'boolean', 'game' => 'nullable|string', 'table_code' => 'nullable|string']);
        $userId = $request->user()->id;
        $status = $this->socialService->updateOnlineStatus($userId, $request->boolean('is_online', true), $request->game, $request->table_code);

        return response()->json(['success' => true, 'data' => $status]);
    }

    public function hideOnlineStatus(Request $request)
    {
        $request->validate(['hide' => 'required|boolean']);
        $userId = $request->user()->id;
        $status = $this->socialService->setHideOnlineStatus($userId, $request->boolean('hide'));

        return response()->json(['success' => true, 'data' => $status]);
    }

    public function notifyFriends(Request $request)
    {
        $request->validate(['notify' => 'required|boolean']);
        $userId = $request->user()->id;
        $status = $this->socialService->setNotifyFriendsOnline($userId, $request->boolean('notify'));

        return response()->json(['success' => true, 'data' => $status]);
    }

    public function autoMode(Request $request)
    {
        $request->validate(['auto_on' => 'required|boolean', 'reason' => 'in:disconnect,afk,manual']);
        $userId = $request->user()->id;
        $status = $this->socialService->setAutoMode($userId, $request->boolean('auto_on'), $request->get('reason', 'disconnect'));

        return response()->json(['success' => true, 'data' => $status, 'message' => $status->is_in_auto_mode ? 'Auto mode ON' : 'Auto mode OFF']);
    }

    public function challenge(Request $request)
    {
        $request->validate(['buddy_id' => 'required|exists:users,id', 'bet_amount' => 'integer|min:100|max:100000']);
        $userId = $request->user()->id;
        try {
            $challenge = $this->socialService->challengeBuddy($userId, $request->buddy_id, $request->get('bet_amount', 100));

            return response()->json(['success' => true, 'data' => $challenge], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function challenges(Request $request)
    {
        $userId = $request->user()->id;
        $sent = Challenge::with(['challenged'])->where('challenger_id', $userId)->orderByDesc('created_at')->get();
        $received = Challenge::with(['challenger'])->where('challenged_id', $userId)->orderByDesc('created_at')->get();

        return response()->json(['success' => true, 'data' => ['sent' => $sent, 'received' => $received]]);
    }

    public function acceptChallenge(Request $request, int $challengeId)
    {
        $userId = $request->user()->id;
        try {
            $challenge = Challenge::where('id', $challengeId)->where('challenged_id', $userId)->firstOrFail();
            $challenge->accept();

            return response()->json(['success' => true, 'data' => $challenge]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function notifications(Request $request)
    {
        $userId = $request->user()->id;
        $notifications = $this->socialService->getFriendNotifications($userId, 50);

        return response()->json(['success' => true, 'data' => $notifications]);
    }

    public function stats(Request $request)
    {
        $userId = $request->user()->id;
        $stats = $this->socialService->getSocialStats($userId);

        return response()->json(['success' => true, 'data' => $stats]);
    }
}
