<?php

namespace App\Http\Controllers\Gameberry;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\WeeklyEventService;
use Illuminate\Http\Request;

class WeeklyEventController extends Controller
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
        $userProgress = [];
        if ($request->user()) {
            $userProgress = $this->eventService->getUserProgress($request->user()->id);
        }

        return view('gameberry.events.index', compact('active', 'upcoming', 'userProgress'));
    }

    public function show(Request $request, int $eventId)
    {
        $event = \App\Models\WeeklyEvent::findOrFail($eventId);
        $leaderboard = $this->eventService->getLeaderboard($eventId, 100);
        $userParticipant = null;
        if ($request->user()) {
            $userParticipant = \App\Models\WeeklyEventParticipant::where('weekly_event_id', $eventId)->where('user_id', $request->user()->id)->first();
        }

        return view('gameberry.events.show', compact('event', 'leaderboard', 'userParticipant'));
    }

    public function join(Request $request, int $eventId)
    {
        $userId = $request->user()->id;
        try {
            $participant = $this->eventService->joinEvent($userId, $eventId);
            return redirect()->back()->with('success', 'Joined weekly special event!');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function claim(Request $request, int $eventId)
    {
        $userId = $request->user()->id;
        try {
            $rewards = $this->eventService->claimReward($userId, $eventId);
            $msg = "Rewards claimed! ";
            if (!empty($rewards['gold'])) $msg .= "+{$rewards['gold']} gold ";
            if (!empty($rewards['gems'])) $msg .= "+{$rewards['gems']} gems";
            return redirect()->back()->with('success', $msg);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function leaderboard(Request $request, int $eventId)
    {
        $event = \App\Models\WeeklyEvent::findOrFail($eventId);
        $leaderboard = $this->eventService->getLeaderboard($eventId, 100);
        return view('gameberry.events.leaderboard', compact('event', 'leaderboard'));
    }
}
