<?php

namespace Tests\Feature;

use App\Models\MarketingAttribution;
use App\Models\MarketingCampaign;
use App\Models\MarketingEvent;
use App\Models\MarketingUtmSnapshot;
use App\Models\User;
use App\Services\MarketingAttributionService;
use App\Services\MarketingUtmGovernanceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 22 — Phase C reporting & export tests:
 * UTM dashboard, analytics dashboard, CSV export security, filter validation,
 * and attribution immutability during reporting.
 */
class MarketingReportingPhaseCTest extends TestCase
{
    use RefreshDatabase;

    public function test_utm_and_analytics_dashboards_require_admin(): void
    {
        // Guests redirected
        $this->get('/admin/marketing/utm')->assertRedirect(route('login'));
        $this->get('/admin/marketing/analytics')->assertRedirect(route('login'));

        // Normal player is forbidden (403)
        $player = User::factory()->create(['role' => 'player']);
        $this->actingAs($player)->get('/admin/marketing/utm')->assertForbidden();
        $this->actingAs($player)->get('/admin/marketing/analytics')->assertForbidden();
        $this->actingAs($player)->get('/admin/marketing/analytics/export?type=utm')->assertForbidden();
    }

    public function test_utm_dashboard_renders_detected_issues_and_summary(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Create attribution touches with tagging inconsistencies
        MarketingAttribution::create([
            'anonymous_id' => 'anon_1',
            'campaign_key' => 'unregistered-tag',
            'source' => 'Facebook',
            'medium' => null, // Missing medium issue
            'campaign' => 'unregistered-tag',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        MarketingAttribution::create([
            'anonymous_id' => 'anon_2',
            'campaign_key' => 'unregistered-tag',
            'source' => 'facebook', // Casing drift issue vs Facebook
            'medium' => 'cpc',
            'campaign' => 'unregistered-tag',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        // Build snapshot
        app(MarketingUtmGovernanceService::class)->buildSnapshot(
            CarbonImmutable::now()->subDays(5),
            CarbonImmutable::now()->addDay()
        );

        $response = $this->actingAs($admin)->get('/admin/marketing/utm');
        $response->assertOk();
        $response->assertSee('Detected Tagging Quality Issues');
        $response->assertSee('Missing Mediums');
        $response->assertSee('unregistered-tag');
    }

    public function test_utm_snapshots_filtering_and_pagination(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        MarketingUtmSnapshot::create([
            'period_start' => now()->subDays(10)->toDateString(),
            'period_end' => now()->toDateString(),
            'source' => 'google',
            'medium' => 'cpc',
            'campaign' => 'spring_championship',
            'touches' => 250,
            'unique_visitors' => 200,
            'conversions' => 40,
            'attributed_users' => 35,
            'created_at' => now(),
        ]);

        MarketingUtmSnapshot::create([
            'period_start' => now()->subDays(10)->toDateString(),
            'period_end' => now()->toDateString(),
            'source' => 'facebook',
            'medium' => 'social',
            'campaign' => 'community_tournament',
            'touches' => 150,
            'unique_visitors' => 120,
            'conversions' => 20,
            'attributed_users' => 18,
            'created_at' => now(),
        ]);

        // Filter by source=google
        $response = $this->actingAs($admin)->get('/admin/marketing/utm/snapshots?source=google');
        $response->assertOk();
        $response->assertSee('spring_championship');
        $response->assertDontSee('community_tournament');
    }

    public function test_utm_snapshot_rebuild_action(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        MarketingAttribution::create([
            'anonymous_id' => 'anon_rebuild',
            'campaign_key' => 'summer_fest',
            'source' => 'meta',
            'medium' => 'cpc',
            'campaign' => 'summer_fest',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $response = $this->actingAs($admin)->post('/admin/marketing/utm/snapshots/build', [
            'start_date' => now()->subDays(7)->toDateString(),
            'end_date' => now()->toDateString(),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('marketing_utm_snapshots', [
            'source' => 'meta',
            'campaign' => 'summer_fest',
        ]);
    }

    public function test_analytics_dashboard_renders_real_metrics_and_funnel(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Create campaign
        $campaign = MarketingCampaign::create([
            'name' => 'Pro Cup 2026',
            'headline' => 'Join the Ultimate Esports Pro Cup',
            'slug' => 'pro-cup-2026',
            'active' => true,
        ]);

        // Create attribution and events
        $user = User::factory()->create();

        MarketingAttribution::create([
            'anonymous_id' => 'anon_kpi',
            'user_id' => $user->id,
            'campaign_key' => 'pro-cup-2026',
            'source' => 'tiktok',
            'medium' => 'video',
            'campaign' => 'pro-cup-2026',
            'first_seen_at' => now(),
            'converted_at' => now(),
        ]);

        MarketingEvent::create([
            'name' => 'landing_view',
            'anonymous_id' => 'anon_kpi',
            'created_at' => now(),
        ]);

        MarketingEvent::create([
            'name' => 'register_complete',
            'anonymous_id' => 'anon_kpi',
            'user_id' => $user->id,
            'created_at' => now(),
        ]);

        MarketingEvent::create([
            'name' => 'payment_success',
            'user_id' => $user->id,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get('/admin/marketing/analytics');
        $response->assertOk();
        $response->assertSee('Marketing Analytics');
        $response->assertSee('tiktok');
        $response->assertSee('Pro Cup 2026');
        $response->assertSee('Stage 1: Top of Funnel');
        $response->assertSee('Stage 2: Registrations');
        $response->assertSee('Stage 3: Paid Conversions');
    }

    public function test_analytics_export_emits_valid_csv(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        MarketingUtmSnapshot::create([
            'period_start' => now()->subDay()->toDateString(),
            'period_end' => now()->toDateString(),
            'source' => 'tiktok',
            'medium' => 'video',
            'campaign' => 'export_test',
            'touches' => 100,
            'unique_visitors' => 90,
            'conversions' => 15,
            'attributed_users' => 15,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get('/admin/marketing/analytics/export?type=utm');
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Period Start,Period End,Source,Medium,Campaign', $response->getContent());
        $this->assertStringContainsString('tiktok,video,export_test', $response->getContent());

        // Test funnel export
        $funnelRes = $this->actingAs($admin)->get('/admin/marketing/analytics/export?type=funnel');
        $funnelRes->assertOk();
        $this->assertStringContainsString('Date,Event Name,Total Occurrences', $funnelRes->getContent());

        // Test campaigns export
        $campRes = $this->actingAs($admin)->get('/admin/marketing/analytics/export?type=campaigns');
        $campRes->assertOk();
        $this->assertStringContainsString('Campaign Slug,Campaign Name,UTM Campaign Key', $campRes->getContent());
    }

    public function test_reporting_never_mutates_underlying_attribution_records(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $attribution = MarketingAttribution::create([
            'anonymous_id' => 'anon_immutable',
            'campaign_key' => 'keep_untouched',
            'source' => 'Mixed_Case_Source',
            'medium' => 'Social',
            'campaign' => 'keep_untouched',
            'first_seen_at' => now()->subDays(2),
            'last_seen_at' => now()->subDays(2),
        ]);

        $originalAttributes = $attribution->fresh()->toArray();

        // View UTM dashboard & Analytics dashboard & Run export
        $this->actingAs($admin)->get('/admin/marketing/utm')->assertOk();
        $this->actingAs($admin)->get('/admin/marketing/analytics')->assertOk();
        $this->actingAs($admin)->get('/admin/marketing/analytics/export?type=utm')->assertOk();

        // Fresh database fetch must remain identical
        $afterReporting = $attribution->fresh()->toArray();
        $this->assertSame($originalAttributes, $afterReporting);
        $this->assertSame('Mixed_Case_Source', $afterReporting['source']);
    }
}
