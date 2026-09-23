<?php

namespace App\Services;

use App\Models\MarketingAttribution;
use App\Models\MarketingCampaign;
use App\Models\MarketingUtmSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Phase 21 — UTM governance (a reporting layer over MarketingAttribution).
 *
 * This is deliberately NOT a second attribution system: it only normalizes
 * dimension values for reporting (trim + lowercase + collapsed whitespace),
 * flags tagging-quality issues, and aggregates idempotent period snapshots.
 * The captured attribution originals are never rewritten — normalization
 * happens at read/aggregation time only.
 */
class MarketingUtmGovernanceService
{
    /**
     * Normalize a UTM dimension value for reporting: trim, lowercase,
     * collapse internal whitespace. The original attribution columns stay
     * untouched.
     */
    public function normalize(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        return mb_strtolower(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    /**
     * Quality checks over captured attribution data.
     *
     * @return array{unregistered_campaigns:array<string,int>, missing_medium:int, inconsistent_source_casing:array<string,array<string,int>>}
     */
    public function issues(): array
    {
        $rows = MarketingAttribution::query()
            ->where('campaign_key', '!=', MarketingAttributionService::DIRECT_KEY)
            ->get(['source', 'medium', 'campaign', 'click_id_type']);

        // utm_campaign values that are not registered campaign landing keys
        // (click-id / referrer keys are exempt — they are not nameable UTMs).
        $registered = MarketingCampaign::query()->get()
            ->map(fn ($c) => mb_strtolower($c->campaignKey()))
            ->flip();

        $unregistered = [];

        foreach ($rows as $row) {
            if ($row->click_id_type !== null || $row->campaign === null) {
                continue;
            }

            $key = mb_strtolower((string) $row->campaign);

            if (! $registered->has($key)) {
                $unregistered[$row->campaign] = ($unregistered[$row->campaign] ?? 0) + 1;
            }
        }

        // utm-sourced rows must carry a medium (the classic tagging error).
        $missingMedium = (int) $rows->whereNull('medium')
            ->filter(fn ($row) => $row->click_id_type === null && $row->campaign !== null)
            ->count();

        // The same source captured with different casings.
        $bySource = [];

        foreach ($rows->filter(fn ($row) => $row->source !== null) as $row) {
            $bySource[mb_strtolower((string) $row->source)][] = (string) $row->source;
        }

        $inconsistent = [];

        foreach ($bySource as $lower => $variants) {
            $distinct = array_count_values($variants);

            if (count($distinct) > 1) {
                $inconsistent[$lower] = $distinct;
            }
        }

        return [
            'unregistered_campaigns' => $unregistered,
            'missing_medium' => $missingMedium,
            'inconsistent_source_casing' => $inconsistent,
        ];
    }

    /**
     * Build (and idempotently upsert) the governance snapshot for a period.
     * Returns the number of snapshot rows written.
     */
    public function buildSnapshot(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): int
    {
        $rows = MarketingAttribution::query()
            ->whereBetween('first_seen_at', [$periodStart->startOfDay(), $periodEnd->endOfDay()])
            ->get(['anonymous_id', 'user_id', 'source', 'medium', 'campaign', 'content', 'term', 'conversion_type']);

        // Group by normalized dimension combination, then aggregate.
        $grouped = [];

        foreach ($rows as $row) {
            $key = implode('|', [
                $this->normalize($row->source),
                $this->normalize($row->medium),
                $this->normalize($row->campaign),
                $this->normalize($row->content),
                $this->normalize($row->term),
            ]);

            $grouped[$key] ??= ['source' => '', 'medium' => '', 'campaign' => '', 'content' => '', 'term' => '', 'anonymous' => collect(), 'touches' => 0, 'conversions' => 0, 'users' => collect()];

            $bucket = &$grouped[$key];
            [$bucket['source'], $bucket['medium'], $bucket['campaign'], $bucket['content'], $bucket['term']] = array_map($this->normalize(...), [$row->source, $row->medium, $row->campaign, $row->content, $row->term]);
            $bucket['touches']++;
            $bucket['anonymous']->push($row->anonymous_id);
            $bucket['conversions'] += $row->conversion_type !== null ? 1 : 0;

            if ($row->user_id !== null) {
                $bucket['users']->push($row->user_id);
            }

            unset($bucket);
        }

        $written = 0;

        foreach ($grouped as $bucket) {
            MarketingUtmSnapshot::query()->updateOrCreate(
                [
                    // Date columns round-trip as midnight datetimes (the
                    // storage format on both sqlite and MySQL), so the
                    // upsert lookup uses the same normalized form — a plain
                    // date string would miss and duplicate on rebuild.
                    'period_start' => $periodStart->startOfDay()->toDateTimeString(),
                    'period_end' => $periodEnd->startOfDay()->toDateTimeString(),
                    'source' => $bucket['source'],
                    'medium' => $bucket['medium'],
                    'campaign' => $bucket['campaign'],
                    'content' => $bucket['content'],
                    'term' => $bucket['term'],
                ],
                [
                    'touches' => $bucket['touches'],
                    'unique_visitors' => $bucket['anonymous']->filter()->unique()->count(),
                    'conversions' => $bucket['conversions'],
                    'attributed_users' => $bucket['users']->unique()->count(),
                    'created_at' => now(),
                ]
            );

            $written++;
        }

        return $written;
    }

    /**
     * Read back a period's snapshots, busiest combinations first.
     */
    public function summary(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): Collection
    {
        return MarketingUtmSnapshot::query()
            ->whereBetween('period_start', [$periodStart->startOfDay(), $periodEnd->endOfDay()])
            ->orderByDesc('touches')
            ->orderByDesc('id')
            ->get();
    }
}
