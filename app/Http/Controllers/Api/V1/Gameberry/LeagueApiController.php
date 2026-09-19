<?php

namespace App\Http\Controllers\Api\V1\Gameberry;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\LeagueService;
use App\Models\League;
use Illuminate\Http\Request;

class LeagueApiController extends Controller
{
    protected LeagueService $leagueService;

    public function __construct(LeagueService $leagueService)
    {
        $this->leagueService = $leagueService;
    }

    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $userLeague = $this->leagueService->getUserLeague($userId);
        $leagues = League::ordered()->get();
        $progression = $this->leagueService->getLeagueProgression();

        return response()->json([
            'success' => true,
            'data' => [
                'user_league' => $userLeague?->load('league'),
                'leagues' => $leagues,
                'progression' => $progression,
                'current_season' => $this->leagueService->currentSeason(),
            ]
        ]);
    }

    public function leaderboard(Request $request, string $slug)
    {
        $season = $request->get('season');
        try {
            $leaderboard = $this->leagueService->getLeaderboard($slug, $season ? (int)$season : null, 100);
            return response()->json(['success' => true, 'data' => $leaderboard]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 404);
        }
    }

    public function show(Request $request, string $slug)
    {
        $league = League::where('slug', $slug)->first();
        if (!$league) {
            return response()->json(['success' => false, 'error' => 'League not found'], 404);
        }

        $userId = $request->user()->id;
        $userLevel = \App\Models\Level::where('user_id', $userId)->first();
        $levelNumber = $userLevel?->level ?? 1;

        if (!$this->leagueService->canAccessLeague($levelNumber, $slug)) {
            return response()->json(['success' => false, 'error' => "Need Level 4 to access Bronze - you are Level {$levelNumber}", 'required_level' => 4], 403);
        }

        $leaderboard = $this->leagueService->getLeaderboard($slug, null, 100);

        return response()->json([
            'success' => true,
            'data' => [
                'league' => $league,
                'leaderboard' => $leaderboard,
                'user_level' => $levelNumber,
            ]
        ]);
    }

    public function history(Request $request)
    {
        $userId = $request->user()->id;
        $history = \App\Models\LeagueHistory::with(['league'])->where('user_id', $userId)->orderByDesc('season')->get();
        $badges = \App\Models\TitanBadge::with('league')->where('user_id', $userId)->orderByDesc('created_at')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'history' => $history,
                'titan_badges' => $badges,
            ]
        ]);
    }

    public function addTrophies(Request $request)
    {
        $request->validate([
            'trophies' => 'required|integer|min:1|max:1000',
            'is_win' => 'boolean',
        ]);

        $userId = $request->user()->id;
        try {
            $userLeague = $this->leagueService->addTrophies($userId, $request->trophies, $request->boolean('is_win', true));
            return response()->json(['success' => true, 'data' => $userLeague->load('league')]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }
}
