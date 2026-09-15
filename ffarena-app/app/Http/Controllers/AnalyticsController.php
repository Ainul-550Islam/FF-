<?php

namespace App\Http\Controllers;

use App\Models\GameMatch;
use App\Models\Team;
use App\Models\Tournament;
use App\Services\AnalyticsService;
use App\Support\CsvExport;
use Illuminate\Http\Request;

/**
 * Operational analytics (Phase 13).
 *
 * Global, financial and security analytics are admin-only. Dispute and
 * support analytics are platform-staff (admin/moderator). Per-tournament
 * metrics are available to the tournament's organizer as well as platform
 * staff. Nothing here mutates any domain state.
 */
class AnalyticsController extends Controller
{
    public function __construct(
        protected AnalyticsService $analytics,
    ) {
    }

    /**
     * Admin analytics overview.
     */
    public function index()
    {
        $this->requireAdmin();

        $overview = $this->analytics->platformOverview();
        $financial = $this->analytics->financialMetrics();
        $security = $this->analytics->securityMetrics();
        $support = $this->analytics->supportMetrics();
        $disputes = $this->analytics->disputeMetrics();

        return view('admin.analytics.index', compact('overview', 'financial', 'security', 'support', 'disputes'));
    }

    /**
     * Tournament + match + player operational metrics (admin).
     */
    public function tournaments(Request $request)
    {
        $this->requireAdmin();

        $from = $request->query('from');
        $to = $request->query('to');

        $overview = $this->analytics->platformOverview($from, $to);
        $matches = $this->analytics->matchMetrics($from, $to);
        $players = $this->analytics->playerMetrics($from, $to);

        $tournaments = Tournament::query()
            ->withCount(['teams', 'matches'])
            ->withCount(['matches as completed_matches' => fn ($q) => $q->where('status', GameMatch::STATUS_COMPLETED)])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.analytics.tournaments', compact('overview', 'matches', 'players', 'tournaments', 'from', 'to'));
    }

    /**
     * Financial analytics (admin).
     */
    public function financial(Request $request)
    {
        $this->requireAdmin();

        $metrics = $this->analytics->financialMetrics($request->query('from'), $request->query('to'));

        return view('admin.analytics.financial', compact('metrics'));
    }

    /**
     * Security/risk analytics (admin).
     */
    public function security()
    {
        $this->requireAdmin();

        $metrics = $this->analytics->securityMetrics();

        return view('admin.analytics.security', compact('metrics'));
    }

    /**
     * Dispute analytics (staff).
     */
    public function disputes(Request $request)
    {
        $this->requireStaff();

        $metrics = $this->analytics->disputeMetrics($request->query('from'), $request->query('to'));

        return view('admin.analytics.disputes', compact('metrics'));
    }

    /**
     * Support analytics (staff).
     */
    public function support(Request $request)
    {
        $this->requireStaff();

        $metrics = $this->analytics->supportMetrics($request->query('from'), $request->query('to'));

        return view('admin.analytics.support', compact('metrics'));
    }

    /**
     * Per-tournament operational metrics (organizer, admin or moderator).
     */
    public function tournament(Tournament $tournament)
    {
        $user = auth()->user();

        abort_unless(
            $user !== null && ($user->isAdmin() || $user->isModerator() || $tournament->organizer_id === $user->id),
            403,
            'You are not authorized to view this tournament\'s analytics.'
        );

        $metrics = $this->analytics->tournamentMetrics($tournament);

        return view('admin.analytics.tournament', compact('tournament', 'metrics'));
    }

    /**
     * CSV export of tournament operational metrics (admin).
     */
    public function exportTournaments()
    {
        $this->requireAdmin();

        // Aggregate team states per tournament in a single query.
        $teamAggregates = Team::query()
            ->selectRaw('tournament_id,
                COUNT(*) AS total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS confirmed,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS waitlisted,
                SUM(CASE WHEN checked_in_at IS NOT NULL THEN 1 ELSE 0 END) AS checked_in,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS no_show',
                [Team::STATUS_CONFIRMED, Team::STATUS_WAITLISTED, Team::STATUS_NO_SHOW])
            ->groupBy('tournament_id')
            ->get()
            ->keyBy('tournament_id');

        $headers = [
            'id', 'name', 'status', 'starts_at', 'teams', 'confirmed',
            'waitlisted', 'checked_in', 'no_show', 'check_in_rate_pct',
        ];

        return CsvExport::download('tournament-analytics', $headers, function () use ($teamAggregates) {
            foreach (Tournament::query()->orderBy('id')->cursor() as $tournament) {
                $agg = $teamAggregates->get($tournament->id);

                $confirmed = (int) ($agg->confirmed ?? 0);
                $noShow = (int) ($agg->no_show ?? 0);
                $slotTeams = $confirmed + $noShow;
                $checkedIn = (int) ($agg->checked_in ?? 0);
                $rate = $slotTeams > 0 ? round($checkedIn / $slotTeams * 100, 1) : 0.0;

                yield [
                    $tournament->id,
                    $tournament->name,
                    $tournament->status,
                    optional($tournament->starts_at)->toIso8601String(),
                    (int) ($agg->total ?? 0),
                    $confirmed,
                    (int) ($agg->waitlisted ?? 0),
                    $checkedIn,
                    $noShow,
                    $rate,
                ];
            }
        });
    }

    /**
     * Admin-only gate for global/financial/security analytics.
     */
    protected function requireAdmin(): void
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403, 'Admin access only.');
    }

    /**
     * Staff gate for dispute/support analytics.
     */
    protected function requireStaff(): void
    {
        abort_unless(auth()->user()?->isStaff() ?? false, 403, 'Staff access only.');
    }
}
