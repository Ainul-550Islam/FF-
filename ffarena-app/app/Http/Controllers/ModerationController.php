<?php

namespace App\Http\Controllers;

use App\Models\AntiCheatIncident;
use App\Models\Dispute;
use App\Models\RiskProfile;
use App\Models\Tournament;
use Illuminate\Http\Request;

/**
 * Moderation queue (Phase 07) + security review (Phase 10).
 *
 * Admins and moderators see the global queue; organizers see only their own
 * tournaments' disputes. Players can never access the queue or the security
 * review.
 */
class ModerationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        abort_unless(
            $user !== null && ($user->isAdmin() || $user->isModerator() || $user->isOrganizer()),
            403,
            'Staff access only.'
        );

        $disputes = Dispute::query()
            ->with(['match.tournament', 'opener', 'assignee', 'team'])
            ->orderByDesc('created_at');

        // Organizers are scoped to their own tournaments.
        if ($user->isOrganizer() && ! $user->isAdmin() && ! $user->isModerator()) {
            $disputes->whereHas('tournament', fn ($q) => $q->where('organizer_id', $user->id));
        }

        $status = $request->query('status');
        if ($status !== null && in_array($status, Dispute::STATUSES, true)) {
            $disputes->where('status', $status);
        }

        $tournamentId = (int) $request->query('tournament_id');
        if ($tournamentId > 0) {
            $disputes->where('tournament_id', $tournamentId);
        }

        $disputes = $disputes->paginate(25)->withQueryString();

        $tournaments = Tournament::query()->orderBy('name')->get(['id', 'name', 'organizer_id']);
        if ($user->isOrganizer() && ! $user->isAdmin() && ! $user->isModerator()) {
            $tournaments = $tournaments->where('organizer_id', $user->id);
        }

        return view('moderation.index', compact('disputes', 'tournaments', 'status', 'tournamentId'));
    }

    /**
     * Security review (Phase 10) — read-only for admins and moderators:
     * accounts flagged for review plus open anti-cheat incidents. Players
     * and organizers cannot access this page.
     */
    public function security(Request $request)
    {
        $user = $request->user();

        abort_unless(
            $user !== null && ($user->isAdmin() || $user->isModerator()),
            403,
            'Staff access only.'
        );

        $flaggedUsers = RiskProfile::query()
            ->with('user')
            ->where('manual_review_required', true)
            ->orWhereIn('risk_level', [RiskProfile::LEVEL_HIGH, RiskProfile::LEVEL_CRITICAL])
            ->orderByDesc('risk_score')
            ->limit(50)
            ->get();

        $openIncidents = AntiCheatIncident::query()
            ->with(['tournament', 'team', 'accusedUser', 'reviewer'])
            ->whereIn('status', [AntiCheatIncident::STATUS_FLAGGED, AntiCheatIncident::STATUS_UNDER_REVIEW])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return view('moderation.security', compact('flaggedUsers', 'openIncidents'));
    }
}
