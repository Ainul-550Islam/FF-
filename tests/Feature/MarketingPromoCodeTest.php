<?php

namespace Tests\Feature;

use App\Models\MarketingPromoCode;
use App\Models\MarketingPromoRedemption;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 21 — promo code application.
 *
 * The server owns every number: discounts are recomputed from the
 * tournament's own entry fee (client prices are never trusted), fixed
 * discounts cap at the fee, eligibility is server-side, and repeat
 * application is an idempotent duplicate instead of an error.
 */
class MarketingPromoCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function applyRoute(Tournament $tournament): string
    {
        return route('marketing.promo.apply').'?tournament_id='.$tournament->id;
    }

    protected function makeTournament(int $feeMinor): Tournament
    {
        $organizer = User::factory()->create(['role' => 'organizer']);
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = 'Promo Tournament';
        $t->slug = 'promo-'.Str::random(8);
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = $feeMinor / 100; // major units on the column
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->starts_at = now()->addDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = 'open';
        $t->save();

        return $t;
    }

    public function test_a_percent_discount_is_computed_from_the_server_fee(): void
    {
        $user = User::factory()->create();
        $tournament = $this->makeTournament(10000); // 100.00 BDT

        MarketingPromoCode::create([
            'code' => 'SPRING20', 'type' => 'percent', 'value' => 20, 'active' => true,
        ]);

        // A lying client price is ignored — only the code + tournament count.
        $this->actingAs($user)
            ->postJson('/marketing/promo/apply', [
                'code' => 'spring20', // case-insensitive
                'tournament_id' => $tournament->id,
                'price_minor' => 1, // spoofed — must be ignored
                'discount_minor' => 9999,
            ])->assertOk()
            ->assertJson(['ok' => true, 'duplicate' => false, 'discount_minor' => 2000]);

        $this->assertSame(2000, MarketingPromoRedemption::query()->value('discount_minor'));
    }

    public function test_a_fixed_discount_caps_at_the_fee(): void
    {
        $user = User::factory()->create();
        $tournament = $this->makeTournament(5000); // 50.00

        MarketingPromoCode::create([
            'code' => 'BIG100', 'type' => 'fixed', 'value' => 10000, 'active' => true, // 100.00 off
        ]);

        $this->actingAs($user)
            ->postJson('/marketing/promo/apply', ['code' => 'BIG100', 'tournament_id' => $tournament->id])
            ->assertOk()
            ->assertJsonPath('discount_minor', 5000, 'A promo never pays the player.');
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $user = User::factory()->create();
        $tournament = $this->makeTournament(10000);

        MarketingPromoCode::create([
            'code' => 'OLDCODE', 'type' => 'percent', 'value' => 50, 'active' => true,
            'ends_at' => now()->subDay(),
        ]);

        $this->actingAs($user)
            ->postJson('/marketing/promo/apply', ['code' => 'OLDCODE', 'tournament_id' => $tournament->id])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);
    }

    public function test_an_inactive_code_is_rejected(): void
    {
        $user = User::factory()->create();
        $tournament = $this->makeTournament(10000);

        MarketingPromoCode::create([
            'code' => 'PAUSED1', 'type' => 'percent', 'value' => 10, 'active' => false,
        ]);

        $this->actingAs($user)
            ->postJson('/marketing/promo/apply', ['code' => 'PAUSED1', 'tournament_id' => $tournament->id])
            ->assertStatus(422);
    }

    public function test_the_global_redemption_cap_is_enforced(): void
    {
        $user = User::factory()->create();
        $tournament = $this->makeTournament(10000);

        MarketingPromoCode::create([
            'code' => 'LIMITED', 'type' => 'percent', 'value' => 10, 'active' => true,
            'max_redemptions' => 1,
        ]);

        // Someone else took the only redemption.
        MarketingPromoRedemption::create([
            'promo_code_id' => MarketingPromoCode::query()->value('id'),
            'user_id' => User::factory()->create()->id,
            'tournament_id' => $tournament->id,
            'discount_minor' => 1000,
        ]);

        $this->actingAs($user)
            ->postJson('/marketing/promo/apply', ['code' => 'LIMITED', 'tournament_id' => $tournament->id])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);
    }

    public function test_a_minimum_fee_gate_uses_the_server_fee(): void
    {
        $user = User::factory()->create();
        $cheap = $this->makeTournament(2000); // 20.00 — below the minimum

        MarketingPromoCode::create([
            'code' => 'BIGSPEND', 'type' => 'percent', 'value' => 10, 'active' => true,
            'min_entry_fee_minor' => 5000,
        ]);

        $this->actingAs($user)
            ->postJson('/marketing/promo/apply', ['code' => 'BIGSPEND', 'tournament_id' => $cheap->id])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);
    }

    public function test_repeating_an_apply_is_an_idempotent_duplicate(): void
    {
        $user = User::factory()->create();
        $tournament = $this->makeTournament(10000);

        MarketingPromoCode::create([
            'code' => 'AGAIN20', 'type' => 'percent', 'value' => 20, 'active' => true,
        ]);

        $first = $this->actingAs($user)
            ->postJson('/marketing/promo/apply', ['code' => 'AGAIN20', 'tournament_id' => $tournament->id])
            ->assertOk()->json();

        // The window closes after the first apply — the repeat still succeeds
        // as a duplicate with the STORED discount.
        $code = MarketingPromoCode::query()->where('code', 'AGAIN20')->first();
        $code->ends_at = now()->subHour();
        $code->save();

        $second = $this->actingAs($user)
            ->postJson('/marketing/promo/apply', ['code' => 'AGAIN20', 'tournament_id' => $tournament->id])
            ->assertOk()->json();

        $this->assertFalse($first['duplicate']);
        $this->assertTrue($second['duplicate'], 'A repeat apply is a duplicate, not an error.');
        $this->assertSame($first['discount_minor'], $second['discount_minor']);
        $this->assertSame($first['redemption_id'], $second['redemption_id']);
        $this->assertSame(1, MarketingPromoRedemption::query()->count());
    }

    public function test_an_unknown_code_is_rejected(): void
    {
        $user = User::factory()->create();
        $tournament = $this->makeTournament(10000);

        $this->actingAs($user)
            ->postJson('/marketing/promo/apply', ['code' => 'NOPE999', 'tournament_id' => $tournament->id])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);
    }

    public function test_guests_cannot_apply(): void
    {
        $tournament = $this->makeTournament(10000);

        $this->postJson('/marketing/promo/apply', ['code' => 'SPRING20', 'tournament_id' => $tournament->id])
            ->assertStatus(401);
    }

    public function test_a_missing_code_is_a_validation_error(): void
    {
        $user = User::factory()->create();
        $tournament = $this->makeTournament(10000);

        $this->actingAs($user)
            ->postJson('/marketing/promo/apply', ['tournament_id' => $tournament->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }
}
