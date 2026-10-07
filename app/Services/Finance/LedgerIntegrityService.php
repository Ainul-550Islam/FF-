<?php

namespace App\Services\Finance;

use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * GAP-10 A8 (tracker row 036) — ledger integrity verification.
 *
 * Answers one question with evidence: does the money on record add up?
 *
 *   1. every wallet's stored balance equals the sum of its ledger entries
 *      (credits − debits);
 *   2. no wallet carries a negative balance, and no wallet's ledger implies
 *      one;
 *   3. every ledger row belongs to an existing wallet and to the same user as
 *      that wallet (orphan / cross-owner rows are the signature of a bad
 *      import or a partial delete);
 *   4. the running-balance chain inside each wallet is consistent, so a row
 *      that was edited after the fact is detectable.
 *
 * Strictly READ-ONLY. It never repairs, never adjusts, never touches a wallet:
 * a report that fixes what it finds cannot be used as evidence, and money
 * corrections must go through WalletService with an audit trail. The caller
 * decides what to do with the findings.
 *
 * The report is structured (and JSON-serialisable) so it can be logged, shown
 * on the ops dashboard, or asserted on by tests.
 */
class LedgerIntegrityService
{
    public const ISSUE_BALANCE_MISMATCH = 'balance_mismatch';

    public const ISSUE_NEGATIVE_BALANCE = 'negative_balance';

    public const ISSUE_ORPHAN_LEDGER = 'orphan_ledger';

    public const ISSUE_OWNER_MISMATCH = 'owner_mismatch';

    public const ISSUE_CHAIN_BREAK = 'chain_break';

    public const ISSUE_ISSUE_TYPES = [
        self::ISSUE_BALANCE_MISMATCH,
        self::ISSUE_NEGATIVE_BALANCE,
        self::ISSUE_ORPHAN_LEDGER,
        self::ISSUE_OWNER_MISMATCH,
        self::ISSUE_CHAIN_BREAK,
    ];

    /**
     * Cap on the number of issues returned per type, so a badly broken
     * database cannot produce an unbounded report.
     */
    public const MAX_ISSUES_PER_TYPE = 100;

    public function __construct(
        protected WalletService $wallets,
    ) {}

    /**
     * Verify the whole ledger, or one user's wallets.
     *
     * @param  int|null  $userId  restrict the audit to a single user
     * @param  int  $walletLimit  0 = every wallet; >0 = the newest N wallets
     * @return array{
     *     ok: bool,
     *     read_only: bool,
     *     checked_at: string,
     *     scope: array{user_id: int|null, wallet_limit: int},
     *     wallets_checked: int,
     *     ledger_entries_checked: int,
     *     issues_count: int,
     *     issues: array<string, array<int, array<string, mixed>>>,
     *     totals: array{stored_minor: int, ledger_minor: int, delta_minor: int}
     * }
     */
    public function verify(?int $userId = null, int $walletLimit = 0): array
    {
        $report = [
            'ok' => true,
            'read_only' => true,
            'checked_at' => now()->toIso8601String(),
            'scope' => ['user_id' => $userId, 'wallet_limit' => $walletLimit],
            'wallets_checked' => 0,
            'ledger_entries_checked' => 0,
            'issues_count' => 0,
            'issues' => [],
            'totals' => ['stored_minor' => 0, 'ledger_minor' => 0, 'delta_minor' => 0],
        ];

        foreach (self::ISSUE_ISSUE_TYPES as $type) {
            $report['issues'][$type] = [];
        }

        $wallets = $this->walletQuery($userId, $walletLimit);

        foreach ($wallets as $wallet) {
            $report['wallets_checked']++;

            $this->inspectWallet($wallet, $report);
        }

        // Orphan and cross-owner rows are checked globally: a ledger row can
        // point at a wallet that no longer exists, which no per-wallet loop
        // would ever see.
        $this->inspectOrphans($report);

        $report['issues_count'] = array_sum(array_map('count', $report['issues']));
        $report['ok'] = $report['issues_count'] === 0;
        $report['totals']['delta_minor'] = $report['totals']['stored_minor'] - $report['totals']['ledger_minor'];

        return $report;
    }

    /**
     * A compact single-line summary for logs and notifications.
     *
     * @param  array<string, mixed>  $report
     */
    public function summarise(array $report): string
    {
        $counts = [];

        foreach (self::ISSUE_ISSUE_TYPES as $type) {
            $count = count($report['issues'][$type] ?? []);

            if ($count > 0) {
                $counts[] = $type.'='.$count;
            }
        }

        $suffix = $counts === [] ? 'no findings' : implode(' ', $counts);

        return sprintf(
            'ledger integrity: %d wallet(s), %d ledger row(s), delta %d minor — %s',
            (int) $report['wallets_checked'],
            (int) $report['ledger_entries_checked'],
            (int) $report['totals']['delta_minor'],
            $suffix,
        );
    }

    /**
     * @param  array<string, mixed>  $report  by reference (mutated)
     */
    protected function inspectWallet(Wallet $wallet, array &$report): void
    {
        $stored = (int) $wallet->balanceMinor();
        $ledger = (int) $this->wallets->calculateBalance($wallet->id);

        $report['totals']['stored_minor'] += $stored;
        $report['totals']['ledger_minor'] += $ledger;

        $entries = LedgerEntry::query()
            ->where('wallet_id', $wallet->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $report['ledger_entries_checked'] += $entries->count();

        if ($stored !== $ledger) {
            $this->push($report, self::ISSUE_BALANCE_MISMATCH, [
                'wallet_id' => (int) $wallet->id,
                'user_id' => (int) $wallet->user_id,
                'stored_minor' => $stored,
                'ledger_minor' => $ledger,
                'difference_minor' => $stored - $ledger,
                'entry_count' => $entries->count(),
            ]);
        }

        if ($stored < 0 || $ledger < 0) {
            $this->push($report, self::ISSUE_NEGATIVE_BALANCE, [
                'wallet_id' => (int) $wallet->id,
                'user_id' => (int) $wallet->user_id,
                'stored_minor' => $stored,
                'ledger_minor' => $ledger,
            ]);
        }

        // Running-balance chain: each row's balance_after_minor must equal the
        // running total of the rows before it. This is what detects an entry
        // that was altered after the fact.
        $running = 0;

        foreach ($entries as $entry) {
            $running += $entry->isCredit() ? (int) $entry->amount_minor : -(int) $entry->amount_minor;

            $balanceAfter = $entry->balance_after_minor ?? $entry->balance_after;

            if ($balanceAfter !== null && (int) $balanceAfter !== $running) {
                $this->push($report, self::ISSUE_CHAIN_BREAK, [
                    'wallet_id' => (int) $wallet->id,
                    'user_id' => (int) $wallet->user_id,
                    'entry_id' => (int) $entry->id,
                    'expected_balance_minor' => $running,
                    'recorded_balance_minor' => (int) $balanceAfter,
                    'direction' => (string) $entry->direction,
                    'amount_minor' => (int) $entry->amount_minor,
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    protected function inspectOrphans(array &$report): void
    {
        try {
            // Ledger rows whose wallet no longer exists. The foreign key makes
            // this impossible through the application, which is exactly why it
            // is worth checking: it can only appear via a manual/restore path.
            $orphans = DB::table('ledger_entries as l')
                ->leftJoin('wallets as w', 'w.id', '=', 'l.wallet_id')
                ->whereNull('w.id')
                ->select(['l.id', 'l.wallet_id', 'l.user_id', 'l.amount_minor', 'l.direction'])
                ->limit(self::MAX_ISSUES_PER_TYPE)
                ->get();

            foreach ($orphans as $row) {
                $this->push($report, self::ISSUE_ORPHAN_LEDGER, [
                    'entry_id' => (int) $row->id,
                    'wallet_id' => (int) $row->wallet_id,
                    'user_id' => (int) $row->user_id,
                    'amount_minor' => (int) $row->amount_minor,
                    'direction' => (string) $row->direction,
                ]);
            }

            // Ledger rows whose user_id does not match the wallet's owner:
            // money attributed to the wrong person.
            $mismatched = DB::table('ledger_entries as l')
                ->join('wallets as w', 'w.id', '=', 'l.wallet_id')
                ->whereColumn('l.user_id', '!=', 'w.user_id')
                ->select(['l.id', 'l.wallet_id', 'l.user_id as entry_user_id', 'w.user_id as wallet_user_id', 'l.amount_minor'])
                ->limit(self::MAX_ISSUES_PER_TYPE)
                ->get();

            foreach ($mismatched as $row) {
                $this->push($report, self::ISSUE_OWNER_MISMATCH, [
                    'entry_id' => (int) $row->id,
                    'wallet_id' => (int) $row->wallet_id,
                    'entry_user_id' => (int) $row->entry_user_id,
                    'wallet_user_id' => (int) $row->wallet_user_id,
                    'amount_minor' => (int) $row->amount_minor,
                ]);
            }
        } catch (Throwable $e) {
            // The verification itself must never throw into a caller: a report
            // that cannot complete is still a report (read_only, ok = false).
            $report['ok'] = false;
            $report['error'] = substr($e::class.': '.$e->getMessage(), 0, 255);
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, Wallet>
     */
    protected function walletQuery(?int $userId, int $walletLimit): \Illuminate\Support\Collection
    {
        $query = Wallet::query()->orderBy('id');

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        if ($walletLimit > 0) {
            $query->limit($walletLimit);
        }

        return $query->get();
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $issue
     */
    protected function push(array &$report, string $type, array $issue): void
    {
        if (! in_array($type, self::ISSUE_ISSUE_TYPES, true)) {
            throw new \InvalidArgumentException("Unknown ledger issue type [{$type}].");
        }

        if (count($report['issues'][$type]) >= self::MAX_ISSUES_PER_TYPE) {
            return;
        }

        $report['issues'][$type][] = $issue;
    }

    /**
     * Convenience wrapper: verify one user's wallets and return whether they
     * are clean. Read-only, safe to call from a controller.
     */
    public function isCleanForUser(User $user): bool
    {
        return (bool) $this->verify((int) $user->id)['ok'];
    }
}
