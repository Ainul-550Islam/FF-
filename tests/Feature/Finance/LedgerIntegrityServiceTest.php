<?php

namespace Tests\Feature\Finance;

use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Finance\LedgerIntegrityService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GAP-10 A8 (tracker row 036) — ledger integrity verification.
 *
 * The service is an *audit*: it reports, it never repairs. These tests pin
 * both halves of that contract — every corruption class is detected, and
 * nothing in the database is modified by running it.
 */
class LedgerIntegrityServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function wallets(): WalletService
    {
        return app(WalletService::class);
    }

    protected function ledger(): LedgerIntegrityService
    {
        return app(LedgerIntegrityService::class);
    }

    /**
     * A wallet with balanced credits/debits recorded through WalletService,
     * i.e. the only way the application is allowed to write money.
     */
    protected function fundedWallet(int $creditMinor = 100000, int $debitMinor = 40000): array
    {
        $user = $this->makeUser();
        $wallet = $this->wallets()->walletFor($user);

        $this->wallets()->credit($wallet, $creditMinor, LedgerEntry::TYPE_DEPOSIT, 'test credit');
        $this->wallets()->debit($wallet->fresh(), $debitMinor, LedgerEntry::TYPE_WITHDRAWAL, 'test debit');

        return [$user, $wallet->fresh()];
    }

    public function test_clean_ledger_reports_ok_and_is_read_only(): void
    {
        [$user, $wallet] = $this->fundedWallet();

        $before = [
            'balance' => $wallet->fresh()->balance_minor,
            'entries' => LedgerEntry::count(),
            'updated_at' => (string) $wallet->fresh()->updated_at,
        ];

        $report = $this->ledger()->verify();

        $this->assertTrue($report['ok'], json_encode($report['issues']));
        $this->assertTrue($report['read_only']);
        $this->assertSame(0, $report['issues_count']);
        $this->assertSame(1, $report['wallets_checked']);
        $this->assertSame(2, $report['ledger_entries_checked']);
        $this->assertSame(60000, $report['totals']['stored_minor']);
        $this->assertSame(60000, $report['totals']['ledger_minor']);
        $this->assertSame(0, $report['totals']['delta_minor']);

        // The audit changed nothing.
        $this->assertSame($before['balance'], $wallet->fresh()->balance_minor);
        $this->assertSame($before['entries'], LedgerEntry::count());
        $this->assertSame($before['updated_at'], (string) $wallet->fresh()->updated_at);

        $this->assertStringContainsString('ledger integrity', $this->ledger()->summarise($report));
        $this->assertTrue($this->ledger()->isCleanForUser($user));
    }

    public function test_a_tampered_balance_is_detected_and_not_repaired(): void
    {
        [, $wallet] = $this->fundedWallet();

        // Someone edits the stored balance directly (a restore, a manual SQL
        // fix, a bad migration) without a ledger row.
        DB::table('wallets')->where('id', $wallet->id)->update(['balance_minor' => 123456]);

        $report = $this->ledger()->verify();

        $this->assertFalse($report['ok']);
        $this->assertSame(1, count($report['issues'][LedgerIntegrityService::ISSUE_BALANCE_MISMATCH]));

        $issue = $report['issues'][LedgerIntegrityService::ISSUE_BALANCE_MISMATCH][0];

        $this->assertSame((int) $wallet->id, $issue['wallet_id']);
        $this->assertSame(123456, $issue['stored_minor']);
        $this->assertSame(60000, $issue['ledger_minor']);
        $this->assertSame(123456 - 60000, $issue['difference_minor']);
        $this->assertSame(63456, $report['totals']['delta_minor']);

        // Evidence, not repair: the tampered value is still there.
        $this->assertSame(123456, (int) $wallet->fresh()->balance_minor);
    }

    public function test_a_negative_wallet_balance_is_detected(): void
    {
        [, $wallet] = $this->fundedWallet();

        DB::table('wallets')->where('id', $wallet->id)->update(['balance_minor' => -5000]);

        $report = $this->ledger()->verify();

        $this->assertFalse($report['ok']);
        $this->assertGreaterThanOrEqual(1, count($report['issues'][LedgerIntegrityService::ISSUE_NEGATIVE_BALANCE]));

        $issue = $report['issues'][LedgerIntegrityService::ISSUE_NEGATIVE_BALANCE][0];

        $this->assertSame(-5000, $issue['stored_minor']);
    }

    public function test_a_broken_running_balance_chain_is_detected(): void
    {
        [, $wallet] = $this->fundedWallet();

        $entry = LedgerEntry::where('wallet_id', $wallet->id)->orderBy('id')->firstOrFail();

        // Rewrite the recorded running balance of the first row: the chain no
        // longer adds up even though the stored wallet balance still does.
        DB::table('ledger_entries')->where('id', $entry->id)->update(['balance_after_minor' => 999]);

        $report = $this->ledger()->verify();

        $this->assertFalse($report['ok']);
        $this->assertGreaterThanOrEqual(1, count($report['issues'][LedgerIntegrityService::ISSUE_CHAIN_BREAK]));

        $issue = $report['issues'][LedgerIntegrityService::ISSUE_CHAIN_BREAK][0];

        $this->assertSame((int) $entry->id, $issue['entry_id']);
        $this->assertSame(999, $issue['recorded_balance_minor']);
        $this->assertSame(100000, $issue['expected_balance_minor']);
    }

    public function test_a_ledger_row_attributed_to_the_wrong_user_is_detected(): void
    {
        [, $wallet] = $this->fundedWallet();

        $other = $this->makeUser();
        $entry = LedgerEntry::where('wallet_id', $wallet->id)->orderBy('id')->firstOrFail();

        // The wallet belongs to someone else now: money attributed to the
        // wrong person.
        DB::table('ledger_entries')->where('id', $entry->id)->update(['user_id' => $other->id]);

        $report = $this->ledger()->verify();

        $this->assertFalse($report['ok']);
        $this->assertGreaterThanOrEqual(1, count($report['issues'][LedgerIntegrityService::ISSUE_OWNER_MISMATCH]));

        $issue = $report['issues'][LedgerIntegrityService::ISSUE_OWNER_MISMATCH][0];

        $this->assertSame((int) $other->id, $issue['entry_user_id']);
        $this->assertSame((int) $wallet->user_id, $issue['wallet_user_id']);
    }

    public function test_orphan_ledger_rows_are_scanned(): void
    {
        $this->fundedWallet();

        // The scan always runs and always reports its own key…
        $report = $this->ledger()->verify();

        $this->assertArrayHasKey(LedgerIntegrityService::ISSUE_ORPHAN_LEDGER, $report['issues']);

        // …and a ledger row pointing at a wallet that does not exist is what
        // it looks for. Producing one requires defeating the foreign key,
        // which is exactly why it can only appear after a bad restore or a
        // manual SQL edit. Where the environment allows it (PostgreSQL with
        // session_replication_role, i.e. a privileged maintenance session),
        // the detection is proven end to end; where it does not, the absence
        // of orphans must be reported as zero, never as "unknown".
        if (! $this->canBypassForeignKeys()) {
            $this->assertSame([], $report['issues'][LedgerIntegrityService::ISSUE_ORPHAN_LEDGER]);

            return;
        }

        DB::unprepared('SET session_replication_role = replica');

        try {
            DB::table('ledger_entries')->insert([
                'wallet_id' => 987654,
                'user_id' => 987654,
                'direction' => LedgerEntry::DIRECTION_CREDIT,
                'amount_minor' => 500,
                'balance_after_minor' => 500,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } finally {
            DB::unprepared('SET session_replication_role = DEFAULT');
        }

        $report = $this->ledger()->verify();

        $this->assertFalse($report['ok']);
        $this->assertSame(1, count($report['issues'][LedgerIntegrityService::ISSUE_ORPHAN_LEDGER]));
        $this->assertSame(987654, $report['issues'][LedgerIntegrityService::ISSUE_ORPHAN_LEDGER][0]['wallet_id']);
    }

    /**
     * Whether this connection lets the test defy its own foreign keys long
     * enough to plant an orphan row. Never assumed: probed, and the probe
     * result decides whether the detection is asserted or the "no orphans"
     * state is asserted.
     */
    protected function canBypassForeignKeys(): bool
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return false;
        }

        try {
            DB::unprepared('SET session_replication_role = replica');
            DB::unprepared('SET session_replication_role = DEFAULT');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function test_the_audit_can_be_scoped_to_one_user(): void
    {
        [$user] = $this->fundedWallet();

        // A second, tampered wallet belonging to somebody else.
        $other = $this->makeUser();
        $otherWallet = $this->wallets()->walletFor($other);
        $this->wallets()->credit($otherWallet, 10000, LedgerEntry::TYPE_DEPOSIT, 'other credit');
        DB::table('wallets')->where('id', $otherWallet->id)->update(['balance_minor' => 5]);

        // Scoped to the clean user: no findings.
        $scoped = $this->ledger()->verify((int) $user->id);

        $this->assertTrue($scoped['ok']);
        $this->assertSame(1, $scoped['wallets_checked']);
        $this->assertTrue($this->ledger()->isCleanForUser($user));

        // Whole database: the tampered wallet is found.
        $all = $this->ledger()->verify();

        $this->assertFalse($all['ok']);
        $this->assertSame(2, $all['wallets_checked']);
        $this->assertFalse($this->ledger()->isCleanForUser($other));
    }

    public function test_the_report_is_bounded_and_json_serialisable(): void
    {
        // 120 broken wallets is more than the per-type cap of 100.
        for ($i = 0; $i < 120; $i++) {
            $user = $this->makeUser();
            $wallet = $this->wallets()->walletFor($user);
            DB::table('wallets')->where('id', $wallet->id)->update(['balance_minor' => 7]);
        }

        $report = $this->ledger()->verify();

        $this->assertFalse($report['ok']);
        $this->assertLessThanOrEqual(LedgerIntegrityService::MAX_ISSUES_PER_TYPE, count($report['issues'][LedgerIntegrityService::ISSUE_BALANCE_MISMATCH]));
        $this->assertNotFalse(json_encode($report), 'The report must be JSON-serialisable.');
    }

    public function test_wallet_limit_bounds_the_scan(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $user = $this->makeUser();
            $wallet = $this->wallets()->walletFor($user);
            DB::table('wallets')->where('id', $wallet->id)->update(['balance_minor' => 99]);
        }

        $report = $this->ledger()->verify(null, 2);

        $this->assertSame(2, $report['wallets_checked']);
        $this->assertSame(2, $report['scope']['wallet_limit']);
    }

    public function test_a_wallet_with_no_ledger_rows_and_zero_balance_is_clean(): void
    {
        $user = $this->makeUser();
        $wallet = $this->wallets()->walletFor($user);

        $this->assertSame(0, $wallet->balanceMinor());

        $report = $this->ledger()->verify((int) $user->id);

        $this->assertTrue($report['ok']);
        $this->assertSame(1, $report['wallets_checked']);
        $this->assertSame(0, $report['ledger_entries_checked']);
        $this->assertSame(Wallet::class, get_class($wallet));
    }
}
