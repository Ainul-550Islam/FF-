<?php

namespace Tests\Feature;

use App\Models\MarketingEvent;
use App\Models\MarketingExperiment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 21 — deterministic A/B assignment.
 *
 * The variant is a pure function of (experiment, identity): deterministic
 * per visitor, zero-allocation variants are never picked, exposure is
 * recorded exactly once and is decoupled from conversion, and the admin
 * surface stays behind the admin middleware.
 */
class MarketingExperimentTest extends TestCase
{
    use RefreshDatabase;

    protected function makeExperiment(): MarketingExperiment
    {
        $experiment = MarketingExperiment::create([
            'key' => 'cta-test',
            'name' => 'CTA test',
            'status' => 'running',
            'traffic_allocation' => 100,
            'starts_at' => now(),
        ]);

        $experiment->variants()->create(['key' => 'control', 'name' => 'Control', 'allocation' => 50]);
        $experiment->variants()->create(['key' => 'challenger', 'name' => 'Challenger', 'allocation' => 50]);

        return $experiment;
    }

    protected function assign(string $key, string $identity): ?string
    {
        // Send the visitor identity through the cookie jar: it encrypts the
        // value exactly like the attribution middleware does in production.
        // JSON requests skip the cookie jar by default (withCredentials).
        $this->withCookies(['ff_aid' => $identity]);
        $this->withCredentials();

        return $this->getJson("/marketing/experiments/{$key}/assign")
            ->assertOk()
            ->json('variant');
    }

    public function test_assignment_is_deterministic_per_visitor(): void
    {
        $this->makeExperiment();

        $first = $this->assign('cta-test', 'visitor-1');
        $again = $this->assign('cta-test', 'visitor-1');

        $this->assertNotNull($first);
        $this->assertSame($first, $again, 'The same visitor must always see the same variant.');
    }

    public function test_exposure_is_recorded_once(): void
    {
        $this->makeExperiment();

        $this->assign('cta-test', 'visitor-1');
        $this->assign('cta-test', 'visitor-1');
        $this->assign('cta-test', 'visitor-1');

        $this->assertSame(1, MarketingEvent::query()->where('name', 'experiment_exposure')->count());
    }

    public function test_a_zero_allocation_variant_is_never_assigned(): void
    {
        $experiment = $this->makeExperiment();
        $experiment->variants()->create(['key' => 'neverpick', 'name' => 'Never', 'allocation' => 0]);

        foreach (range(1, 12) as $i) {
            $variant = $this->assign('cta-test', 'identity-'.$i);

            if ($variant !== null) {
                $this->assertNotSame('neverpick', $variant);
            }
        }

        $this->assertSame(0, MarketingEvent::query()->where('properties->variant', 'neverpick')->count());
    }

    public function test_a_paused_experiment_assigns_nothing(): void
    {
        $experiment = $this->makeExperiment();
        $experiment->update(['status' => 'paused']);

        $this->assertNull($this->assign('cta-test', 'visitor-1'));
        $this->assertSame(0, MarketingEvent::query()->where('name', 'experiment_exposure')->count());
    }

    public function test_an_unknown_experiment_key_is_a_404(): void
    {
        $this->withCredentials();
        $this->getJson('/marketing/experiments/nope/assign')->assertStatus(404);

        $this->postJson('/marketing/experiments/nope/convert', ['conversion' => 'register_start'])
            ->assertStatus(404);
    }

    public function test_a_conversion_is_recorded_for_an_exposed_identity(): void
    {
        $this->makeExperiment();

        $this->assign('cta-test', 'visitor-1');

        $this->withCookies(['ff_aid' => 'visitor-1']);
        $this->withCredentials();

        $this->postJson('/marketing/experiments/cta-test/convert', [
            'conversion' => 'register_start',
        ])->assertOk()->assertJson(['ok' => true, 'recorded' => true]);

        $this->assertSame(1, MarketingEvent::query()->where('name', 'experiment_conversion')->count());
    }

    public function test_a_conversion_without_exposure_is_not_faked(): void
    {
        $this->makeExperiment();

        $this->withCookies(['ff_aid' => 'never-exposed']);
        $this->withCredentials();

        $this->postJson('/marketing/experiments/cta-test/convert', [
            'conversion' => 'register_start',
        ])->assertOk()->assertJson(['ok' => true, 'recorded' => false]);

        $this->assertSame(0, MarketingEvent::query()->where('name', 'experiment_conversion')->count());
    }

    public function test_authenticated_visitors_dedupe_by_user_id(): void
    {
        $this->makeExperiment();
        $user = User::factory()->create();
        $identity = 'u:'.$user->id;

        // The controller derives the identity from the authenticated user —
        // the same account is exposed once even across different browsers.
        $this->actingAs($user)->withCredentials();
        $first = $this->getJson('/marketing/experiments/cta-test/assign')->assertOk()->json('variant');
        $again = $this->getJson('/marketing/experiments/cta-test/assign')->assertOk()->json('variant');

        $this->assertSame($first, $again);
        $this->assertNotNull($first);
        $this->assertSame(1, MarketingEvent::query()
            ->where('name', 'experiment_exposure')
            ->where('properties->identity', $identity)
            ->count(), 'User identities are exposed once too.');
    }

    public function test_an_admin_can_create_an_experiment_that_starts_running(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/admin/marketing/experiments', [
            'key' => 'pricing-page',
            'name' => 'Pricing page test',
            'traffic_allocation' => 100,
            'variants' => [
                ['key' => 'control', 'name' => 'Control', 'allocation' => 50],
                ['key' => 'new', 'name' => 'New pricing', 'allocation' => 50],
            ],
        ])->assertRedirect()->assertSessionHas('success');

        $experiment = MarketingExperiment::query()->where('key', 'pricing-page')->first();
        $this->assertNotNull($experiment);
        $this->assertSame('running', $experiment->status);
        $this->assertSame(2, $experiment->variants()->count());
    }

    public function test_only_admins_can_manage_experiments(): void
    {
        $player = User::factory()->create(['role' => 'player']);

        // Guests get bounced to login; players get 403 — the admin surface
        // is never public.
        $this->post('/admin/marketing/experiments', ['key' => 'x'])->assertRedirect(route('login'));
        $this->actingAs($player)->post('/admin/marketing/experiments', ['key' => 'x'])->assertStatus(403);
        $this->actingAs($player)->get('/admin/marketing/experiments')->assertStatus(403);
        $this->assertSame(0, MarketingExperiment::query()->count());
    }
}
