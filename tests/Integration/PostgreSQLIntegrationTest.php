<?php

namespace Tests\Integration;

use App\Models\User;
use App\Models\Wallet;
use App\Services\Finance\LedgerIntegrityService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgreSQLIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_postgres_connection_ping(): void
    {
        if (! $this->isPostgresAvailable()) {
            $this->markTestSkipped('PostgreSQL not available - BLOCKED BY ENVIRONMENT');
        } $pdo = DB::connection('pgsql')->getPdo();
        $this->assertNotNull($pdo);
        $result = DB::connection('pgsql')->select('SELECT 1 as val');
        $this->assertEquals(1, $result[0]->val);
    }

    public function test_postgres_transaction_commit_rollback(): void
    {
        if (! $this->isPostgresAvailable()) {
            $this->markTestSkipped('PostgreSQL not available - BLOCKED BY ENVIRONMENT');
        } DB::connection('pgsql')->beginTransaction();
        DB::connection('pgsql')->table('users')->insert(['name' => 'Test', 'email' => 'test-rollback@example.com', 'password' => bcrypt('password'), 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('pgsql')->rollBack();
        $exists = DB::connection('pgsql')->table('users')->where('email', 'test-rollback@example.com')->exists();
        $this->assertFalse($exists);
    }

    public function test_postgres_select_for_update_locking(): void
    {
        if (! $this->isPostgresAvailable()) {
            $this->markTestSkipped('PostgreSQL not available - BLOCKED BY ENVIRONMENT');
        } $user = User::factory()->create();
        $wallet = Wallet::create(['user_id' => $user->id, 'currency' => 'BDT', 'balance_minor' => 1000]);
        DB::connection('pgsql')->transaction(function () use ($wallet) {
            $locked = Wallet::where('id', $wallet->id)->lockForUpdate()->first();
            $this->assertNotNull($locked);
            $this->assertEquals(1000, $locked->balance_minor);
        });
    }

    public function test_postgres_ledger_append_only(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::create(['user_id' => $user->id, 'currency' => 'BDT', 'balance_minor' => 0]);
        $service = app(WalletService::class);
        $service->credit($user->id, 1000, 'BDT', 'test', 'ref-1');
        $service->credit($user->id, 500, 'BDT', 'test', 'ref-2');
        $service->debit($user->id, 200, 'BDT', 'test', 'ref-3');
        $wallet->refresh();
        $this->assertEquals(1300, $wallet->balance_minor);
        $calculated = $service->calculateBalance($wallet->id);
        $this->assertEquals(1300, $calculated);
        $this->assertTrue($service->verifyLedgerIntegrity($wallet->id));
    }

    /**
     * GAP-10 A8 — integrity is recomputed from the ledger, never read from the
     * row's own metadata.
     *
     * The tempting shortcut for a health check is to trust a flag: a
     * `metadata.verified` written when the row was created, or a stored hash
     * column, proves nothing after the row is edited. This test tampers with a
     * ledger row the way a bug (or an attacker with SQL access) would — change
     * the amount, leave every derived value claiming the old state — and
     * asserts that the checker notices, then notices the recovery when the row
     * is put back.
     *
     * Run with the PostgreSQL profile; it exercises the real json column and the
     * real `SUM()` behaviour the SQLite profile cannot prove.
     */
    public function test_postgres_ledger_integrity_is_recomputed_not_trusted(): void
    {
        if (! $this->isPostgresAvailable()) {
            $this->markTestSkipped('PostgreSQL not available - BLOCKED BY ENVIRONMENT');
        }

        $user = User::factory()->create();
        $wallet = Wallet::create(['user_id' => $user->id, 'currency' => 'BDT', 'balance_minor' => 0]);

        $walletService = app(WalletService::class);
        $walletService->credit($user->id, 1000, 'BDT', 'integration', 'integrity-1');
        $walletService->debit($user->id, 250, 'BDT', 'integration', 'integrity-2');

        $integrity = app(LedgerIntegrityService::class);

        $clean = $integrity->verify((int) $user->id);
        $this->assertTrue($clean['ok'], 'A freshly written wallet must verify clean.');
        $this->assertSame(0, $clean['totals']['delta_minor']);
        $this->assertGreaterThanOrEqual(1, $clean['wallets_checked']);
        $this->assertGreaterThanOrEqual(2, $clean['ledger_entries_checked']);

        $entry = DB::connection('pgsql')
            ->table('ledger_entries')
            ->where('wallet_id', $wallet->id)
            ->orderBy('id')
            ->first();

        $this->assertNotNull($entry, 'The wallet must have at least one ledger row.');

        // Tamper: the amount no longer matches what the wallet claims, and the
        // row's metadata is rewritten to say everything is fine — which is
        // exactly the claim the checker must ignore.
        DB::connection('pgsql')->table('ledger_entries')->where('id', $entry->id)->update([
            'amount_minor' => 999_999,
            'metadata' => json_encode(['verified' => true, 'reason' => 'metadata is not evidence']),
        ]);

        $tampered = $integrity->verify((int) $user->id);
        $this->assertFalse($tampered['ok'], 'Tampering must be detected even though the row metadata says "verified".');
        $this->assertGreaterThanOrEqual(1, $tampered['issues_count']);
        $this->assertNotSame(0, $tampered['totals']['delta_minor']);
        $this->assertNotEmpty(
            $tampered['issues'][LedgerIntegrityService::ISSUE_BALANCE_MISMATCH],
            'A stored balance that disagrees with the ledger is a balance_mismatch.',
        );
        $this->assertFalse($integrity->isCleanForUser($user), 'isCleanForUser must fail closed on the same evidence.');

        // And put it back: a checker that cannot clear is as useless as one that
        // cannot fail.
        DB::connection('pgsql')->table('ledger_entries')->where('id', $entry->id)->update([
            'amount_minor' => $entry->amount_minor,
        ]);

        $recovered = $integrity->verify((int) $user->id);
        $this->assertTrue($recovered['ok'], 'Restoring the row must make the report clean again.');
        $this->assertSame(0, $recovered['totals']['delta_minor']);
        $this->assertTrue($integrity->isCleanForUser($user));
    }
}
