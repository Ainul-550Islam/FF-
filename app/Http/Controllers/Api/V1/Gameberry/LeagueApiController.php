<?php

namespace App\Http\Controllers\Api\V1\Gameberry;

use App\Http\Controllers\Controller;
use App\Models\League;
use App\Models\LeagueHistory;
use App\Models\Level;
use App\Models\TitanBadge;
use App\Services\AuditLogService;
use App\Services\Gameberry\LeagueService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * League API — standings, progression and history.
 *
 * AUDIT FIX (2026-10-08, GAPS-03) — the trophies surface:
 *
 * `POST /v1/gameberry/league/trophies` previously let ANY authenticated
 * player add up to 1000 trophies to their OWN league record per call, with
 * no proof that a match ever happened. At the general API rate ceiling that
 * is a full climb to Titan (and its leaderboard rewards) in minutes — a
 * competitive-integrity hole, not a feature. Trophies may now only be moved
 * by staff (moderator/admin) reconciling a settlement, the action is audited
 * with the full grant context, and the route additionally carries the `admin`
 * middleware (defence in depth — the controller check stands on its own even
 * if the route file regresses).
 *
 * Error responses were also tightened: internal exception messages are
 * logged, never echoed to the client.
 */
class LeagueApiController extends Controller
{
    public function __construct(protected LeagueService $leagueService) {}

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
            ],
        ]);
    }

    public function leaderboard(Request $request, string $slug)
    {
        $season = $request->get('season');
        try {
            $leaderboard = $this->leagueService->getLeaderboard($slug, $season ? (int) $season : null, 100);

            return response()->json(['success' => true, 'data' => $leaderboard]);
        } catch (\Exception $e) {
            // 404 for an unknown league slug; the internal message is logged,
            // not disclosed.
            logger()->info('gameberry league leaderboard lookup failed', [
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'error' => 'League not found.'], 404);
        }
    }

    public function show(Request $request, string $slug)
    {
        $league = League::where('slug', $slug)->first();
        if (! $league) {
            return response()->json(['success' => false, 'error' => 'League not found'], 404);
        }

        $userId = $request->user()->id;
        $userLevel = Level::where('user_id', $userId)->first();
        $levelNumber = $userLevel?->level ?? 1;

        if (! $this->leagueService->canAccessLeague($levelNumber, $slug)) {
            return response()->json(['success' => false, 'error' => "Need Level 4 to access Bronze - you are Level {$levelNumber}", 'required_level' => 4], 403);
        }

        $leaderboard = $this->leagueService->getLeaderboard($slug, null, 100);

        return response()->json([
            'success' => true,
            'data' => [
                'league' => $league,
                'leaderboard' => $leaderboard,
                'user_level' => $levelNumber,
            ],
        ]);
    }

    public function history(Request $request)
    {
        $userId = $request->user()->id;
        $history = LeagueHistory::with(['league'])->where('user_id', $userId)->orderByDesc('season')->get();
        $badges = TitanBadge::with('league')->where('user_id', $userId)->orderByDesc('created_at')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'history' => $history,
                'titan_badges' => $badges,
            ],
        ]);
    }

    /**
     * Staff-only manual trophy reconciliation (see class docblock).
     */
    public function addTrophies(Request $request, AuditLogService $audit): JsonResponse
    {
        $actor = $request->user();

        // Route middleware already guards this; the controller re-checks so
        // the invariant never depends on a single registration site.
        if ($actor === null || ! method_exists($actor, 'isStaff') || ! $actor->isStaff()) {
            return response()->json([
                'success' => false,
                'error' => 'forbidden',
                'message' => 'League trophies are adjusted by staff reconciliation only. Game results update them automatically.',
            ], 403);
        }

        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'trophies' => 'required|integer|min:-1000|max:1000',
            'is_win' => 'boolean',
            'reason' => 'required|string|min:5|max:500',
        ]);

        $targetUserId = (int) $data['user_id'];
        $delta = (int) $data['trophies'];

        try {
            $userLeague = $this->leagueService->addTrophies($targetUserId, $delta, $data['is_win'] ?? ($delta >= 0));
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['success' => false, 'error' => 'The trophy adjustment failed. Try again or use the admin tooling.'], 500);
        }

        $audit->recordQuietly($actor, 'league.trophy_grant', 'user', $targetUserId, [
            'target_user_id' => $targetUserId,
            'metadata' => [
                'delta' => $delta,
                'is_win' => (bool) ($data['is_win'] ?? ($delta >= 0)),
                'reason' => (string) $data['reason'],
                'via' => 'api',
            ],
        ]);

        return response()->json(['success' => true, 'data' => $userLeague->load('league')]);
    }
}
