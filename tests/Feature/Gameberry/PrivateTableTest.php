<?php

namespace Tests\Feature\Gameberry;

use Tests\TestCase;
use App\Models\User;
use App\Models\PrivateTable;
use App\Services\Gameberry\PrivateTableService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PrivateTableTest extends TestCase
{
    use RefreshDatabase;

    protected PrivateTableService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PrivateTableService::class);
    }

    public function test_create_private_table_with_code_and_link(): void
    {
        $host = User::factory()->create();
        $table = $this->service->createTable($host->id, ['game_mode' => 'classic', 'bet_amount' => 100]);

        $this->assertNotNull($table->code);
        $this->assertEquals(6, strlen($table->code));
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{6}$/', $table->code);
        $this->assertNotNull($table->link);
        $this->assertStringContainsString($table->code, $table->link);
        $this->assertEquals('waiting', $table->status);
    }

    public function test_join_table_deducts_gold_at_stake(): void
    {
        $host = User::factory()->create();
        $player = User::factory()->create();

        // Create wallets
        app(\App\Services\Gameberry\GoldEconomyService::class)->getOrCreateWallet($host->id);
        app(\App\Services\Gameberry\GoldEconomyService::class)->getOrCreateWallet($player->id);

        $table = $this->service->createTable($host->id, ['game_mode' => 'quick', 'bet_amount' => 100]);

        $initialGold = app(\App\Services\Gameberry\GoldEconomyService::class)->getBalance($player->id);
        $participant = $this->service->joinTable($player->id, $table->code);
        $afterGold = app(\App\Services\Gameberry\GoldEconomyService::class)->getBalance($player->id);

        $this->assertEquals($initialGold - 100, $afterGold);
        $this->assertEquals($player->id, $participant->user_id);
    }

    public function test_team_up_mode_assigns_teams(): void
    {
        $host = User::factory()->create();
        $table = $this->service->createTable($host->id, ['game_mode' => 'team_up', 'bet_amount' => 100, 'is_team_up' => true]);

        $this->assertTrue($table->is_team_up);
        $this->assertEquals(4, $table->max_players);
    }

    public function test_auto_mode_on_disconnect(): void
    {
        $host = User::factory()->create();
        $player = User::factory()->create();
        app(\App\Services\Gameberry\GoldEconomyService::class)->getOrCreateWallet($player->id);

        $table = $this->service->createTable($host->id, ['game_mode' => 'classic', 'bet_amount' => 100]);
        $this->service->joinTable($player->id, $table->code);

        $this->service->setAutoMode($player->id, $table->code, true, 'disconnect');

        $participant = \App\Models\PrivateTableParticipant::where('private_table_id', $table->id)->where('user_id', $player->id)->first();
        $this->assertTrue($participant->is_in_auto_mode);
        $this->assertNotNull($participant->auto_mode_on_at);

        $log = \App\Models\AutoModeLog::where('user_id', $player->id)->where('private_table_id', $table->id)->first();
        $this->assertNotNull($log);
        $this->assertEquals('disconnect', $log->reason);
    }

    public function test_challenge_button(): void
    {
        $challenger = User::factory()->create();
        $challenged = User::factory()->create();

        $challenge = $this->service->challengeFriend($challenger->id, $challenged->id, 'private_table', 100);
        $this->assertEquals('pending', $challenge->status);
        $this->assertEquals($challenger->id, $challenge->challenger_id);
        $this->assertEquals($challenged->id, $challenge->challenged_id);
    }

    public function test_game_variations_classic_master_quick(): void
    {
        $host = User::factory()->create();
        $classic = $this->service->createTable($host->id, ['game_mode' => 'classic', 'bet_amount' => 100]);
        $master = $this->service->createTable($host->id, ['game_mode' => 'master', 'bet_amount' => 200]);
        $quick = $this->service->createTable($host->id, ['game_mode' => 'quick', 'bet_amount' => 50]);

        $this->assertEquals(4, $classic->max_players);
        $this->assertEquals(4, $master->max_players);
        $this->assertEquals(2, $quick->max_players);
    }
}
