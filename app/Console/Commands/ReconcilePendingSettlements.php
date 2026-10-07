<?php

namespace App\Console\Commands;

use App\Jobs\ReconcileSettlement;
use App\Models\FinancialSettlement;
use App\Models\Tournament;
use App\Services\AuditLogService;
use App\Services\Finance\AtomicSettlementService;
use App\Services\Finance\LedgerIntegrityService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * GAP-10 A8 (tracker row 055) — dispatch reconciliation for pending
 * settlements.
 *
 * "Pending" means a settlement that is not reconciled as balanced, plus any
 * tournament whose prize distribution completed without a settlement snapshot
 * at all. Each is handed to App\Jobs\ReconcileSettlement, which re-checks the
 * figures and never moves money.
 *
 * Bounded by design:
 *  - `--limit` (default from config, hard-capped) bounds how many jobs one run
 *    dispatches, so a backlog cannot flood the queue;
 *  - oldest first, so nothing starves;
 *  - `--dry-run` lists exactly what would be dispatched and writes nothing.
 *
 * The command is scheduled with `onOneServer()->withoutOverlapping()`, so two
 * schedulers cannot work the same backlog at once.
 */
class ReconcilePendingSettlements extends Command
{
    protected $signature = 'ffarena:settle:reconcile-pending
        {--limit= : Maximum settlements to dispatch in this run}
        {--dry-run : Report what would be dispatched without dispatching or writing anything}
        {--with-ledger : Also run the read-only ledger integrity check and report the result}';

    protected $description = 'Dispatch reconciliation for settlements that are not balanced yet (never moves money)';

    public function handle(
        AtomicSettlementService $settlements,
        AuditLogService $audit,
        LedgerIntegrityService $ledger,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->resolveLimit();

        if ($limit === null) {
            return self::FAILURE;
        }

        $rows = $settlements->pendingSettlements($limit);
        $missing = $settlements->tournamentsMissingSnapshot($limit);

        $this->info(sprintf(
            '%s: %d pending settlement(s), %d tournament(s) missing a snapshot (limit %d).',
            $dryRun ? 'Dry run' : 'Reconciling',
            $rows->count(),
            $missing->count(),
            $limit,
        ));

        foreach ($rows as $settlement) {
            $this->line(sprintf(
                '  %s settlement #%d (tournament %d, status %s)',
                $dryRun ? 'would dispatch' : 'dispatching',
                $settlement->id,
                $settlement->tournament_id,
                (string) ($settlement->reconciliation_status ?? 'unset'),
            ));

            if (! $dryRun) {
                ReconcileSettlement::dispatch((int) $settlement->id, null);
            }
        }

        foreach ($missing as $tournament) {
            $this->line(sprintf(
                '  %s reconciliation for tournament %d (completed distribution, no settlement snapshot)',
                $dryRun ? 'would dispatch' : 'dispatching',
                $tournament->id,
            ));

            if (! $dryRun) {
                ReconcileSettlement::dispatch(null, (int) $tournament->id);
            }
        }

        $dispatched = $dryRun ? 0 : $rows->count() + $missing->count();

        if ($this->option('with-ledger')) {
            $report = $ledger->verify();
            $line = $ledger->summarise($report);

            $this->newLine();

            if ($report['ok']) {
                $this->info('Ledger: '.$line);
            } else {
                $this->warn('Ledger: '.$line);
            }
        }

        if (! $dryRun) {
            $audit->recordQuietly(null, 'settlement.reconciled', 'financial_settlement', null, [
                'metadata' => [
                    'command' => 'ffarena:settle:reconcile-pending',
                    'dispatched' => $dispatched,
                    'pending' => $rows->count(),
                    'missing_snapshots' => $missing->count(),
                ],
            ]);
        }

        $this->info($dryRun
            ? 'Dry run complete: nothing was dispatched and nothing was written.'
            : "Dispatched {$dispatched} reconciliation job(s).");

        return self::SUCCESS;
    }

    /**
     * Resolve the batch size: an explicit --limit wins, but it is always
     * clamped to the configured hard ceiling.
     */
    protected function resolveLimit(): ?int
    {
        $configured = (int) config('gameberry.settlements.reconcile_batch', 50);
        $maximum = (int) config('gameberry.settlements.reconcile_batch_max', 500);

        $option = $this->option('limit');

        if ($option === null || $option === '') {
            return max(1, min($configured, $maximum));
        }

        if (! is_numeric($option) || (int) $option < 1) {
            $this->error('--limit must be a positive integer.');

            return null;
        }

        $requested = (int) $option;

        if ($requested > $maximum) {
            $this->warn("--limit {$requested} exceeds the configured ceiling of {$maximum}; using {$maximum}.");
        }

        return max(1, min($requested, $maximum));
    }

    /**
     * Exposed for the scheduler/tests: the settlements a run would work on.
     *
     * @return array{0: Collection<int, FinancialSettlement>, 1: Collection<int, Tournament>}
     */
    public function pending(int $limit): array
    {
        /** @var AtomicSettlementService $settlements */
        $settlements = app(AtomicSettlementService::class);

        return [$settlements->pendingSettlements($limit), $settlements->tournamentsMissingSnapshot($limit)];
    }
}
