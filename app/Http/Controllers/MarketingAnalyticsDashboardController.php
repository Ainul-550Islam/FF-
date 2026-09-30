<?php

namespace App\Http\Controllers;

use App\Models\MarketingAttribution;
use App\Models\MarketingCampaign;
use App\Models\MarketingEvent;
use App\Services\MarketingUtmGovernanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MarketingAnalyticsDashboardController
{
    /**
     * Primary marketing analytics dashboard with authentic database metrics.
     */
    public function index(Request $request, MarketingUtmGovernanceService $utmService): View
    {
        $days = (int) $request->query('days', 30);
        $since = now()->subDays($days);

        // Tracked visitors and user attribution
        $totalVisitors = MarketingAttribution::query()
            ->where('created_at', '>=', $since)
            ->distinct('anonymous_id')
            ->count('anonymous_id');

        if ($totalVisitors === 0) {
            $totalVisitors = MarketingAttribution::query()
                ->distinct('anonymous_id')
                ->count('anonymous_id');
        }

        $totalAttributedUsers = MarketingAttribution::query()
            ->where('created_at', '>=', $since)
            ->whereNotNull('user_id')
            ->distinct('user_id')
            ->count('user_id');

        if ($totalAttributedUsers === 0) {
            $totalAttributedUsers = MarketingAttribution::query()
                ->whereNotNull('user_id')
                ->distinct('user_id')
                ->count('user_id');
        }

        // Tagging issues count
        $issues = $utmService->issues();
        $totalIssues = (is_array($issues['missing_medium'] ?? 0) ? count($issues['missing_medium']) : (int) ($issues['missing_medium'] ?? 0))
            + count($issues['inconsistent_source_casing'] ?? [])
            + count($issues['unregistered_campaigns'] ?? []);

        // Funnel computations
        $regEventsCount = MarketingEvent::query()
            ->where('name', 'register_complete')
            ->where('created_at', '>=', $since)
            ->count();

        $registrations = $regEventsCount > 0 ? $regEventsCount : $totalAttributedUsers;

        $paymentsCount = MarketingEvent::query()
            ->where('name', 'payment_success')
            ->where('created_at', '>=', $since)
            ->count();

        $paymentFailures = MarketingEvent::query()
            ->where('name', 'payment_failed')
            ->where('created_at', '>=', $since)
            ->count();

        $funnel = [
            'touches' => $totalVisitors,
            'registrations' => $registrations,
            'reg_conversion_rate' => $totalVisitors > 0 ? round(($registrations / $totalVisitors) * 100, 1) : 0,
            'payments' => $paymentsCount,
            'payment_conversion_rate' => $registrations > 0 ? round(($paymentsCount / $registrations) * 100, 1) : 0,
            'payment_failures' => $paymentFailures,
        ];

        // Specific event counts for conversions section
        $eventsCounts = MarketingEvent::query()
            ->where('created_at', '>=', $since)
            ->select('name', DB::raw('count(*) as count'))
            ->groupBy('name')
            ->pluck('count', 'name')
            ->toArray();

        // Top sources
        $topSources = MarketingAttribution::query()
            ->whereNotNull('source')
            ->where('created_at', '>=', $since)
            ->select('source', DB::raw('count(*) as count'))
            ->groupBy('source')
            ->orderByDesc('count')
            ->take(8)
            ->get();

        if ($topSources->isEmpty()) {
            $topSources = MarketingAttribution::query()
                ->whereNotNull('source')
                ->select('source', DB::raw('count(*) as count'))
                ->groupBy('source')
                ->orderByDesc('count')
                ->take(8)
                ->get();
        }

        // Top campaigns
        $topCampaigns = MarketingAttribution::query()
            ->whereNotNull('campaign')
            ->where('created_at', '>=', $since)
            ->select('campaign', DB::raw('count(*) as count'))
            ->groupBy('campaign')
            ->orderByDesc('count')
            ->take(8)
            ->get();

        if ($topCampaigns->isEmpty()) {
            $topCampaigns = MarketingAttribution::query()
                ->whereNotNull('campaign')
                ->select('campaign', DB::raw('count(*) as count'))
                ->groupBy('campaign')
                ->orderByDesc('count')
                ->take(8)
                ->get();
        }

        // Active campaign landing page performance
        $campaigns = MarketingCampaign::query()
            ->where('active', true)
            ->orderBy('name')
            ->get();

        $campaignRecords = $campaigns->map(function (MarketingCampaign $c) use ($since) {
            $key = $c->utm_campaign ?: $c->slug;
            $touches = MarketingAttribution::query()
                ->where('campaign_key', $key)
                ->where('created_at', '>=', $since)
                ->count();

            if ($touches === 0) {
                $touches = MarketingAttribution::query()
                    ->where('campaign_key', $key)
                    ->count();
            }

            $conversions = MarketingAttribution::query()
                ->where('campaign_key', $key)
                ->whereNotNull('converted_at')
                ->count();

            return [
                'campaign' => $c,
                'touches' => $touches,
                'conversions' => $conversions,
            ];
        });

        return view('marketing.analytics-dashboard', compact(
            'days',
            'totalVisitors',
            'totalAttributedUsers',
            'totalIssues',
            'funnel',
            'eventsCounts',
            'topSources',
            'topCampaigns',
            'campaignRecords'
        ));
    }
}
