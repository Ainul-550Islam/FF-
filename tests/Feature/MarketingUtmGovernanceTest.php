<?php

namespace Tests\Feature;

use App\Models\MarketingAttribution;
use App\Models\MarketingCampaign;
use App\Models\MarketingUtmSnapshot;
use App\Services\MarketingUtmGovernanceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 21 — UTM governance (reporting layer over MarketingAttribution).
 *
 * Governance normalizes dimension values for reporting and builds
 * idempotent period snapshots. It never rewrites the captured attribution
 * originals and introduces no second attribution system.
 */
class MarketingUtmGovernanceTest extends TestCase
{
    use RefreshDatabase;

    protected function service(): MarketingUtmGovernanceService
    {
        return app(MarketingUtmGovernanceService::class);
    }

    protected function attribution(array $overrides = []): MarketingAttribution
    {
        return MarketingAttribution::create(array_merge([
            'anonymous_id' => $overrides['anonymous_id'] ?? (string) Str::uuid(),
            'campaign_key' => 'spring-launch',
            'source' => 'facebook',
            'medium' => 'cpc',
            'campaign' => 'spring-launch',
            'content' => null,
            'term' => null,
            'click_id_type' => null,
            'click_id' => null,
            'landing_path' => '/',
            'referrer_host' => null,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'converted_at' => null,
            'conversion_type' => null,
        ], $overrides));
    }

    public function test_normalization_is_trim_lowercase_and_whitespace_collapsed(): void
    {
        $this->assertSame('facebook', $this->service()->normalize(' FACEBOOK '));
        $this->assertSame('spring launch', $this->service()->normalize("Spring\tLaunch"));
        $this->assertSame('paid social', $this->service()->normalize('paid    social'));
        $this->assertSame('', $this->service()->normalize(null));
        $this->assertSame('', $this->service()->normalize('   '));
    }

    public function test_build_snapshot_aggregates_touches_visitors_conversions(): void
    {
        $this->attribution(['anonymous_id' => '22222222-2222-4222-8222-222222222222', 'user_id' => 7, 'conversion_type' => 'register', 'converted_at' => now()]);
        $this->attribution(['anonymous_id' => '33333333-3333-4333-8333-333333333333']);
        // Same normalized combination, different original casing — groups together.
        $this->attribution(['anonymous_id' => '44444444-4444-4444-8444-444444444444', 'source' => 'FACEBOOK ']);

        $written = $this->service()->buildSnapshot(CarbonImmutable::today(), CarbonImmutable::today());

        $this->assertSame(1, $written);

        $snapshot = MarketingUtmSnapshot::query()->first();
        $this->assertSame(3, (int) $snapshot->touches);
        $this->assertSame(3, (int) $snapshot->unique_visitors, 'One row per attribution touch; casing variants group but keep their visitor.');
        $this->assertSame(1, (int) $snapshot->conversions);
        $this->assertSame(1, (int) $snapshot->attributed_users);
        $this->assertSame('facebook', $snapshot->source, 'Snapshot dimensions are normalized.');
    }

    public function test_rebuilding_a_period_is_idempotent(): void
    {
        $this->attribution();
        $this->attribution(['anonymous_id' => '55555555-5555-4555-8555-555555555555']);

        $first = $this->service()->buildSnapshot(CarbonImmutable::today(), CarbonImmutable::today());
        $second = $this->service()->buildSnapshot(CarbonImmutable::today(), CarbonImmutable::today());

        $this->assertSame(1, $first);
        $this->assertSame(1, $second, 'A rebuild upserts instead of duplicating.');
        $this->assertSame(1, MarketingUtmSnapshot::query()->count());
        $this->assertSame(2, (int) MarketingUtmSnapshot::query()->value('touches'));
    }

    public function test_snapshots_never_rewrite_the_attribution_originals(): void
    {
        $row = $this->attribution(['source' => ' Facebook ', 'campaign' => 'Spring-Launch']);

        $this->service()->buildSnapshot(CarbonImmutable::today(), CarbonImmutable::today());

        $row->refresh();
        $this->assertSame(' Facebook ', $row->source, 'Captured originals are immutable.');
        $this->assertSame('Spring-Launch', $row->campaign);
    }

    public function test_out_of_period_rows_are_excluded(): void
    {
        $this->attribution(['first_seen_at' => now()->subDays(10)]);
        $this->attribution(['first_seen_at' => now()->addDays(10)]);

        $written = $this->service()->buildSnapshot(CarbonImmutable::today(), CarbonImmutable::today());

        $this->assertSame(0, $written);
        $this->assertSame(0, MarketingUtmSnapshot::query()->count());
    }

    public function test_summary_orders_busiest_combinations_first(): void
    {
        $this->attribution();
        $this->attribution(['source' => 'google', 'medium' => 'organic', 'campaign' => null, 'campaign_key' => '(direct)']);
        $this->attribution(['anonymous_id' => '66666666-6666-4666-8666-666666666666', 'source' => 'google', 'medium' => 'organic', 'campaign' => null, 'campaign_key' => '(direct)']);

        $this->service()->buildSnapshot(CarbonImmutable::today(), CarbonImmutable::today());

        $summary = $this->service()->summary(CarbonImmutable::today(), CarbonImmutable::today());

        $this->assertSame(2, $summary->count());
        $this->assertSame('google', $summary->first()->source, 'The busiest combination leads.');
        $this->assertSame(2, (int) $summary->first()->touches);
    }

    public function test_issues_flags_unregistered_campaigns(): void
    {
        // A registered campaign makes its own key legit.
        MarketingCampaign::create([
            'slug' => 'spring-launch',
            'name' => 'Spring Launch',
            'headline' => 'Play this spring',
            'utm_campaign' => 'spring-launch',
            'active' => true,
        ]);

        $this->attribution(['campaign' => 'spring-launch']);
        $this->attribution(['campaign' => 'rogue-campaign']);
        $this->attribution(['campaign' => 'rogue-campaign', 'source' => 'facebook']);

        $issues = $this->service()->issues();

        $this->assertSame(['rogue-campaign' => 2], $issues['unregistered_campaigns']);
    }

    public function test_click_id_rows_are_exempt_from_campaign_issues(): void
    {
        // Click-id rows are not nameable UTMs — they must not appear as
        // unregistered campaigns nor as missing-medium tagging errors.
        $this->attribution([
            'campaign_key' => 'fbclid',
            'campaign' => null,
            'medium' => null,
            'click_id_type' => 'fbclid',
            'click_id' => 'abc123',
        ]);

        $issues = $this->service()->issues();

        $this->assertSame([], $issues['unregistered_campaigns']);
        $this->assertSame(0, $issues['missing_medium']);
    }

    public function test_issues_flags_missing_medium_and_casing_drift(): void
    {
        $this->attribution(['campaign' => 'no-medium', 'medium' => null]);
        $this->attribution(['source' => 'Facebook']);
        $this->attribution(['source' => 'facebook', 'campaign' => null]);

        $issues = $this->service()->issues();

        $this->assertSame(1, $issues['missing_medium'], 'Only utm-tagged rows with a campaign count.');

        $casing = $issues['inconsistent_source_casing'];
        $this->assertSame(['facebook'], array_keys($casing));
        $this->assertSame(1, $casing['facebook']['Facebook']);
        $this->assertSame(2, $casing['facebook']['facebook']);
    }
}
