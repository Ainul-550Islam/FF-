<?php

namespace Tests\Feature\Gameberry;

use Tests\TestCase;
use App\Models\User;
use App\Models\Dice;
use App\Models\UserDice;
use App\Services\Gameberry\DiceCollectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DiceCollectionTest extends TestCase
{
    use RefreshDatabase;

    protected DiceCollectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DiceCollectionService::class);
    }

    public function test_seed_250_dices(): void
    {
        $this->service->seedDefaultDices();
        $this->assertEquals(250, Dice::count());
        $this->assertEquals(52, Dice::where('is_lucky', true)->count());
    }

    public function test_user_can_collect_dice_max_52(): void
    {
        $user = User::factory()->create();
        $dice = Dice::factory()->create(['max_collection' => 52]);

        $userDice = $this->service->addDiceToUser($user->id, $dice->id, 10);
        $this->assertEquals(10, $userDice->quantity);

        $this->expectException(\Exception::class);
        $this->service->addDiceToUser($user->id, $dice->id, 43); // 10+43=53 >52
    }

    public function test_facebook_only_exchange_requires_2_dice(): void
    {
        $sender = User::factory()->create();
        $receiver = User::factory()->create();
        $dice = Dice::factory()->create();

        UserDice::create(['user_id' => $sender->id, 'dice_id' => $dice->id, 'quantity' => 1]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('at least 2');
        $this->service->exchangeDice($sender->id, $receiver->id, $dice->id);
    }

    public function test_user_collection_completion_percent(): void
    {
        $user = User::factory()->create();
        Dice::factory()->count(10)->create();
        $dice = Dice::first();
        UserDice::create(['user_id' => $user->id, 'dice_id' => $dice->id, 'quantity' => 1]);

        $collection = $this->service->getUserCollection($user->id);
        $this->assertEquals(10, $collection['total_dice_types']);
        $this->assertEquals(1, $collection['owned_types']);
        $this->assertEquals(10.0, $collection['completion_percent']);
    }

    public function test_equip_dice_unequips_others(): void
    {
        $user = User::factory()->create();
        $dice1 = Dice::factory()->create();
        $dice2 = Dice::factory()->create();
        UserDice::create(['user_id' => $user->id, 'dice_id' => $dice1->id, 'quantity' => 1, 'is_equipped' => true]);
        UserDice::create(['user_id' => $user->id, 'dice_id' => $dice2->id, 'quantity' => 1, 'is_equipped' => false]);

        $equipped = $this->service->equipDice($user->id, $dice2->id);
        $this->assertTrue($equipped->is_equipped);
        $this->assertEquals(1, UserDice::where('user_id', $user->id)->where('is_equipped', true)->count());
    }
}
