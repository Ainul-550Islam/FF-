<?php

namespace App\Http\Controllers;

use App\Models\AntiCheatIncident;
use App\Models\Dispute;
use App\Models\RiskProfile;
use App\Models\Tournament;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Moderation queues (disputes + anti-cheat/risk), Phase 10/12.
 *
 * Access is enforced here (not only on the route) because `/moderation` is
 * registered twice in routes/web.php and the later definition currently wins:
 *
 *  - platform staff (admin/moderator) see every queue,
 *  - organizers see only their own tournaments' disputes — an organizer queue is
 *    scoped by `tournaments.organizer_id`, so one organizer can never read
 *    another's disputes, teams or evidence,
 *  - players (and any other role) get 403 instead of an empty queue that would
 *    still leak counts and team names.
 */
class ModerationController extends Controller
{
    public function index(Request $request): View
    {
        $actor = auth()->user();

        if (! $actor || ! ($actor->isStaff() || $actor->isOrganizer())) {
            abort(403);
        }

        $status = $request->input('status');
        $tournamentId = $request->input('tournament_id') ? (int) $request->input('tournament_id') : null;

        $tournaments = Tournament::query()
            ->when($actor->isOrganizer() && ! $actor->isStaff(), fn ($q) => $q->where('organizer_id', $actor->id))
            ->orderBy('name')
            ->get();

        $disputes = Dispute::query()
            ->with(['match.tournament', 'team', 'assignee'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($tournamentId, fn ($q) => $q->whereHas('match', fn ($mq) => $mq->where('tournament_id', $tournamentId)))
            // Organizers are scoped to the tournaments they run. Staff are not
            // scoped — they are the escalation path across the platform.
            ->when(
                $actor->isOrganizer() && ! $actor->isStaff(),
                fn ($q) => $q->whereHas('match.tournament', fn ($tq) => $tq->where('organizer_id', $actor->id))
            )
            ->latest()
            ->paginate(25);

        return view('moderation.index', compact('status', 'tournamentId', 'tournaments', 'disputes'));
    }

    public function security(): View
    {
        $actor = auth()->user();

        // Risk profiles and anti-cheat incidents are platform-wide safety data:
        // staff only. Organizers get the disputes queue, not the risk engine.
        if (! $actor || ! $actor->isStaff()) {
            abort(403);
        }

        $flaggedUsers = RiskProfile::query()
            ->with('user')
            ->where(function ($q) {
                $q->where('manual_review_required', true)
                    ->orWhere('risk_score', '>=', 50);
            })
            ->latest()
            ->get();

        $openIncidents = AntiCheatIncident::query()
            ->with(['tournament', 'team'])
            ->whereIn('status', [AntiCheatIncident::STATUS_FLAGGED, 'under_review'])
            ->latest()
            ->get();

        return view('moderation.security', compact('flaggedUsers', 'openIncidents'));
    }
}
