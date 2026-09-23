<?php

namespace Tests\Feature\R9;

use App\Models\FinancialSettlement;
use App\Models\IdempotencyRecord;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Tournament;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WebhookEvent;
use App\Services\WalletService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PostgreSQLIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_connection_and_migrations(): void
    {
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(DB::table('users')->count() >= 0);
    }

    public function test_tables_exist(): void
    {
        $tables = ['users', 'tournaments', 'wallets', 'ledger_entries', 'payments', 'payouts', 'webhook_events', 'idempotency_records', 'financial_settlements'];
        foreach ($tables as $table) {
            $this->assertTrue(DB::getSchemaBuilder()->hasTable($table), "Table $table missing");
        }
    }

    public function test_transaction_commit_rollback(): void
    {
        DB::beginTransaction();
        User::factory()->create(['email' => 'tx-test@example.com']);
        DB::rollBack();
        $this->assertDatabaseMissing('users', ['email' => 'tx-test@example.com']);
        DB::beginTransaction();
        User::factory()->create(['email' => 'tx-test2@example.com']);
        DB::commit();
        $this->assertDatabaseHas('users', ['email' => 'tx-test2@example.com']);
    }

    public function test_payment_persistence(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create(['user_id' => $user->id, 'currency' => 'BDT', 'balance_minor' => 0]);
        $payment = $this->createRow(Payment::class, ['user_id' => $user->id, 'wallet_id' => $wallet->id, 'provider' => 'manual', 'external_id' => 'ext-'.uniqid(), 'amount_minor' => 1000, 'currency' => 'BDT', 'status' => Payment::STATUS_CREATED, 'idempotency_key' => 'idem-'.uniqid()]);
        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
    }

    public function test_idempotency_unique_key(): void
    {
        $record = IdempotencyRecord::create(['key' => 'test-key-'.uniqid(), 'fingerprint' => hash('sha256', json_encode(['amount' => 100])), 'operation' => 'payment_create', 'user_id' => null, 'request_body' => ['amount' => 100], 'response_body' => ['status' => 'ok'], 'status_code' => 200, 'expires_at' => now()->addHour()]);
        $this->assertDatabaseHas('idempotency_records', ['key' => $record->key]);
        $this->expectException(QueryException::class);
        IdempotencyRecord::create(['key' => $record->key, 'fingerprint' => hash('sha256', json_encode(['amount' => 200])), 'operation' => 'payment_create', 'request_body' => [], 'response_body' => [], 'expires_at' => now()->addHour()]);
    }

    public function test_wallet_locking(): void
    {
        $user = User::factory()->create();
        Wallet::create(['user_id' => $user->id, 'currency' => 'BDT', 'balance_minor' => 1000]);
        $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();
        $this->assertNotNull($wallet);
        $this->assertEquals(1000, $wallet->balance_minor);
    }

    public function test_ledger_append_only(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create(['user_id' => $user->id, 'currency' => 'BDT', 'balance_minor' => 0]);
        $service = app(WalletService::class);
        $service->credit($user->id, 1000, 'BDT', 'test', 'ref-1');
        $service->debit($user->id, 200, 'BDT', 'test', 'ref-2');
        $wallet->refresh();
        $this->assertEquals(800, $wallet->balance_minor);
        $entries = LedgerEntry::where('wallet_id', $wallet->id)->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertEquals(1000, $entries[0]->balance_after_minor);
        $this->assertEquals(800, $entries[1]->balance_after_minor);
    }

    public function test_payout_duplicate_prevention(): void
    {
        $user = User::factory()->create();
        $tournament = Tournament::factory()->create();
        $payout = $this->createRow(Payout::class, ['user_id' => $user->id, 'tournament_id' => $tournament->id, 'amount_minor' => 5000, 'currency' => 'BDT', 'status' => 'pending', 'external_id' => 'ext-payout-'.uniqid(), 'idempotency_key' => 'idem-payout-'.uniqid()]);
        $this->assertDatabaseHas('payouts', ['id' => $payout->id]);
        $this->expectException(QueryException::class);
        $this->createRow(Payout::class, ['user_id' => $user->id, 'tournament_id' => $tournament->id, 'amount_minor' => 5000, 'currency' => 'BDT', 'status' => 'pending', 'external_id' => $payout->external_id, 'idempotency_key' => 'different-key-'.uniqid()]);
    }

    public function test_webhook_event_uniqueness(): void
    {
        $eventId = 'evt-'.uniqid();
        WebhookEvent::create(['provider' => 'bkash', 'event_type' => 'payment.succeeded', 'event_id' => $eventId, 'payload' => ['amount' => 1000], 'state' => 'received']);
        $this->expectException(QueryException::class);
        WebhookEvent::create(['provider' => 'bkash', 'event_type' => 'payment.succeeded', 'event_id' => $eventId, 'payload' => ['amount' => 1000], 'state' => 'received']);
    }

    public function test_settlement_uniqueness(): void
    {
        $tournament = Tournament::factory()->create();
        $settlement = $this->createRow(FinancialSettlement::class, ['tournament_id' => $tournament->id, 'total_amount_minor' => 10000, 'currency' => 'BDT', 'status' => 'pending', 'idempotency_key' => 'settlement-'.uniqid()]);
        $this->assertDatabaseHas('financial_settlements', ['id' => $settlement->id]);
        $this->expectException(QueryException::class);
        $this->createRow(FinancialSettlement::class, ['tournament_id' => $tournament->id, 'total_amount_minor' => 10000, 'currency' => 'BDT', 'status' => 'pending', 'idempotency_key' => $settlement->idempotency_key]);
    }
}
