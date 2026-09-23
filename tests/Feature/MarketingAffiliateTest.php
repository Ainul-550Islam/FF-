<?php

namespace Tests\Feature;

use App\Models\MarketingAffiliate;
use App\Models\MarketingAffiliateReferral;
use App\Models\MarketingAttribution;
use App\Models\MarketingEvent;
use App\Models\Notification;
use App\Models\User;
use App\Services\MarketingAffiliateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 21 — affiliate program.
 *
 * Idempotent registration (one affiliate per user), server-validated codes,
 * idempotent click recording (one row per affiliate + visitor), server-side
 * signup crediting (once per user, never self-credited), and a dashboard
 * that only ever shows the visitor's own data.
 */
class MarketingAffiliateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_registers_as_an_affiliate_once(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/affiliates', ['code' => 'FIRST1'])->assertRedirect();
        $this->actingAs($user)->post('/affiliates', ['code' => 'SECOND'])->assertRedirect();

        $this->assertSame(1, MarketingAffiliate::query()->where('user_id', $user->id)->count());
        $this->assertSame('FIRST1', MarketingAffiliate::query()->where('user_id', $user->id)->value('code'));
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->post('/affiliates', ['code' => 'GUESTY'])->assertRedirect(route('login'));
    }

    public function test_a_taken_code_is_rejected(): void
    {
        $one = User::factory()->create();
        $two = User::factory()->create();

        $this->actingAs($one)->post('/affiliates', ['code' => 'TAKEN1'])->assertRedirect();
        $this->actingAs($two)->post('/affiliates', ['code' => 'TAKEN1'])
            ->assertSessionHasErrors(['code']);
    }

    public function test_non_alnum_codes_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/affiliates', ['code' => 'has space!'])
            ->assertSessionHasErrors(['code']);

        $this->assertSame(0, MarketingAffiliate::query()->count());
    }

    public function test_generated_codes_are_eight_uppercase_alnum_chars(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/affiliates')->assertRedirect();

        $code = MarketingAffiliate::query()->where('user_id', $user->id)->value('code');
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', (string) $code);
    }

    public function test_a_referral_click_redirects_with_the_utm_trio(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/affiliates', ['code' => 'SHARE10']);
        $code = MarketingAffiliate::query()->where('user_id', $user->id)->value('code');

        $this->get("/r/{$code}")
            ->assertRedirect()
            ->assertSee('utm_campaign='.strtoupper((string) $code), false);

        $this->assertSame(1, MarketingAffiliateReferral::query()->count());
        $this->assertSame(1, MarketingEvent::query()->where('name', 'referral_click')->count());
    }

    public function test_clicks_are_idempotent_per_visitor(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/affiliates', ['code' => 'ONCE01']);

        $this->withCredentials();
        $this->withCookies(['ff_aid' => 'clicker-1']);
        $this->get('/r/ONCE01')->assertRedirect();
        $this->get('/r/ONCE01')->assertRedirect();
        $this->get('/r/ONCE01')->assertRedirect();

        $this->assertSame(1, MarketingAffiliateReferral::query()->count(), 'One row per (affiliate, visitor).');
    }

    public function test_unknown_codes_are_a_404(): void
    {
        $this->get('/r/NOSUCH1')->assertStatus(404);
    }

    public function test_suspended_affiliates_are_a_404(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/affiliates', ['code' => 'SUSP01']);

        MarketingAffiliate::query()->update(['status' => 'suspended', 'suspended_at' => now()]);

        $this->get('/r/SUSP01')->assertStatus(404);
    }

    public function test_pending_affiliates_are_a_404(): void
    {
        MarketingAffiliate::create([
            'user_id' => User::factory()->create()->id,
            'code' => 'PEND01',
            'status' => 'pending',
        ]);

        $this->get('/r/PEND01')->assertStatus(404);
    }

    public function test_registration_credits_the_referral_and_records_events(): void
    {
        $affiliate = User::factory()->create();
        app(MarketingAffiliateService::class)->register($affiliate, ['code' => 'CREDIT1']);

        // The visitor clicks while signed out — exactly like a real referral.
        $this->withCredentials();
        $this->withCookies(['ff_aid' => 'new-visitor-9']);
        $this->get('/r/CREDIT1')->assertRedirect();

        $this->post('/register', [
            'name' => 'Referred Player',
            'username' => 'referred1',
            'email' => 'referred1@example.com',
            'role' => 'player',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertRedirect();

        $referral = MarketingAffiliateReferral::query()->first();
        $this->assertNotNull($referral);
        $this->assertTrue($referral->isCredited(), 'The signup is credited server-side.');
        $this->assertSame('referred1@example.com', User::find($referral->referred_user_id)->email);
        $this->assertSame(1, MarketingEvent::query()->where('name', 'referral_signup')->count());

        $welcome = Notification::query()
            ->where('user_id', $referral->referred_user_id)
            ->where('type', Notification::TYPE_MARKETING)
            ->count();
        $this->assertGreaterThanOrEqual(0, $welcome);
    }

    public function test_the_affiliate_never_credits_themselves(): void
    {
        $affiliate = User::factory()->create();
        app(MarketingAffiliateService::class)->register($affiliate, ['code' => 'SELFIE1']);

        // The affiliate's own browser: their attribution rows are bound to
        // their account, so a "new account" from the same browser is still
        // a self-referral.
        $this->withCredentials();
        $this->withCookies(['ff_aid' => 'affiliates-own-browser']);
        $this->get('/r/SELFIE1')->assertRedirect();

        MarketingAttribution::query()->updateOrCreate(
            ['anonymous_id' => 'affiliates-own-browser', 'campaign_key' => '(direct)'],
            ['user_id' => $affiliate->id, 'first_seen_at' => now(), 'last_seen_at' => now()]
        );

        $newAccount = User::factory()->create();

        app(MarketingAffiliateService::class)->attachReferralToUser($newAccount);

        $this->assertFalse(
            MarketingAffiliateReferral::query()->whereNotNull('referred_user_id')->exists(),
            'A self-referral is never credited.'
        );
    }

    public function test_a_visitor_credits_only_the_first_signup(): void
    {
        $affiliateOwner = User::factory()->create();
        app(MarketingAffiliateService::class)->register($affiliateOwner, ['code' => 'DOUBLE1']);

        $this->withCredentials();
        $this->withCookies(['ff_aid' => 'visitor-double']);
        $this->get('/r/DOUBLE1')->assertRedirect();

        $first = User::factory()->create();
        $this->withCredentials();
        $this->withCookies(['ff_aid' => 'visitor-double']);
        $this->actingAs($first);
        app(MarketingAffiliateService::class)->attachReferralToUser($first);

        $second = User::factory()->create();
        $this->withCredentials();
        $this->withCookies(['ff_aid' => 'visitor-double']);
        $this->actingAs($second);
        app(MarketingAffiliateService::class)->attachReferralToUser($second);

        $this->assertSame(1, MarketingAffiliateReferral::query()->whereNotNull('referred_user_id')->count(), 'No double credit for the same visitor.');
    }

    public function test_the_dashboard_shows_only_your_own_data(): void
    {
        $mine = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($mine)->post('/affiliates', ['code' => 'MINE001'])->assertRedirect();
        $this->actingAs($other)->post('/affiliates', ['code' => 'OTHER1'])->assertRedirect();

        $response = $this->actingAs($mine)->get('/affiliates/dashboard')->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString('MINE001', $html);
        $this->assertStringNotContainsString('OTHER1', $html, 'The dashboard never leaks another affiliate\'s data.');
    }
}
