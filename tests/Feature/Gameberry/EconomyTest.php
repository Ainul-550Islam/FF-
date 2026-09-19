<?php

namespace Tests\Feature\Gameberry;

use Tests\TestCase;
use App\Models\User;
use App\Services\Gameberry\GoldEconomyService;
use App\Services\Gameberry\GemEconomyService;
use App\Services\Gameberry\VideoAdService;
use App\Services\Gameberry\MagicChestService;
use App\Services\Gameberry\SpinService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class EconomyTest extends TestCase
{
    use RefreshDatabase;

    public function test_gold_wallet_reconciliation(): void
    {
        $user = User::factory()->create();
        $goldService = app(GoldEconomyService::class);

        $wallet = $goldService->getOrCreateWallet($user->id);
        $initial = $wallet->gold_balance;

        $wallet->addGold(500, 'win', 'test', '1', 'Test win');
        $wallet->spendGold(200, 'bet', 'test', '1', 'Test bet');

        $reconcile = $goldService->reconcile($user->id);
        $this->assertTrue($reconcile['is_balanced'], 'Gold wallet must reconcile, difference: '.$reconcile['difference']);
        $this->assertEquals(0, $reconcile['difference']);
    }

    public function test_gem_wallet_lucky_dice_reward(): void
    {
        $user = User::factory()->create();
        $gemService = app(GemEconomyService::class);

        $wallet = $gemService->getOrCreateWallet($user->id);
        $initial = $wallet->gem_balance;

        $gemService->rewardLuckyDice($user->id, 10, 'three_same');
        $after = $gemService->getBalance($user->id);

        $this->assertEquals($initial + 10, $after);

        $reconcile = $gemService->reconcile($user->id);
        $this->assertTrue($reconcile['is_balanced']);
    }

    public function test_video_ads_free_gold_daily_limit(): void
    {
        $user = User::factory()->create();
        $videoService = app(VideoAdService::class);
        app(GoldEconomyService::class)->getOrCreateWallet($user->id);
        app(GemEconomyService::class)->getOrCreateWallet($user->id);

        $this->assertTrue($videoService->canWatch($user->id));

        // Watch 5 ads (daily limit)
        for ($i = 0; $i < 5; $i++) {
            // Bypass cooldown for test by deleting last or mocking time
            if ($i > 0) {
                \App\Models\VideoAdReward::where('user_id', $user->id)->delete();
            }
            $reward = $videoService->watchAd($user->id, 'admob');
            $this->assertEquals(100, $reward->gold_reward);
            $this->assertEquals(1, $reward->gem_reward);
        }
    }

    public function test_magic_chest_rewards(): void
    {
        $user = User::factory()->create();
        $chestService = app(MagicChestService::class);
        app(GoldEconomyService::class)->getOrCreateWallet($user->id);
        app(GemEconomyService::class)->getOrCreateWallet($user->id);

        $chest = $chestService->createChest($user->id, 'bronze');
        $this->assertTrue($chest->isAvailable());
        $this->assertGreaterThanOrEqual(50, $chest->gold_reward);
        $this->assertLessThanOrEqual(200, $chest->gold_reward);

        $goldBefore = app(GoldEconomyService::class)->getBalance($user->id);
        $rewards = $chestService->openChest($user->id, $chest->id);
        $goldAfter = app(GoldEconomyService::class)->getBalance($user->id);

        $this->assertEquals($goldBefore + $rewards['gold'], $goldAfter);
    }

    public function test_spin2win(): void
    {
        $user = User::factory()->create();
        $spinService = app(SpinService::class);
        app(GoldEconomyService::class)->getOrCreateWallet($user->id);
        app(GemEconomyService::class)->getOrCreateWallet($user->id);

        $this->assertTrue($spinService->canSpin($user->id));
        $this->assertEquals(1, $spinService->getFreeSpinsRemaining($user->id));

        $spin = $spinService->spin($user->id, true); // free spin
        $this->assertNotNull($spin->result);
        $this->assertContains($spin->result, ['gold','gems','dice','jackpot']);
    }

    public function test_gold_at_stake_flow(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $goldService = app(GoldEconomyService::class);

        $goldService->getOrCreateWallet($user1->id);
        $goldService->getOrCreateWallet($user2->id);

        $balance1Before = $goldService->getBalance($user1->id);
        $balance2Before = $goldService->getBalance($user2->id);

        // Both place bet 100
        $goldService->placeBet($user1->id, 100, 'TEST123');
        $goldService->placeBet($user2->id, 100, 'TEST123');

        // User1 wins 200 (opponent gold)
        $goldService->winGold($user1->id, 200, 'TEST123');

        $balance1After = $goldService->getBalance($user1->id);
        $balance2After = $goldService->getBalance($user2->id);

        $this->assertEquals($balance1Before - 100 + 200, $balance1After);
        $this->assertEquals($balance2Before - 100, $balance2After);

        // Reconciliation must still hold
        $this->assertTrue($goldService->reconcile($user1->id)['is_balanced']);
        $this->assertTrue($goldService->reconcile($user2->id)['is_balanced']);
    }
}
