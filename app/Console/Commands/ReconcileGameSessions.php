<?php

namespace App\Console\Commands;

use App\Models\GameSession;
use App\Services\AuditLogService;
use App\Services\Gameberry\ReconciliationService as GameberryReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GAP-10 A8 (tracker row 056) — close stale Gameberry sessions and reconcile
 * the affected wallets.
 *
 * A session that never finished (client killed, app crashed, device offline)
 * leaves a `game_sessions` row open forever: it skews statistics, blocks the
 * user from starting a new session in some modes, and hides the fact that the
 * round simply never concluded. This command closes those rows and reports
 * whether the affected wallets still add up.
 *
 * What it does:
 *  1. selects sessions that are still open (`finished_at` null) and older than
 *     the stale threshold, oldest first, bounded by `--limit`;
 *  2. marks each one finished with `result = 'abandoned'` — only when the
 *     result is still the untouched default `pending`, so a real outcome is
 *     never overwritten — records the reason and timestamp inside the
 *     session's `metadata`, and leaves the money columns untouched;
 *  3. runs the read-only Gameberry wallet reconciliation for every affected
 *     user and reports balanced/unbalanced.
 *
 * What it must never do: move money. Closing a session is bookkeeping; a
 * wallet is only ever changed through WalletService with a ledger entry and an
 * actor. An unbalanced wallet is reported for a human, never "fixed" silently.
 *
 * `--dry-run` reports the work without writing anything.
 */
class ReconcileGameSessions extends Command
{
    /**
     * The result value written for a session that never concluded. Chosen so
     * it cannot be confused with a real match outcome ('win'/'loss').
     */
    public const RESULT_ABANDONED = 'abandoned';

    /**
     * The result value a session carries before an outcome is known.
     */
    public const RESULT_PENDING = 'pending';

    protected $signature = 'ffarena:gameberry:reconcile-sessions
        {--limit= : Maximum number of stale sessions to close in this run}
        {--stale-minutes= : Override the staleness threshold (minutes)}
        {--dry-run : Report what would be closed without writing anything}';

    protected $description = 'Close stale Gameberry sessions (abandoned, no money movement) and reconcile affected wallets';

    public function handle(
        GameberryReconciliationService $reconciliation,
        AuditLogService $audit,
    ): int {
        $dryRun = (bool) $this->option('dry-run');

        $limit = $this->resolveLimit();

        if ($limit === null) {
            return self::FAILURE;
        }

        $staleMinutes = $this->resolveStaleMinutes();

        if ($staleMinutes === null) {
            return self::FAILURE;
        }

        $cutoff = Carbon::now()->subMinutes($staleMinutes);

        $sessions = GameSession::query()
            ->whereNull('finished_at')
            ->whereNotNull('started_at')
            ->where('started_at', '<=', $cutoff)
            ->orderBy('started_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $this->info(sprintf(
            '%s: %d stale session(s) older than %d minutes (limit %d).',
            $dryRun ? 'Dry run' : 'Reconciling',
            $sessions->count(),
            $staleMinutes,
            $limit,
        ));

        $closed = 0;
        $startedWithoutTimestamp = 0;
        $affectedUsers = [];
        $unbalanced = [];

        foreach ($sessions as $session) {
            $affectedUsers[(int) $session->user_id] = true;

            if ((string) $session->result !== self::RESULT_PENDING && (string) $session->result !== '') {
                // The session already carries a real outcome; leave it alone
                // and let a human investigate why it was never closed.
                $startedWithoutTimestamp++;

                $this->line(sprintf(
                    '  session #%d already has result "%s" — left untouched for review',
                    $session->id,
                    (string) $session->result,
                ));

                if (! $dryRun) {
                    $audit->recordQuietly(null, 'game_session.reconciled', 'game_session', (int) $session->id, [
                        'target_user_id' => (int) $session->user_id,
                        'metadata' => [
                            'command' => 'ffarena:gameberry:reconcile-sessions',
                            'outcome' => 'skipped_non_pending_result',
                            'result' => (string) $session->result,
                        ],
                    ]);
                }

                continue;
            }

            $this->line(sprintf(
                '  %s session #%d (user %d, started %s)',
                $dryRun ? 'would close' : 'closing',
                $session->id,
                $session->user_id,
                (string) $session->started_at,
            ));

            if ($dryRun) {
                $closed++;

                continue;
            }

            DB::transaction(function () use ($session, $audit, &$closed) {
                $locked = GameSession::query()
                    ->whereKey($session->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($locked === null || $locked->finished_at !== null) {
                    // Another worker closed it first — idempotent no-op.
                    return;
                }

                if ((string) $locked->result !== self::RESULT_PENDING && (string) $locked->result !== '') {
                    // Re-checked under the lock: a real result landed while we
                    // were waiting.
                    return;
                }

                $metadata = is_array($locked->metadata) ? $locked->metadata : [];

                $metadata['reconciliation'] = [
                    'closed_by' => 'ffarena:gameberry:reconcile-sessions',
                    'closed_at' => now()->toIso8601String(),
                    'reason' => 'stale_open_session',
                    'previous_result' => self::RESULT_PENDING,
                    'money_moved' => false,
                ];

                $locked->finished_at = now();
                $locked->result = self::RESULT_ABANDONED;
                $locked->metadata = $metadata;

                // Duration is the only derived value recomputed: money columns
                // (gold_change/gem_change/trophies_change/bet_amount) are left
                // exactly as recorded.
                if ($locked->started_at !== null && (int) ($locked->duration_seconds ?? 0) === 0) {
                    $locked->duration_seconds = max(0, (int) $locked->started_at->diffInSeconds(now()));
                }

                $locked->save();

                $audit->recordQuietly(null, 'game_session.reconciled', 'game_session', (int) $locked->id, [
                    'target_user_id' => (int) $locked->user_id,
                    'metadata' => [
                        'command' => 'ffarena:gameberry:reconcile-sessions',
                        'outcome' => 'closed_stale_session',
                        'money_moved' => false,
                    ],
                ]);

                $closed++;
            });
        }

        if ($affectedUsers !== []) {
            $this->newLine();
            $this->info('Wallet reconciliation for affected users (read-only):');

            foreach (array_keys($affectedUsers) as $userId) {
                $result = $reconciliation->reconcileAll((int) $userId);

                if ($result['all_balanced']) {
                    $this->line(sprintf('  user %d: balanced', $userId));

                    continue;
                }

                $unbalanced[] = $userId;

                $this->warn(sprintf('  user %d: UNBALANCED — must be reviewed, not auto-corrected', $userId));
            }
        }

        if (! $dryRun) {
            $audit->recordQuietly(null, 'game_session.reconciled', 'game_session', null, [
                'metadata' => [
                    'command' => 'ffarena:gameberry:reconcile-sessions',
                    'closed' => $closed,
                    'skipped' => $startedWithoutTimestamp,
                    'unbalanced_users' => count($unbalanced),
                ],
            ]);
        }

        $this->newLine();
        $this->info(sprintf(
            '%s complete: %d session(s) %s, %d skipped, %d unbalanced wallet(s).',
            $dryRun ? 'Dry run' : 'Reconciliation',
            $closed,
            $dryRun ? 'would be closed' : 'closed',
            $startedWithoutTimestamp,
            count($unbalanced),
        ));

        return self::SUCCESS;
    }

    protected function resolveLimit(): ?int
    {
        $configured = (int) config('gameberry.sessions.reconcile_batch', 200);

        $option = $this->option('limit');

        if ($option === null || $option === '') {
            return max(1, $configured);
        }

        if (! is_numeric($option) || (int) $option < 1) {
            $this->error('--limit must be a positive integer.');

            return null;
        }

        return (int) $option;
    }

    protected function resolveStaleMinutes(): ?int
    {
        $configured = (int) config('gameberry.sessions.stale_minutes', 180);

        $option = $this->option('stale-minutes');

        if ($option === null || $option === '') {
            return max(1, $configured);
        }

        if (! is_numeric($option) || (int) $option < 1) {
            $this->error('--stale-minutes must be a positive integer.');

            return null;
        }

        return (int) $option;
    }
}
