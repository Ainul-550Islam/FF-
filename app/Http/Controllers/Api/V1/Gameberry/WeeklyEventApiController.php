<?php

namespace App\Http\Controllers\Api\V1\Gameberry;

use App\Http\Controllers\Controller;
use App\Models\WeeklyEvent;
use App\Models\WeeklyEventParticipant;
use App\Services\Gameberry\WeeklyEventService;
use Illuminate\Http\Request;

class WeeklyEventApiController extends Controller
{
    protected WeeklyEventService $eventService;

    public function __construct(WeeklyEventService $eventService)
    {
        $this->eventService = $eventService;
    }

    public function index(Request $request)
    {
        $active = $this->eventService->getActiveEvents();
        $upcoming = $this->eventService->getUpcomingEvents();
        $userProgress = $request->user() ? $this->eventService->getUserProgress($request->user()->id) : [];

        return response()->json(['success' => true, 'data' => ['active' => $active, 'upcoming' => $upcoming, 'user_progress' => $userProgress]]);
    }

    public function show(Request $request, int $eventId)
    {
        $event = WeeklyEvent::find($eventId);
        if (! $event) {
            return response()->json(['success' => false, 'error' => 'Event not found'], 404);
        }

        $leaderboard = $this->eventService->getLeaderboard($eventId, 100);
        $userParticipant = null;
        if ($request->user()) {
            $userParticipant = WeeklyEventParticipant::where('weekly_event_id', $eventId)->where('user_id', $request->user()->id)->first();
        }

        return response()->json(['success' => true, 'data' => ['event' => $event, 'leaderboard' => $leaderboard, 'user_participant' => $userParticipant]]);
    }

    public function join(Request $request, int $eventId)
    {
        $userId = $request->user()->id;
        try {
            $participant = $this->eventService->joinEvent($userId, $eventId);

            return response()->json(['success' => true, 'data' => $participant, 'message' => 'Joined weekly special event']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function claim(Request $request, int $eventId)
    {
        $userId = $request->user()->id;
        try {
            $rewards = $this->eventService->claimReward($userId, $eventId);

            return response()->json(['success' => true, 'data' => $rewards]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function leaderboard(Request $request, int $eventId)
    {
        $event = WeeklyEvent::find($eventId);
        if (! $event) {
            return response()->json(['success' => false, 'error' => 'Event not found'], 404);
        }
        $leaderboard = $this->eventService->getLeaderboard($eventId, 100);

        return response()->json(['success' => true, 'data' => ['event' => $event, 'leaderboard' => $leaderboard]]);
    }
}
