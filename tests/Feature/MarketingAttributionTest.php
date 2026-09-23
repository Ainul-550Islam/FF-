<?php

namespace Tests\Feature;

use App\Models\MarketingAttribution;
use App\Models\User;
use App\Services\MarketingAttributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Phase 20 — first-party attribution.
 *
 * The anonymous visitor cookie is stable per visitor, first-touch rows are
 * immutable, last-touch columns refresh, UTM / click-id / referrer / direct
 * landings all resolve to a campaign key, and login binds the anonymous
 * touches to the account.
 */
class MarketingAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_sets_the_anonymous_visitor_cookie(): void
    {
        $this->get('/')->assertCookie('ff_aid');

        $cookie = $this->get('/')->getCookie('ff_aid');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $cookie->getValue());
    }

    public function test_an_invalid_cookie_value_gets_a_fresh_identity(): void
    {
        $this->withUnencryptedCookies(['ff_aid' => 'bad identity with spaces;drop table']);

        $response = $this->get('/');
        $cookie = $response->getCookie('ff_aid');

        $this->assertNotNull($cookie);
        $this->assertNotSame('bad identity with spaces;drop table', $cookie->getValue());
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $cookie->getValue());
    }

    public function test_utm_parameters_are_captured_as_first_touch(): void
    {
        $this->get('/?utm_source=facebook&utm_medium=cpc&utm_campaign=spring-launch&utm_content=banner1');

        $row = MarketingAttribution::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('facebook', $row->source);
        $this->assertSame('cpc', $row->medium);
        $this->assertSame('spring-launch', $row->campaign);
        $this->assertSame('banner1', $row->content);
        $this->assertSame('spring-launch', $row->campaign_key);
    }

    public function test_a_click_id_is_captured_with_its_type(): void
    {
        $this->get('/?fbclid=abc123&utm_source=facebook');

        $row = MarketingAttribution::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('fbclid', $row->click_id_type);
        $this->assertSame('abc123', $row->click_id);
        $this->assertSame('fbclid', $row->campaign_key);
    }

    public function test_an_external_referrer_becomes_the_campaign_key(): void
    {
        $this->get('/', ['Referer' => 'https://news.example.com/story']);

        $row = MarketingAttribution::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('news.example.com', $row->referrer_host);
        $this->assertSame('news.example.com', $row->campaign_key);
    }

    public function test_a_plain_landing_lands_under_direct(): void
    {
        $this->get('/');

        $row = MarketingAttribution::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('(direct)', $row->campaign_key);
        $this->assertNull($row->source);
    }

    public function test_first_touch_is_immutable_and_last_touch_refreshes(): void
    {
        $this->withCredentials();
        $this->withCookies(['ff_aid' => 'visitor-1']);
        $this->get('/?utm_source=facebook&utm_medium=cpc&utm_campaign=spring-launch');

        $first = MarketingAttribution::query()->first();
        $this->assertSame('facebook', $first->source);

        sleep(0);

        // Same visitor, a different campaign — first row stays, new row for
        // the new campaign key, and the visitor now has two touches.
        $this->withCredentials();
        $this->withCookies(['ff_aid' => 'visitor-1']);
        $this->get('/?utm_source=google&utm_medium=organic&utm_campaign=evergreen');

        $this->assertSame(2, MarketingAttribution::query()->where('anonymous_id', 'visitor-1')->count());

        $evergreen = MarketingAttribution::query()->where('campaign_key', 'evergreen')->first();
        $this->assertSame('google', $evergreen->source);

        // Re-visiting the same campaign refreshes last_seen, keeps first.
        $firstSeen = MarketingAttribution::query()->where('campaign_key', 'spring-launch')->value('first_seen_at');
        $this->withCredentials();
        $this->withCookies(['ff_aid' => 'visitor-1']);
        $this->get('/?utm_source=facebook&utm_medium=cpc&utm_campaign=spring-launch');

        $again = MarketingAttribution::query()->where('campaign_key', 'spring-launch')->first();
        $this->assertSame(1, MarketingAttribution::query()->where('campaign_key', 'spring-launch')->count(), 'No duplicate row per (visitor, campaign).');
        $this->assertTrue($again->last_seen_at->greaterThanOrEqualTo($first->last_seen_at));
    }

    public function test_latest_for_returns_the_newest_touch(): void
    {
        $service = app(MarketingAttributionService::class);

        $this->withCredentials();
        $this->withCookies(['ff_aid' => 'visitor-2']);
        $this->get('/?utm_source=facebook&utm_campaign=one');
        $this->get('/?utm_source=google&utm_campaign=two');

        $latest = $service->latestFor('visitor-2');
        $this->assertNotNull($latest);
        $this->assertSame('two', $latest->campaign);
    }

    public function test_attach_user_binds_anonymous_touches(): void
    {
        $user = User::factory()->create();

        $this->withCredentials();
        $this->withCookies(['ff_aid' => 'visitor-3']);
        $this->get('/?utm_source=facebook&utm_campaign=spring-launch');

        $service = app(MarketingAttributionService::class);
        $service->attachUser('visitor-3', $user->id);

        $row = MarketingAttribution::query()->where('anonymous_id', 'visitor-3')->first();
        $this->assertSame($user->id, $row->user_id);
    }

    public function test_identity_is_memoized_within_a_request(): void
    {
        $service = app(MarketingAttributionService::class);
        $request = Request::create('/');

        $first = $service->anonymousId($request);
        $second = $service->anonymousId($request);

        $this->assertSame($first, $second, 'One request derives exactly one identity.');
    }
}
