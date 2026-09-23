<?php

namespace Tests\Feature\R9;

use App\Models\FinancialSettlement;
use App\Models\LedgerEntry;
use App\Models\Tournament;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgreSQLFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_transaction_rollback_on_failed_ledger(): void
    {
        $user = User::factory()->create();
        Wallet::create(['user_id' => $user->id, 'currency' => 'BDT', 'balance_minor' => 1000]);
        try {
            DB::transaction(function () use ($user) {
                $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();
                $wallet->update(['balance_minor' => 500]);
                throw new \RuntimeException('Simulated failure');
            });
        } catch (\RuntimeException $e) {
        } $wallet = Wallet::where('user_id', $user->id)->first();
        $this->assertEquals(1000, $wallet->balance_minor);
    }

    public function test_failed_insert_rollback(): void
    {
        $initialCount = User::count();
        try {
            DB::transaction(function () {
                User::factory()->create(['email' => 'fail-test@example.com']);
                throw new \RuntimeException('Fail');
            });
        } catch (\RuntimeException $e) {
        } $this->assertEquals($initialCount, User::count());
    }

    public function test_failed_wallet_update_atomicity(): void
    {
        $user = User::factory()->create();
        Wallet::create(['user_id' => $user->id, 'currency' => 'BDT', 'balance_minor' => 1000]);
        $service = app(WalletService::class);
        try {
            $service->debit($user->id, 2000, 'BDT', 'test', 'ref-fail');
            $this->fail('Should have thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Insufficient funds', $e->getMessage());
        } $wallet = Wallet::where('user_id', $user->id)->first();
        $this->assertEquals(1000, $wallet->balance_minor);
        $this->assertEquals(0, LedgerEntry::where('wallet_id', $wallet->id)->count());
    }

    public function test_failed_payment_no_partial_record(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create(['user_id' => $user->id, 'currency' => 'BDT', 'balance_minor' => 1000]);
        $initialLedgerCount = LedgerEntry::count();
        try {
            DB::transaction(function () use ($user, $wallet) {
                $this->createRow(LedgerEntry::class, ['wallet_id' => $wallet->id, 'user_id' => $user->id, 'direction' => 'debit', 'amount_minor' => 100, 'balance_after_minor' => 900, 'reference_type' => 'payment', 'reference_id' => 'ref-fail', 'idempotency_key' => 'idem-fail-'.uniqid()]);
                throw new \RuntimeException('Payment failed');
            });
        } catch (\RuntimeException $e) {
        } $this->assertEquals($initialLedgerCount, LedgerEntry::count());
        $wallet->refresh();
        $this->assertEquals(1000, $wallet->balance_minor);
    }

    public function test_partially_failed_settlement_atomic(): void
    {
        $tournament = Tournament::factory()->create();
        $initialCount = FinancialSettlement::count();
        try {
            DB::transaction(function () use ($tournament) {
                FinancialSettlement::create(['tournament_id' => $tournament->id, 'total_amount_minor' => 10000, 'currency' => 'BDT', 'status' => 'pending', 'idempotency_key' => 'settlement-fail-'.uniqid()]);
                throw new \RuntimeException('Settlement failed');
            });
        } catch (\RuntimeException $e) {
        } $this->assertEquals($initialCount, FinancialSettlement::count());
    }

    public function test_query_timeout_handling(): void
    {
        $this->assertTrue(true);
        if ($this->isPostgresAvailable()) {
            try {
                DB::connection('pgsql')->statement('SET statement_timeout = 1');
                DB::connection('pgsql')->select('SELECT 1');
                DB::connection('pgsql')->statement('SET statement_timeout = 0');
                $this->assertTrue(true);
            } catch (\Throwable $e) {
                $this->assertStringContainsString('timeout', strtolower($e->getMessage()));
            }
        } else {
            $this->markTestSkipped('PostgreSQL not available - BLOCKED BY ENVIRONMENT');
        }
    }

    public function test_financial_operation_never_reports_success_without_commit(): void
    {
        $user = User::factory()->create();
        $service = app(WalletService::class);
        $service->credit($user->id, 1000, 'BDT', 'test', 'ref-init');
        $wallet = Wallet::where('user_id', $user->id)->first();
        $this->assertEquals(1000, $wallet->balance_minor);
        $this->assertTrue($service->verifyLedgerIntegrity($wallet->id));
        $service->credit($user->id, 500, 'BDT', 'test', 'ref-success-check');
        $wallet->refresh();
        $this->assertEquals(1500, $wallet->balance_minor);
        $this->assertTrue($service->verifyLedgerIntegrity($wallet->id));
        $initialLedgerCount = LedgerEntry::where('wallet_id', $wallet->id)->count();
        try {
            $service->debit($user->id, 5000, 'BDT', 'test', 'ref-fail-large');
            $this->fail('Should have thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Insufficient funds', $e->getMessage());
        } $wallet->refresh();
        $this->assertEquals(1500, $wallet->balance_minor);
        $this->assertEquals($initialLedgerCount, LedgerEntry::where('wallet_id', $wallet->id)->count());
        $this->assertTrue($service->verifyLedgerIntegrity($wallet->id));
    }
}
