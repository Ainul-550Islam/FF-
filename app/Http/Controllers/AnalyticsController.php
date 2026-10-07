<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Services\AnalyticsService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin analytics (Phase 16/17).
 *
 * Read-only: every method here aggregates data and never mutates business or
 * financial state. Each view receives exactly the variables it renders —
 * `resources/views/admin/analytics/tournaments.blade.php` needs `overview`,
 * `matches` and `players` together with the paginated `$tournaments` list, and
 * a missing one used to surface as a 500 ("Undefined variable $overview").
 */
class AnalyticsController extends Controller
{
    public function index(AnalyticsService $analytics): View
    {
        $overview = $analytics->platformOverview();
        // The analytics landing page renders both the platform overview and
        // the financial block; only `overview` used to be passed, so the page
        // died with "Undefined variable $financial" (HTTP 500) for admins.
        $financial = $analytics->financialMetrics();
        // …and the security + support blocks on the same page ($security, $support).
        $security = $analytics->securityMetrics();
        $support = $analytics->supportMetrics();

        return view('admin.analytics.index', compact('overview', 'financial', 'security', 'support'));
    }

    public function financial(AnalyticsService $analytics): View
    {
        $metrics = $analytics->financialMetrics();

        return view('admin.analytics.financial', compact('metrics'));
    }

    public function security(AnalyticsService $analytics): View
    {
        $metrics = $analytics->securityMetrics();

        return view('admin.analytics.security', compact('metrics'));
    }

    public function disputes(AnalyticsService $analytics): View
    {
        $metrics = $analytics->disputeMetrics();

        return view('admin.analytics.disputes', compact('metrics'));
    }

    public function support(AnalyticsService $analytics): View
    {
        $metrics = $analytics->supportMetrics();

        return view('admin.analytics.support', compact('metrics'));
    }

    /**
     * Tournament + match + player analytics.
     *
     * The date range is validated (not trusted) and the same range is applied
     * to every block, so the header counters and the table always describe the
     * same window.
     */
    public function tournaments(Request $request, AnalyticsService $analytics): View
    {
        $data = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $from = $data['from'] ?? null;
        $to = $data['to'] ?? null;

        $overview = $analytics->platformOverview($from, $to);
        $matches = $analytics->matchMetrics($from, $to);
        $players = $analytics->playerMetrics($from, $to);

        $tournaments = Tournament::query()
            ->with('organizer')
            ->withCount([
                'teams',
                'matches',
                'matches as completed_matches_count' => fn ($q) => $q->where('status', 'completed'),
            ])
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('admin.analytics.tournaments', compact(
            'tournaments',
            'overview',
            'matches',
            'players',
            'from',
            'to',
        ));
    }

    public function tournament(Tournament $tournament, AnalyticsService $analytics): View
    {
        $user = auth()->user();

        if (! $user || (! $user->isStaff() && $tournament->organizer_id !== $user->id)) {
            abort(403);
        }

        $metrics = $analytics->tournamentMetrics($tournament);

        return view('admin.analytics.tournament', compact('tournament', 'metrics'));
    }

    public function exportTournaments(): StreamedResponse
    {
        $tournaments = Tournament::query()->with(['organizer'])->orderBy('id')->get();

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="tournaments-analytics-export.csv"',
        ];

        return response()->stream(function () use ($tournaments) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Name', 'Slug', 'Organizer', 'Status', 'Game Mode', 'Entry Fee', 'Prize Pool', 'Created At']);

            foreach ($tournaments as $t) {
                fputcsv($handle, [
                    $t->id,
                    $t->name,
                    $t->slug,
                    $t->organizer?->name ?? 'Unknown',
                    $t->status,
                    $t->game_mode,
                    $t->entry_fee,
                    $t->prize_pool,
                    $t->created_at?->toISOString(),
                ]);
            }

            fclose($handle);
        }, 200, $headers);
    }
}
