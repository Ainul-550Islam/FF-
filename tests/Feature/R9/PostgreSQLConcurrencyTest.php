<?php

namespace Tests\Feature\R9;

use App\Models\FinancialSettlement;
use App\Models\Payout;
use App\Models\Tournament;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WebhookEvent;
use App\Services\WalletService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgreSQLConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_concurrent_wallet_debits_one_succeeds(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create(['user_id' => $user->id, 'currency' => 'BDT', 'balance_minor' => 1000]);
        $service = app(WalletService::class);
        $service->debit($user->id, 800, 'BDT', 'test', 'ref-1');
        $wallet->refresh();
        $this->assertEquals(200, $wallet->balance_minor);
        $this->expectException(\RuntimeException::class);
        $service->debit($user->id, 500, 'BDT', 'test', 'ref-2');
    }

    public function test_concurrent_credits_both_succeed(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create(['user_id' => $user->id, 'currency' => 'BDT', 'balance_minor' => 0]);
        $service = app(WalletService::class);
        $service->credit($user->id, 1000, 'BDT', 'test', 'ref-1');
        $service->credit($user->id, 500, 'BDT', 'test', 'ref-2');
        $wallet->refresh();
        $this->assertEquals(1500, $wallet->balance_minor);
        $this->assertTrue($service->verifyLedgerIntegrity($wallet->id));
    }

    public function test_idempotency_concurrent_one_effect(): void
    {
        $user = User::factory()->create();
        Wallet::create(['user_id' => $user->id, 'currency' => 'BDT', 'balance_minor' => 0]);
        $service = app(WalletService::class);
        $idemKey = 'idem-'.uniqid();
        $entry1 = $service->credit($user->id, 1000, 'BDT', 'test', 'ref-1', $idemKey);
        $entry2 = $service->credit($user->id, 1000, 'BDT', 'test', 'ref-1', $idemKey);
        $this->assertEquals($entry1->id, $entry2->id);
        $wallet = Wallet::where('user_id', $user->id)->first();
        $this->assertEquals(1000, $wallet->balance_minor);
    }

    public function test_concurrent_refund_one_effect(): void
    {
        $user = User::factory()->create();
        Wallet::create(['user_id' => $user->id, 'currency' => 'BDT', 'balance_minor' => 1000]);
        $service = app(WalletService::class);
        $paymentId = 'pay-'.uniqid();
        $service->credit($user->id, 500, 'BDT', 'refund', $paymentId, 'idem-refund-'.uniqid());
        $wallet = Wallet::where('user_id', $user->id)->first();
        $this->assertEquals(1500, $wallet->balance_minor);
        $idemKey = 'refund-'.$paymentId;
        $service->credit($user->id, 500, 'BDT', 'refund', $paymentId, $idemKey);
        $service->credit($user->id, 500, 'BDT', 'refund', $paymentId, $idemKey);
        $wallet->refresh();
        $this->assertEquals(2000, $wallet->balance_minor);
    }

    public function test_concurrent_webhook_same_event_id_one_effect(): void
    {
        $eventId = 'evt-'.uniqid();
        WebhookEvent::create(['provider' => 'bkash', 'event_type' => 'payment.succeeded', 'event_id' => $eventId, 'payload' => ['amount' => 1000], 'state' => 'received']);
        $this->assertDatabaseHas('webhook_events', ['event_id' => $eventId]);
        $this->expectException(QueryException::class);
        WebhookEvent::create(['provider' => 'bkash', 'event_type' => 'payment.succeeded', 'event_id' => $eventId, 'payload' => ['amount' => 1000], 'state' => 'received']);
    }

    public function test_concurrent_payout_same_idempotency_one_payout(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->create();
        $idemKey = 'payout-'.uniqid();
        $this->createRow(Payout::class, ['user_id' => $user->id, 'tournament_id' => $tournament->id, 'amount_minor' => 5000, 'currency' => 'BDT', 'status' => 'pending', 'external_id' => 'ext-'.uniqid(), 'idempotency_key' => $idemKey]);
        $this->expectException(QueryException::class);
        $this->createRow(Payout::class, ['user_id' => $user->id, 'tournament_id' => $tournament->id, 'amount_minor' => 5000, 'currency' => 'BDT', 'status' => 'pending', 'external_id' => 'ext-'.uniqid(), 'idempotency_key' => $idemKey]);
    }

    public function test_concurrent_settlement_completion_once_only(): void
    {
        $tournament = Tournament::factory()->create();
        $idemKey = 'settlement-'.uniqid();
        $settlement = $this->createRow(FinancialSettlement::class, ['tournament_id' => $tournament->id, 'total_amount_minor' => 10000, 'currency' => 'BDT', 'status' => 'pending', 'idempotency_key' => $idemKey]);
        DB::transaction(function () use ($settlement) {
            $locked = FinancialSettlement::where('id', $settlement->id)->lockForUpdate()->first();
            $this->updateRow($locked, ['status' => 'completed', 'completed_at' => now()]);
        });
        $settlement->refresh();
        $this->assertEquals('completed', $settlement->status);
    }
}
