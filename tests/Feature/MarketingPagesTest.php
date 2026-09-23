<?php

namespace Tests\Feature;

use App\Models\MarketingCampaign;
use App\Models\MarketingEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 20 — trust & landing pages.
 *
 * /privacy /terms /faq /contact are public and indexable with canonical
 * URLs; campaign landings only render while live and every view is a
 * measured funnel moment.
 */
class MarketingPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_trust_pages_render_public_and_indexable(): void
    {
        foreach (['privacy' => 'Privacy Policy', 'terms' => 'Terms of Service', 'faq' => 'FAQ', 'contact' => 'Contact'] as $path => $titlePart) {
            $response = $this->get("/{$path}")->assertOk();

            $html = $response->getContent();
            $this->assertStringNotContainsString('noindex', $html, "/{$path} is a marketing surface — indexable.");
            $this->assertStringContainsString('rel="canonical"', $html);
        }
    }

    public function test_every_trust_page_has_a_canonical_url(): void
    {
        $this->get('/privacy')->assertOk()->assertSee('rel="canonical" href="'.route('marketing.privacy').'"', false);
        $this->get('/terms')->assertOk()->assertSee('rel="canonical" href="'.route('marketing.terms').'"', false);
    }

    public function test_campaign_landing_renders_when_live(): void
    {
        $campaign = $this->makeCampaign();

        $this->get("/campaign/{$campaign->slug}")
            ->assertOk()
            ->assertSee($campaign->headline)
            ->assertSee('rel="canonical"', false);

        $this->assertSame(1, MarketingEvent::query()->where('name', 'campaign_view')->count(), 'Every landing view is measured.');
    }

    public function test_an_inactive_campaign_is_a_404(): void
    {
        $campaign = $this->makeCampaign(['active' => false]);

        $this->get("/campaign/{$campaign->slug}")->assertStatus(404);
    }

    public function test_an_unknown_campaign_is_a_404(): void
    {
        $this->get('/campaign/nope-not-here')->assertStatus(404);
    }

    public function test_a_campaign_outside_its_window_is_a_404(): void
    {
        $expired = $this->makeCampaign(['ends_at' => now()->subDay()]);
        $scheduled = $this->makeCampaign(['starts_at' => now()->addDay()]);

        $this->get("/campaign/{$expired->slug}")->assertStatus(404);
        $this->get("/campaign/{$scheduled->slug}")->assertStatus(404);
    }

    public function test_the_landing_injects_the_campaign_key_for_attribution(): void
    {
        $campaign = $this->makeCampaign(['utm_campaign' => 'spring-launch']);

        $html = $this->get("/campaign/{$campaign->slug}")->getContent();

        $this->assertStringContainsString('spring-launch', $html, 'CTAs carry the utm_campaign key.');
    }

    public function test_the_landing_has_open_graph_share_tags(): void
    {
        $campaign = $this->makeCampaign();

        $html = $this->get("/campaign/{$campaign->slug}")->getContent();

        $this->assertStringContainsString('property="og:title"', $html);
        $this->assertStringContainsString('property="og:image"', $html);
    }

    public function test_trust_pages_carry_the_open_graph_default_image(): void
    {
        $html = $this->get('/privacy')->getContent();

        $this->assertStringContainsString('property="og:image"', $html);
        $this->assertStringContainsString('og-default', $html);
    }

    public function test_campaign_body_is_escaped(): void
    {
        $campaign = $this->makeCampaign(['headline' => '<script>alert("x")</script> Spring']);

        $html = $this->get("/campaign/{$campaign->slug}")->getContent();

        $this->assertStringNotContainsString('<script>alert(', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(', $html);
    }

    protected function makeCampaign(array $overrides = []): MarketingCampaign
    {
        return MarketingCampaign::create(array_merge([
            'slug' => 'spring-'.Str::random(6),
            'name' => 'Spring Launch',
            'headline' => 'Play this spring',
            'subheadline' => 'Bigger prize pools every week.',
            'body' => '<p>Join the arena this spring.</p>',
            'cta_label' => 'Join now',
            'cta_url' => '/tournaments',
            'active' => true,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ], $overrides));
    }
}
