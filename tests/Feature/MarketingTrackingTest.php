<?php

namespace Tests\Feature;

use App\Models\MarketingAttribution;
use App\Models\MarketingConsent;
use App\Models\MarketingEvent;
use App\Models\User;
use App\Services\MarketingTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 20 — conversion events (§40 taxonomy) + consent ledger.
 *
 * Client events are strictly validated against the taxonomy, consent
 * decisions are an append-only ledger echoed back as a cookie, and
 * server-side conversions (registration, payment) record without client JS.
 */
class MarketingTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_valid_event_is_recorded(): void
    {
        $this->postJson('/marketing/event', [
            'name' => 'tournament_view',
            'properties' => ['slug' => 'euro-cup'],
        ])->assertOk()->assertJson(['ok' => true]);

        $event = MarketingEvent::query()->first();
        $this->assertNotNull($event);
        $this->assertSame('tournament_view', $event->name);
        $this->assertSame('euro-cup', $event->properties['slug']);
        $this->assertNotNull($event->anonymous_id);
    }

    public function test_an_unknown_event_name_is_rejected(): void
    {
        $this->postJson('/marketing/event', ['name' => 'free_money'])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->assertSame(0, MarketingEvent::query()->count(), 'The funnel is a contract, not a free-form log.');
    }

    public function test_oversized_property_bags_are_rejected(): void
    {
        $properties = [];
        foreach (range(1, 25) as $i) {
            $properties["key{$i}"] = $i;
        }

        $this->postJson('/marketing/event', ['name' => 'page_view', 'properties' => $properties])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);
    }

    public function test_non_object_properties_are_rejected(): void
    {
        $this->postJson('/marketing/event', ['name' => 'page_view', 'properties' => 'not-an-object'])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);
    }

    public function test_the_visitor_cookie_identity_flows_into_events(): void
    {
        $this->withCredentials();
        $this->withCookies(['ff_aid' => 'visitor-tracked-1']);

        $this->postJson('/marketing/event', ['name' => 'share_click'])->assertOk();

        $event = MarketingEvent::query()->first();
        $this->assertSame('visitor-tracked-1', $event->anonymous_id);
    }

    public function test_consent_grant_writes_a_ledger_row_and_cookie(): void
    {
        $response = $this->postJson('/marketing/consent', ['analytics' => true, 'marketing' => true])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertNotNull($response->getCookie('ff_consent', false)); // plaintext consent cookie — no decrypt

        $row = MarketingConsent::query()->first();
        $this->assertNotNull($row);
        $this->assertTrue($row->analytics_consent);
        $this->assertTrue($row->marketing_consent);
        $this->assertNotNull($row->granted_at);
        $this->assertNull($row->withdrawn_at);
    }

    public function test_consent_withdraw_is_appended_and_echoed(): void
    {
        $this->postJson('/marketing/consent', ['analytics' => true, 'marketing' => true])->assertOk();
        $this->postJson('/marketing/consent', ['analytics' => false, 'marketing' => false, 'withdraw' => true])
            ->assertOk()
            ->assertJsonPath('consent.withdrawn', true);

        $this->assertSame(2, MarketingConsent::query()->count(), 'Consent history is append-only.');

        $latest = MarketingConsent::query()->orderByDesc('id')->first();
        $this->assertFalse($latest->marketing_consent);
        $this->assertNotNull($latest->withdrawn_at);
    }

    public function test_consent_validation_requires_booleans(): void
    {
        $this->postJson('/marketing/consent', ['analytics' => 'yes-please'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['analytics']);
    }

    public function test_server_side_conversion_records_without_client_js(): void
    {
        $user = User::factory()->create();

        app(MarketingTrackingService::class)->recordConversion('register_complete', $user, ['source' => 'organic']);

        $event = MarketingEvent::query()->first();
        $this->assertNotNull($event);
        $this->assertSame('register_complete', $event->name);
        $this->assertSame($user->id, $event->user_id);
    }

    public function test_attach_visitor_links_events_and_attribution_to_the_account(): void
    {
        $user = User::factory()->create();

        $this->withCredentials();
        $this->withCookies(['ff_aid' => 'visitor-attach-1']);
        $this->postJson('/marketing/event', ['name' => 'page_view'])->assertOk();

        $this->withCredentials();
        $this->withCookies(['ff_aid' => 'visitor-attach-1']);
        $this->get('/?utm_source=facebook&utm_campaign=spring-launch');

        app(MarketingTrackingService::class)->attachVisitor($user);

        $this->assertSame(1, MarketingEvent::query()->whereNotNull('user_id')->count());
        // Two attribution touches were captured for this visitor (the event
        // POST landing + the utm landing) — both belong to the account now.
        $this->assertSame(2, MarketingAttribution::query()->where('user_id', $user->id)->count());
    }

    public function test_events_can_be_disabled_by_config(): void
    {
        config(['marketing.events.enabled' => false]);

        $this->postJson('/marketing/event', ['name' => 'page_view'])->assertOk();
        $this->assertSame(0, MarketingEvent::query()->count());
    }
}
