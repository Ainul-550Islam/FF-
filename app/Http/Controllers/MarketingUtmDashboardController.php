<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarketingUtmDashboardRequest;
use App\Models\MarketingUtmSnapshot;
use App\Services\MarketingUtmGovernanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MarketingUtmDashboardController
{
    /**
     * UTM governance overview with detected tagging quality issues.
     */
    public function index(Request $request, MarketingUtmGovernanceService $utmService): View
    {
        $days = (int) $request->query('days', 30);
        $periodEnd = CarbonImmutable::now();
        $periodStart = $periodEnd->subDays($days);

        $issues = $utmService->issues();
        $summary = $utmService->summary($periodStart, $periodEnd);
        $recentSnapshots = MarketingUtmSnapshot::query()
            ->orderByDesc('period_start')
            ->orderByDesc('touches')
            ->take(10)
            ->get();

        return view('marketing.utm.dashboard', compact('issues', 'summary', 'recentSnapshots', 'days', 'periodStart', 'periodEnd'));
    }

    /**
     * Paginated snapshot archive with source/medium/campaign filters.
     */
    public function snapshots(Request $request, MarketingUtmGovernanceService $utmService): View
    {
        $filters = [
            'source' => $request->query('source'),
            'medium' => $request->query('medium'),
            'campaign' => $request->query('campaign'),
            'period_start' => $request->query('period_start'),
            'period_end' => $request->query('period_end'),
        ];

        $snapshots = $utmService->snapshotsPaginated($filters, 20);
        $sources = MarketingUtmSnapshot::query()->distinct()->whereNotNull('source')->pluck('source')->filter()->values();
        $mediums = MarketingUtmSnapshot::query()->distinct()->whereNotNull('medium')->pluck('medium')->filter()->values();

        return view('marketing.utm.snapshot', compact('snapshots', 'filters', 'sources', 'mediums'));
    }

    /**
     * Trigger on-demand rebuilding of aggregated UTM snapshots.
     */
    public function buildSnapshot(
        MarketingUtmDashboardRequest $request,
        MarketingUtmGovernanceService $utmService
    ): RedirectResponse {
        $start = $request->input('start_date') ?: $request->input('period_start') ?: now()->subDays(30)->toDateString();
        $end = $request->input('end_date') ?: $request->input('period_end') ?: now()->toDateString();

        $startDate = CarbonImmutable::parse($start);
        $endDate = CarbonImmutable::parse($end);

        $count = $utmService->buildSnapshot($startDate, $endDate);

        return back()->with('success', sprintf(
            'UTM snapshots aggregated successfully (%d campaign/source combinations recorded for %s to %s).',
            $count,
            $startDate->toDateString(),
            $endDate->toDateString()
        ));
    }
}
