<?php

namespace App\Services;

use App\Models\MarketingAffiliatePayout;
use App\Models\MarketingAttribution;
use App\Models\MarketingCampaign;
use App\Models\MarketingEvent;
use App\Models\MarketingUtmSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 22 — marketing analytics & UTM data export service.
 *
 * Produces deterministic, sanitized CSV representations of marketing performance
 * metrics, UTM governance snapshots, funnel aggregates, and affiliate payouts.
 * All operations are strictly read-only and never mutate attribution history.
 */
class MarketingAnalyticsExportService
{
    public function __construct(
        protected MarketingUtmGovernanceService $utmGovernance,
    ) {}

    /**
     * Dispatch an export by type.
     *
     * @return array{filename: string, content: string}
     */
    public function export(string $type, array $filters = []): array
    {
        $timestamp = now()->format('Y-m-d_His');

        return match ($type) {
            'utm' => [
                'filename' => "utm_governance_report_{$timestamp}.csv",
                'content' => $this->exportUtmCsv($filters),
            ],
            'funnel' => [
                'filename' => "funnel_events_report_{$timestamp}.csv",
                'content' => $this->exportFunnelCsv($filters),
            ],
            'campaigns' => [
                'filename' => "campaigns_performance_{$timestamp}.csv",
                'content' => $this->exportCampaignsCsv(),
            ],
            'affiliates' => [
                'filename' => "affiliate_payouts_{$timestamp}.csv",
                'content' => $this->exportAffiliatesCsv(),
            ],
            default => [
                'filename' => "marketing_overview_{$timestamp}.csv",
                'content' => $this->exportUtmCsv($filters),
            ],
        };
    }

    /**
     * Export UTM Snapshot / Governance dataset.
     */
    public function exportUtmCsv(array $filters = []): string
    {
        $query = MarketingUtmSnapshot::query()
            ->when(! empty($filters['source']), fn ($q) => $q->where('source', $filters['source']))
            ->when(! empty($filters['medium']), fn ($q) => $q->where('medium', $filters['medium']))
            ->when(! empty($filters['campaign']), fn ($q) => $q->where('campaign', $filters['campaign']))
            ->when(! empty($filters['period_start']), fn ($q) => $q->where('period_start', '>=', $filters['period_start']))
            ->when(! empty($filters['period_end']), fn ($q) => $q->where('period_end', '<=', $filters['period_end']))
            ->orderByDesc('period_start')
            ->orderByDesc('touches')
            ->orderByDesc('id');

        $headers = [
            'Period Start',
            'Period End',
            'Source',
            'Medium',
            'Campaign',
            'Content',
            'Term',
            'Touches',
            'Unique Visitors',
            'Conversions',
            'Attributed Users',
            'Conversion Rate (%)',
        ];

        $rows = [];
        $rows[] = $this->formatCsvLine($headers);

        foreach ($query->cursor() as $snapshot) {
            $cr = $snapshot->touches > 0
                ? number_format(($snapshot->conversions / $snapshot->touches) * 100, 2)
                : '0.00';

            $rows[] = $this->formatCsvLine([
                $snapshot->period_start?->format('Y-m-d') ?? '',
                $snapshot->period_end?->format('Y-m-d') ?? '',
                $snapshot->source,
                $snapshot->medium,
                $snapshot->campaign,
                $snapshot->content ?? '',
                $snapshot->term ?? '',
                $snapshot->touches,
                $snapshot->unique_visitors,
                $snapshot->conversions,
                $snapshot->attributed_users,
                $cr,
            ]);
        }

        return implode("\r\n", $rows)."\r\n";
    }

    /**
     * Export aggregated conversion funnel events.
     */
    public function exportFunnelCsv(array $filters = []): string
    {
        $headers = [
            'Date',
            'Event Name',
            'Total Occurrences',
            'Unique Visitors',
            'Unique Authenticated Users',
        ];

        $rows = [];
        $rows[] = $this->formatCsvLine($headers);

        $events = MarketingEvent::query()
            ->select([
                DB::raw('DATE(created_at) as event_date'),
                'name',
                DB::raw('COUNT(*) as total_events'),
                DB::raw('COUNT(DISTINCT anonymous_id) as unique_visitors'),
                DB::raw('COUNT(DISTINCT user_id) as unique_users'),
            ])
            ->when(! empty($filters['period_start']), fn ($q) => $q->where('created_at', '>=', $filters['period_start']))
            ->when(! empty($filters['period_end']), fn ($q) => $q->where('created_at', '<=', $filters['period_end']))
            ->groupBy('event_date', 'name')
            ->orderByDesc('event_date')
            ->orderBy('name')
            ->get();

        foreach ($events as $event) {
            $rows[] = $this->formatCsvLine([
                $event->event_date,
                $event->name,
                $event->total_events,
                $event->unique_visitors,
                $event->unique_users,
            ]);
        }

        return implode("\r\n", $rows)."\r\n";
    }

    /**
     * Export active marketing campaigns performance.
     */
    public function exportCampaignsCsv(): string
    {
        $headers = [
            'Campaign Slug',
            'Campaign Name',
            'UTM Campaign Key',
            'Status',
            'Start Date',
            'End Date',
            'Total Touches',
            'Registered Conversions',
        ];

        $rows = [];
        $rows[] = $this->formatCsvLine($headers);

        $campaigns = MarketingCampaign::query()->orderBy('slug')->get();

        foreach ($campaigns as $campaign) {
            $campaignKey = $campaign->campaignKey();
            $touches = MarketingAttribution::query()->where('campaign_key', $campaignKey)->count();
            $conversions = MarketingAttribution::query()->where('campaign_key', $campaignKey)->whereNotNull('converted_at')->count();

            $rows[] = $this->formatCsvLine([
                $campaign->slug,
                $campaign->name,
                $campaignKey,
                $campaign->active ? 'active' : 'inactive',
                $campaign->starts_at?->format('Y-m-d H:i') ?? '',
                $campaign->ends_at?->format('Y-m-d H:i') ?? '',
                $touches,
                $conversions,
            ]);
        }

        return implode("\r\n", $rows)."\r\n";
    }

    /**
     * Export affiliate commission payout requests.
     */
    public function exportAffiliatesCsv(): string
    {
        $headers = [
            'Payout ID',
            'Affiliate Code',
            'User ID',
            'Amount BDT',
            'Status',
            'Requested Date',
            'Processed Date',
            'Reviewed By ID',
            'Rejection Reason',
        ];

        $rows = [];
        $rows[] = $this->formatCsvLine($headers);

        $payouts = MarketingAffiliatePayout::query()
            ->with(['affiliate'])
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->get();

        foreach ($payouts as $payout) {
            $rows[] = $this->formatCsvLine([
                $payout->id,
                $payout->affiliate?->code ?? 'N/A',
                $payout->user_id,
                number_format($payout->amount_minor / 100, 2, '.', ''),
                $payout->status,
                $payout->requested_at?->format('Y-m-d H:i') ?? '',
                $payout->processed_at?->format('Y-m-d H:i') ?? '',
                $payout->reviewed_by ?? '',
                $payout->rejection_reason ?? '',
            ]);
        }

        return implode("\r\n", $rows)."\r\n";
    }

    /**
     * Escape and format fields safe from CSV formula injection (=, +, -, @).
     */
    protected function formatCsvLine(array $fields): string
    {
        $escaped = array_map(function ($value) {
            $str = (string) $value;

            // Neutralize formula injection risk in spreadsheet programs
            if (isset($str[0]) && in_array($str[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                $str = "'".$str;
            }

            // Standard RFC 4180 quote escaping
            if (str_contains($str, '"') || str_contains($str, ',') || str_contains($str, "\n") || str_contains($str, "\r")) {
                $str = '"'.str_replace('"', '""', $str).'"';
            }

            return $str;
        }, $fields);

        return implode(',', $escaped);
    }
}
