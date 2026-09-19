<?php

namespace App\Http\Controllers\Gameberry;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\LeagueService;
use App\Models\League;
use Illuminate\Http\Request;

class LeagueController extends Controller
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
        $currentSeason = $this->leagueService->currentSeason();

        // Check level access
        $userLevel = \App\Models\Level::where('user_id', $userId)->first();
        $levelNumber = $userLevel?->level ?? 1;

        return view('gameberry.league.index', compact('userLeague', 'leagues', 'progression', 'currentSeason', 'levelNumber'));
    }

    public function show(Request $request, string $slug)
    {
        $league = League::where('slug', $slug)->firstOrFail();
        $userId = $request->user()->id;
        $userLevel = \App\Models\Level::where('user_id', $userId)->first();
        $levelNumber = $userLevel?->level ?? 1;

        if (!$this->leagueService->canAccessLeague($levelNumber, $slug)) {
            return redirect()->route('gameberry.league.index')->with('error', "Need Level {$this->getRequiredLevel($slug)} to access {$league->name} - currently Level {$levelNumber}. Bronze unlocks at Level 4");
        }

        $leaderboard = $this->leagueService->getLeaderboard($slug, null, 100);
        $userLeague = $this->leagueService->getUserLeague($userId);

        return view('gameberry.league.show', compact('league', 'leaderboard', 'userLeague', 'levelNumber'));
    }

    public function leaderboard(Request $request, string $slug)
    {
        $season = $request->get('season');
        $leaderboard = $this->leagueService->getLeaderboard($slug, $season ? (int)$season : null, 100);
        $league = League::where('slug', $slug)->firstOrFail();

        return view('gameberry.league.leaderboard', compact('leaderboard', 'league'));
    }

    public function history(Request $request)
    {
        $userId = $request->user()->id;
        $history = \App\Models\LeagueHistory::with(['league', 'promotionLeague', 'demotionLeague'])
            ->where('user_id', $userId)
            ->orderByDesc('season')
            ->get();

        $titanBadges = \App\Models\TitanBadge::with('league')->where('user_id', $userId)->orderByDesc('created_at')->get();

        return view('gameberry.league.history', compact('history', 'titanBadges'));
    }

    public function badges(Request $request)
    {
        $userId = $request->user()->id;
        $badges = \App\Models\TitanBadge::with('league')->where('user_id', $userId)->orderByDesc('year')->orderByDesc('week')->get();
        return view('gameberry.league.badges', compact('badges'));
    }

    private function getRequiredLevel(string $slug): int
    {
        return match ($slug) {
            'bronze' => 4,
            'silver' => 4,
            'gold' => 6,
            'platinum' => 8,
            'diamond' => 10,
            'titan' => 12,
            default => 4,
        };
    }
}
