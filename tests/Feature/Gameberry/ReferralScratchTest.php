<?php

namespace Tests\Feature\Gameberry;

use Tests\TestCase;
use App\Models\User;
use App\Models\Referral;
use App\Models\ScratchCard;
use App\Services\Gameberry\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ReferralScratchTest extends TestCase
{
    use RefreshDatabase;

    protected ReferralService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ReferralService::class);
    }

    public function test_generate_bgi20_style_code(): void
    {
        $user = User::factory()->create(['name' => 'Bijoy']);
        $code = $this->service->generateReferralCode($user->id);

        $this->assertMatchesRegularExpression('/^[A-Z]{2,}[A-Z0-9]{3}20$/', $code);
        $this->assertStringEndsWith('20', $code);
        $this->assertDatabaseHas('referrals', ['referrer_id' => $user->id, 'code' => $code]);
    }

    public function test_referral_bgi20_rs_25_bonus(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create();

        app(\App\Services\Gameberry\GoldEconomyService::class)->getOrCreateWallet($referrer->id);
        app(\App\Services\Gameberry\GoldEconomyService::class)->getOrCreateWallet($referred->id);
        app(\App\Services\Gameberry\GemEconomyService::class)->getOrCreateWallet($referrer->id);
        app(\App\Services\Gameberry\GemEconomyService::class)->getOrCreateWallet($referred->id);

        $code = $this->service->generateReferralCode($referrer->id);

        $referrerGoldBefore = app(\App\Services\Gameberry\GoldEconomyService::class)->getBalance($referrer->id);
        $referredGoldBefore = app(\App\Services\Gameberry\GoldEconomyService::class)->getBalance($referred->id);

        $referral = $this->service->applyReferralCode($referred->id, $code);

        $referrerGoldAfter = app(\App\Services\Gameberry\GoldEconomyService::class)->getBalance($referrer->id);
        $referredGoldAfter = app(\App\Services\Gameberry\GoldEconomyService::class)->getBalance($referred->id);

        // Both should get ₹25 = 2500 minor
        $this->assertEquals($referrerGoldBefore + 2500, $referrerGoldAfter);
        $this->assertEquals($referredGoldBefore + 2500, $referredGoldAfter);
        $this->assertEquals('rewarded', $referral->status);
    }

    public function test_scratch_cards_created_on_referral(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create();

        app(\App\Services\Gameberry\GoldEconomyService::class)->getOrCreateWallet($referrer->id);
        app(\App\Services\Gameberry\GoldEconomyService::class)->getOrCreateWallet($referred->id);
        app(\App\Services\Gameberry\GemEconomyService::class)->getOrCreateWallet($referrer->id);
        app(\App\Services\Gameberry\GemEconomyService::class)->getOrCreateWallet($referred->id);

        $code = $this->service->generateReferralCode($referrer->id);
        $this->service->applyReferralCode($referred->id, $code);

        $this->assertDatabaseHas('scratch_cards', ['user_id' => $referrer->id, 'type' => 'referral']);
        $this->assertDatabaseHas('scratch_cards', ['user_id' => $referred->id, 'type' => 'referral']);
    }

    public function test_scratch_card_scratch_and_claim(): void
    {
        $user = User::factory()->create();
        app(\App\Services\Gameberry\GoldEconomyService::class)->getOrCreateWallet($user->id);
        app(\App\Services\Gameberry\GemEconomyService::class)->getOrCreateWallet($user->id);

        $card = ScratchCard::create([
            'user_id' => $user->id,
            'type' => 'referral',
            'reward_minor' => 1000,
            'reward_gems' => 5,
            'status' => 'unscratched',
            'expires_at' => now()->addDays(7),
        ]);

        $this->assertTrue($card->isUnscratched());

        $goldBefore = app(\App\Services\Gameberry\GoldEconomyService::class)->getBalance($user->id);
        $rewards = $card->scratch();

        app(\App\Services\Gameberry\GoldEconomyService::class)->getOrCreateWallet($user->id)->addGold($rewards['gold'], 'scratch_card', 'scratch_card', (string)$card->id, 'Scratch');
        app(\App\Services\Gameberry\GemEconomyService::class)->getOrCreateWallet($user->id)->addGems($rewards['gems'], 'scratch_card', 'scratch_card', (string)$card->id, 'Scratch');

        $card->claim();

        $goldAfter = app(\App\Services\Gameberry\GoldEconomyService::class)->getBalance($user->id);
        $this->assertEquals($goldBefore + 1000, $goldAfter);
        $this->assertEquals('claimed', $card->fresh()->status);
    }

    public function test_cannot_use_own_referral_code(): void
    {
        $user = User::factory()->create();
        $code = $this->service->generateReferralCode($user->id);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('own referral code');
        $this->service->applyReferralCode($user->id, $code);
    }
}
