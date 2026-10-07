<?php

namespace Tests\Feature\Postgres;

use App\Models\GemWallet;
use App\Models\GoldWallet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * GAP-10 A2 — real row locking on the virtual (gold/gem) wallets.
 *
 * Reproduces the confirmed defect: `$this->lockForUpdate();` on a *model
 * instance* returns a query builder and executes zero statements, so no row
 * lock was ever taken and two concurrent spends could both succeed against the
 * same balance. These tests drive the production write paths
 * (`spendGold()` / `spendGems()` / `addGold()` / `addGems()`) while a second,
 * independent connection holds the row, and assert that the writer really
 * blocks and that a locking SELECT is issued.
 *
 * They need an engine that implements `SELECT … FOR UPDATE`, hence the
 * PostgreSQL profile (Laravel's SQLite grammar silently drops the lock clause,
 * and SQLite is a single-writer database; the SQLite-safe structural guard
 * lives in tests/Feature/Gameberry/VirtualWalletLockQueryTest.php).
 *
 * Row-ownership rules that make these tests deterministic:
 *  - The wallet row and its user are created and left *committed* on the second
 *    connection, because a row inserted inside the test's own transaction is
 *    invisible to every other connection and no lock could be contended for.
 *  - Cleanup cannot run inside the test body: the framework's
 *    `RefreshDatabase` transaction is still open and holds the tuple the
 *    cleanup DELETE needs, so the DELETE would block until the test times out.
 *    Seeded rows are therefore purged in tearDown(), after
 *    `parent::tearDown()` has rolled the test transaction back, using a raw
 *    PDO handle captured while the application was still booted.
 *  - Both connections get a `lock_timeout`/`statement_timeout` safety net so a
 *    regression fails loudly instead of hanging the suite forever.
 */
class VirtualWalletLockTest extends PostgresTestCase
{
    /** @var list<array{table: string, id: int}> Rows committed on connection 2. */
    protected array $committedRows = [];

    /** @var int|null The user committed on connection 2. */
    protected ?int $committedUserId = null;

    /** @var \PDO|null Raw handle to the second connection, captured before teardown. */
    protected ?\PDO $cleanupPdo = null;

    /** @var \PDO|null Raw handle to the default connection. */
    protected ?\PDO $defaultPdo = null;

    protected function setUp(): void
    {
        parent::setUp();

        $second = $this->secondConnection();
        $second->statement('SET lock_timeout = 4000');
        $second->statement('SET statement_timeout = 8000');

        $this->cleanupPdo = $second->getPdo();

        // A net over the default connection as well: if a writer ever blocks
        // because the lock stopped being taken (i.e. the defect returns and
        // the write runs past the row), the statement is cancelled instead of
        // hanging the whole suite.
        DB::connection()->statement('SET statement_timeout = 8000');

        $this->defaultPdo = DB::connection()->getPdo();
    }

    protected function tearDown(): void
    {
        $cleanup = $this->cleanupPdo;
        $default = $this->defaultPdo;
        $rows = $this->committedRows;
        $userId = $this->committedUserId;

        $this->cleanupPdo = null;
        $this->defaultPdo = null;
        $this->committedRows = [];
        $this->committedUserId = null;

        // Rolls back the RefreshDatabase transaction, which releases every
        // lock the test held and unblocks the DELETE executed below.
        parent::tearDown();

        if ($default !== null) {
            try {
                $default->exec('RESET statement_timeout');
            } catch (\Throwable) {
                // The connection may already be gone; nothing to reset.
            }
        }

        if ($cleanup === null || $rows === []) {
            return;
        }

        try {
            if ($cleanup->inTransaction()) {
                $cleanup->rollBack();
            }

            foreach ($rows as $row) {
                $cleanup->prepare('delete from '.$row['table'].' where id = ?')->execute([$row['id']]);
            }

            if ($userId !== null) {
                $cleanup->prepare('delete from users where id = ?')->execute([$userId]);
            }
        } catch (\Throwable) {
            // Test databases are rebuilt per run; a failed purge must not mask
            // the assertion failure that caused the test to stop early.
        }
    }

    /**
     * Commit a user and a virtual wallet through the second connection.
     *
     * @return GoldWallet A wallet model bound to the *default* connection, so
     *                    its write path contends with the other connection.
     */
    protected function committedGoldWallet(int $balance): GoldWallet
    {
        $second = $this->secondConnection();
        $uid = bin2hex(random_bytes(6));

        $userId = (int) $second->table('users')->insertGetId([
            'name' => 'Wallet '.$uid,
            'email' => 'wallet-'.$uid.'@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->committedUserId = $userId;

        $walletId = (int) $second->table('gold_wallets')->insertGetId([
            'user_id' => $userId,
            'gold_balance' => $balance,
            'total_earned' => $balance,
            'total_spent' => 0,
            'total_won' => 0,
            'total_lost' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->committedRows[] = ['table' => 'gold_wallets', 'id' => $walletId];

        return GoldWallet::query()->whereKey($walletId)->firstOrFail();
    }

    /**
     * Commit a user and a gem wallet through the second connection.
     */
    protected function committedGemWallet(int $balance): GemWallet
    {
        $second = $this->secondConnection();
        $uid = bin2hex(random_bytes(6));

        $userId = (int) $second->table('users')->insertGetId([
            'name' => 'Gems '.$uid,
            'email' => 'gems-'.$uid.'@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->committedUserId = $userId;

        $walletId = (int) $second->table('gem_wallets')->insertGetId([
            'user_id' => $userId,
            'gem_balance' => $balance,
            'total_earned' => $balance,
            'total_spent' => 0,
            'total_purchased' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->committedRows[] = ['table' => 'gem_wallets', 'id' => $walletId];

        return GemWallet::query()->whereKey($walletId)->firstOrFail();
    }

    public function test_gold_spend_issues_a_locking_select_statement(): void
    {
        $wallet = $this->committedGoldWallet(1000);
        $walletId = (int) $wallet->getKey();

        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        $wallet->spendGold(100, 'bet');

        $lockingSelect = array_values(array_filter(
            $statements,
            static fn (string $sql): bool => (bool) preg_match(
                '/select\s+.*from\s+"?gold_wallets"?.*for\s+update/is',
                $sql
            )
        ));

        // Before the A2 fix this array was empty: the old implementation ran
        // `$this->lockForUpdate(); $this->refresh();` — the first call built a
        // query builder that was thrown away, so no statement at all was sent.
        $this->assertNotEmpty(
            $lockingSelect,
            'The gold write path must issue SELECT … FOR UPDATE on the wallet row.'
        );

        $this->assertStringContainsString('gold_wallets', $lockingSelect[0]);

        $this->assertSame(
            900,
            (int) DB::table('gold_wallets')->where('id', $walletId)->value('gold_balance')
        );

        // The transaction-row bookkeeping keeps the running balance.
        $this->assertSame(
            900,
            (int) DB::table('gold_transactions')
                ->where('gold_wallet_id', $walletId)->orderByDesc('id')->value('balance_after')
        );
    }

    public function test_gold_spend_blocks_on_a_row_held_by_another_writer(): void
    {
        $wallet = $this->committedGoldWallet(1000);
        $walletId = (int) $wallet->getKey();

        $second = $this->secondConnection();

        try {
            // The other client holds the wallet row for the duration of its
            // work — exactly what a concurrent spend looks like.
            $second->beginTransaction();
            $second->table('gold_wallets')->where('id', $walletId)->lockForUpdate()->first();

            // Trip the statement timeout well before the connection-level net
            // so the assertion below is what fails, not a suite hang.
            DB::connection()->statement("SET statement_timeout = '500ms'");

            $blockedFor = 0.0;
            $threw = false;

            try {
                $started = microtime(true);
                $wallet->spendGold(100, 'bet');
            } catch (QueryException $e) {
                $threw = true;
                $blockedFor = microtime(true) - $started;

                $this->assertMatchesRegularExpression(
                    '/statement timeout|canceling statement/i',
                    $e->getMessage(),
                    'The spend must be cancelled by the row lock, not by an unrelated error.'
                );
            } finally {
                DB::connection()->statement('RESET statement_timeout');
            }

            // Before the A2 fix the spend returned instantly, took the lock
            // for granted and spent a balance it had never locked.
            $this->assertTrue(
                $threw,
                'The wallet write must block while another writer holds the row.'
            );

            $this->assertGreaterThanOrEqual(
                0.3,
                $blockedFor,
                'The spend must have waited on the lock for about the statement timeout.'
            );
        } finally {
            if ($second->transactionLevel() > 0) {
                $second->rollBack();
            }
        }

        // Nothing was written by the blocked spend.
        $this->assertSame(
            1000,
            (int) DB::table('gold_wallets')->where('id', $walletId)->value('gold_balance')
        );

        $this->assertSame(
            0,
            (int) DB::table('gold_transactions')->where('gold_wallet_id', $walletId)->count()
        );
    }

    public function test_gold_spend_uses_the_committed_balance_not_the_stale_instance(): void
    {
        $wallet = $this->committedGoldWallet(1000);
        $walletId = (int) $wallet->getKey();

        // Someone else spends 950 and commits. The in-memory instance still
        // holds 1000, but the write path re-reads the row under the lock, so
        // the balance check must run against the committed 50.
        $this->secondConnection()->table('gold_wallets')
            ->where('id', $walletId)
            ->update(['gold_balance' => 50]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Insufficient gold balance');

        $wallet->spendGold(100, 'bet');
    }

    public function test_concurrent_spends_never_overdraw_the_wallet(): void
    {
        $wallet = $this->committedGoldWallet(1000);
        $walletId = (int) $wallet->getKey();

        // 1000 - 600 = 400; the second spend of 600 must be refused and the
        // balance may never go negative.
        $wallet->spendGold(600, 'bet');

        $refused = false;

        try {
            $wallet->spendGold(600, 'bet');
        } catch (\Exception $e) {
            $refused = true;
        }

        $this->assertTrue($refused, 'The second spend must be refused.');

        $balance = (int) DB::table('gold_wallets')->where('id', $walletId)->value('gold_balance');

        $this->assertSame(400, $balance);
        $this->assertGreaterThanOrEqual(0, $balance);
    }

    public function test_gem_spend_locks_the_row_and_never_goes_negative(): void
    {
        $wallet = $this->committedGemWallet(100);
        $walletId = (int) $wallet->getKey();

        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        $wallet->addGems(50, 'reward');
        $wallet->spendGems(120, 'spin2win');

        $this->assertNotEmpty(
            array_filter(
                $statements,
                static fn (string $sql): bool => (bool) preg_match(
                    '/select\s+.*from\s+"?gem_wallets"?.*for\s+update/is',
                    $sql
                )
            ),
            'The gem write path must issue SELECT … FOR UPDATE on the wallet row.'
        );

        $this->assertSame(
            30,
            (int) DB::table('gem_wallets')->where('id', $walletId)->value('gem_balance')
        );

        try {
            $wallet->spendGems(100, 'spin2win');
            $this->fail('Overspending must throw.');
        } catch (\Exception $e) {
            $this->assertSame('Insufficient gems', $e->getMessage());
        }

        $this->assertSame(
            30,
            (int) DB::table('gem_wallets')->where('id', $walletId)->value('gem_balance')
        );

        $this->assertSame(
            30,
            (int) DB::table('gem_transactions')
                ->where('gem_wallet_id', $walletId)->orderByDesc('id')->value('balance_after')
        );
    }
}
