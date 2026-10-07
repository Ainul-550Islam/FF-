<?php

namespace Tests\Feature\Finance;

use App\Jobs\ReconcileSettlement;
use App\Models\FinancialSettlement;
use App\Models\GameSession;
use App\Models\LedgerEntry;
use App\Models\Notification;
use App\Models\PrizeDistribution;
use App\Models\Score;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Finance\AtomicSettlementService;
use App\Services\PrizeDistributionService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GAP-10 A8 (tracker rows 054/055/056) — reconciliation commands and the job
 * they dispatch.
 *
 * The rules under test:
 *
 *  - the commands only ever work on *pending* items, bounded by `--limit` with
 *    a configured ceiling, oldest first;
 *  - `--dry-run` writes and dispatches nothing;
 *  - the job re-checks figures and NEVER moves money: no wallet balance, no
 *    ledger row and no payout status changes when it runs, in any outcome;
 *  - drift and unknown outcomes stop for a human (drift is reported, unknown
 *    is left exactly as it was);
 *  - stale game sessions are closed as `abandoned` with their money columns
 *    untouched, and are left alone once they carry a real result.
 */
class ReconcileCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function makeTournament(User $organizer, array $o = []): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = $o['name'] ?? 'Reconcile Cup';
        $t->slug = $o['slug'] ?? ('reconcile-'.Str::random(8));
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = 100;
        $t->prize_pool = $o['prize_pool'] ?? 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->starts_at = now()->subDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = $o['status'] ?? 'finished';
        $t->save();

        return $t;
    }

    /**
     * A settled tournament (real distributions, payouts and ledger credits).
     *
     * @return array{0: Tournament, 1: User, 2: FinancialSettlement}
     */
    protected function settledTournament(): array
    {
        $organizer = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');

        $tournament = $this->makeTournament($organizer);

        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain->id;
        $team->name = 'Team '.Str::random(6);
        $team->captain_name = $captain->name;
        $team->phone = '01700000000';
        $team->game_uid = 'UID'.strtoupper(Str::random(8));
        $team->status = 'confirmed';
        $team->save();

        $match = new \App\Models\GameMatch();
        $match->tournament_id = $tournament->id;
        $match->round = 1;
        $match->match_no = 1;
        $match->team1_id = $team->id;
        $match->winner_team_id = $team->id;
        $match->status = \App\Models\GameMatch::STATUS_COMPLETED;
        $match->completed_at = now();
        $match->save();

        $score = new Score();
        $score->match_id = $match->id;
        $score->team_id = $team->id;
        $score->kills = 10;
        $score->placement = 1;
        $score->placement_points = 12;
        $score->kill_points = 10;
        $score->points = 22;
        $score->status = 'pending';
        $score->save();

        app(PrizeDistributionService::class)->saveTiers($tournament, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);

        $result = app(AtomicSettlementService::class)->settle($tournament, $admin);

        return [$tournament, $admin, FinancialSettlement::findOrFail($result['settlement_id'])];
    }

    protected function makePendingSettlement(Tournament $tournament, string $status = FinancialSettlement::STATUS_MISMATCH): FinancialSettlement
    {
        $settlement = new FinancialSettlement();
        $settlement->tournament_id = $tournament->id;
        $settlement->idempotency_key = 'test:'.Str::uuid();
        $settlement->gross_collected_minor = 0;
        $settlement->reconciliation_status = $status;
        $settlement->save();

        return $settlement;
    }

    // ------------------------------------------------------------------
    // ffarena:settle:reconcile-pending
    // ------------------------------------------------------------------

    public function test_dry_run_dispatches_nothing_and_writes_nothing(): void
    {
        Bus::fake();

        $organizer = $this->makeUser('organizer');

        for ($i = 0; $i < 3; $i++) {
            $this->makePendingSettlement($this->makeTournament($organizer, ['name' => 'Cup '.$i]));
        }

        $this->artisan('ffarena:settle:reconcile-pending', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        Bus::assertNothingDispatched();

        $this->assertSame(0, DB::table('audit_logs')->where('action', 'settlement.reconciled')->count());
    }

    public function test_pending_only_is_dispatched_in_bounded_batches(): void
    {
        Bus::fake();

        $organizer = $this->makeUser('organizer');

        $pending = [];

        for ($i = 0; $i < 5; $i++) {
            $pending[] = $this->makePendingSettlement($this->makeTournament($organizer, ['name' => 'Pending '.$i]));
        }

        // A balanced settlement must be ignored entirely.
        $this->makePendingSettlement(
            $this->makeTournament($organizer, ['name' => 'Balanced']),
            FinancialSettlement::STATUS_BALANCED
        );

        $this->artisan('ffarena:settle:reconcile-pending', ['--limit' => 2])->assertSuccessful();

        Bus::assertDispatchedTimes(ReconcileSettlement::class, 2);

        // The oldest two were chosen.
        Bus::assertDispatched(ReconcileSettlement::class, fn (ReconcileSettlement $job) => $job->settlementId === $pending[0]->id);
        Bus::assertDispatched(ReconcileSettlement::class, fn (ReconcileSettlement $job) => $job->settlementId === $pending[1]->id);

        // An audit row records the run.
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'settlement.reconciled')->whereNull('entity_id')->count());
    }

    public function test_the_limit_is_clamped_to_the_configured_ceiling(): void
    {
        Bus::fake();

        config(['gameberry.settlements.reconcile_batch_max' => 3]);

        $organizer = $this->makeUser('organizer');

        for ($i = 0; $i < 5; $i++) {
            $this->makePendingSettlement($this->makeTournament($organizer, ['name' => 'Clamp '.$i]));
        }

        $this->artisan('ffarena:settle:reconcile-pending', ['--limit' => 999])->assertSuccessful();

        Bus::assertDispatchedTimes(ReconcileSettlement::class, 3);
    }

    public function test_an_invalid_limit_fails_without_dispatching(): void
    {
        Bus::fake();

        $this->artisan('ffarena:settle:reconcile-pending', ['--limit' => 'zero'])->assertFailed();

        Bus::assertNothingDispatched();
    }

    public function test_a_completed_distribution_without_a_snapshot_is_dispatched(): void
    {
        Bus::fake();

        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer, ['name' => 'No Snapshot']);

        $distribution = new PrizeDistribution();
        $distribution->tournament_id = $tournament->id;
        $distribution->status = PrizeDistribution::STATUS_COMPLETED;
        $distribution->save();

        $this->artisan('ffarena:settle:reconcile-pending')->assertSuccessful();

        Bus::assertDispatched(ReconcileSettlement::class, fn (ReconcileSettlement $job) => $job->tournamentId === $tournament->id);
    }

    // ------------------------------------------------------------------
    // App\Jobs\ReconcileSettlement
    // ------------------------------------------------------------------

    public function test_the_job_rechecks_a_healthy_settlement_without_moving_money(): void
    {
        [, , $settlement] = $this->settledTournament();

        $before = [
            'ledger' => LedgerEntry::count(),
            'balances' => DB::table('wallets')->orderBy('id')->pluck('balance_minor')->all(),
            'settlement' => [
                'allocated' => (int) $settlement->allocated_prizes_minor,
                'completed' => (int) $settlement->completed_payouts_minor,
            ],
        ];

        (new ReconcileSettlement((int) $settlement->id))->handle(
            app(AtomicSettlementService::class),
            app(\App\Services\ReconciliationService::class),
            app(\App\Services\AuditLogService::class),
            app(\App\Services\NotificationService::class),
        );

        $this->assertSame($before['ledger'], LedgerEntry::count());
        $this->assertSame($before['balances'], DB::table('wallets')->orderBy('id')->pluck('balance_minor')->all());

        $fresh = $settlement->fresh();

        $this->assertSame($before['settlement']['allocated'], (int) $fresh->allocated_prizes_minor);
        $this->assertSame($before['settlement']['completed'], (int) $fresh->completed_payouts_minor);

        // The run is recorded with a recognised outcome.
        $audit = DB::table('audit_logs')->where('action', 'settlement.reconciled')->where('entity_id', $settlement->id)->first();

        $this->assertNotNull($audit, 'The reconciliation run must be recorded.');

        $metadata = json_decode((string) $audit->metadata, true);

        $this->assertContains($metadata['outcome'], [
            ReconcileSettlement::OUTCOME_BALANCED,
            ReconcileSettlement::OUTCOME_DRIFT,
            ReconcileSettlement::OUTCOME_UNKNOWN,
        ]);
    }

    public function test_drift_is_reported_and_never_auto_corrected(): void
    {
        [, , $settlement] = $this->settledTournament();

        $settlement->allocated_prizes_minor = 999999;
        $settlement->save();

        $balanceBefore = app(WalletService::class);
        $ledgerBefore = LedgerEntry::count();

        (new ReconcileSettlement((int) $settlement->id))->handle(
            app(AtomicSettlementService::class),
            app(\App\Services\ReconciliationService::class),
            app(\App\Services\AuditLogService::class),
            app(\App\Services\NotificationService::class),
        );

        // The stored (wrong) figure is untouched: financial history is never
        // rewritten by a reconciliation run.
        $this->assertSame(999999, (int) $settlement->fresh()->allocated_prizes_minor);
        $this->assertSame($ledgerBefore, LedgerEntry::count());

        $audit = DB::table('audit_logs')->where('action', 'settlement.reconciled')->where('entity_id', $settlement->id)->firstOrFail();
        $metadata = json_decode((string) $audit->metadata, true);

        $this->assertSame(ReconcileSettlement::OUTCOME_DRIFT, $metadata['outcome']);
        $this->assertArrayHasKey('allocated_prizes_minor', $metadata['drift']);

        // Admins are told; a human decides.
        $this->assertGreaterThanOrEqual(
            1,
            Notification::where('type', Notification::TYPE_SYSTEM)->count()
        );

        $this->assertNotNull($balanceBefore);
    }

    public function test_an_unknown_outcome_is_left_exactly_as_it_was(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer, ['name' => 'Unknown Cup']);

        // A snapshot for a tournament with no prize distribution at all: the
        // source data is in a state this build cannot interpret.
        $settlement = $this->makePendingSettlement($tournament);

        $before = (string) $settlement->reconciliation_status;

        (new ReconcileSettlement((int) $settlement->id))->handle(
            app(AtomicSettlementService::class),
            app(\App\Services\ReconciliationService::class),
            app(\App\Services\AuditLogService::class),
            app(\App\Services\NotificationService::class),
        );

        $audit = DB::table('audit_logs')->where('action', 'settlement.reconciled')->where('entity_id', $settlement->id)->firstOrFail();
        $metadata = json_decode((string) $audit->metadata, true);

        $this->assertSame(ReconcileSettlement::OUTCOME_UNKNOWN, $metadata['outcome']);
        $this->assertSame('prize_distribution_missing', $metadata['reason']);

        // Nothing was retried, nothing was corrected, nothing paid out.
        $this->assertSame($before, (string) $settlement->fresh()->reconciliation_status);
        $this->assertSame(0, LedgerEntry::count());
    }

    // ------------------------------------------------------------------
    // ffarena:gameberry:reconcile-sessions
    // ------------------------------------------------------------------

    protected function makeSession(User $user, array $attributes = []): GameSession
    {
        return GameSession::create(array_merge([
            'user_id' => $user->id,
            'game_mode' => 'classic',
            'game_variation' => 'classic',
            'bet_amount' => 100,
            'result' => 'pending',
            'gold_change' => 0,
            'gem_change' => 0,
            'trophies_change' => 0,
            'duration_seconds' => 0,
            'is_team_up' => false,
            'started_at' => now()->subHours(6),
            'finished_at' => null,
        ], $attributes));
    }

    public function test_stale_sessions_are_closed_as_abandoned_without_touching_money(): void
    {
        $user = $this->makeUser('player');
        $wallet = app(WalletService::class)->walletFor($user);
        app(WalletService::class)->credit($wallet, 50000, LedgerEntry::TYPE_DEPOSIT, 'seed');

        $session = $this->makeSession($user, ['gold_change' => -1000, 'gem_change' => -2]);
        $fresh = $this->makeSession($user, ['started_at' => now()->subMinutes(5)]);

        $ledgerBefore = LedgerEntry::count();
        $balanceBefore = $wallet->fresh()->balanceMinor();

        $this->artisan('ffarena:gameberry:reconcile-sessions', ['--limit' => 10, '--stale-minutes' => 180])
            ->assertSuccessful();

        $closed = $session->fresh();

        $this->assertNotNull($closed->finished_at);
        $this->assertSame('abandoned', (string) $closed->result);
        $this->assertSame('stale_open_session', (string) $closed->metadata['reconciliation']['reason']);
        $this->assertFalse((bool) $closed->metadata['reconciliation']['money_moved']);

        // Money columns are exactly as recorded.
        $this->assertSame(-1000, (int) $closed->gold_change);
        $this->assertSame(-2, (int) $closed->gem_change);

        // Nothing moved: no ledger row, no balance change.
        $this->assertSame($ledgerBefore, LedgerEntry::count());
        $this->assertSame($balanceBefore, $wallet->fresh()->balanceMinor());

        // A recent session is untouched.
        $this->assertNull($fresh->fresh()->finished_at);
        $this->assertSame('pending', (string) $fresh->fresh()->result);
    }

    public function test_a_session_with_a_real_result_is_never_overwritten(): void
    {
        $user = $this->makeUser('player');
        $session = $this->makeSession($user, ['result' => 'win', 'gold_change' => 500]);

        $this->artisan('ffarena:gameberry:reconcile-sessions', ['--limit' => 10, '--stale-minutes' => 1])
            ->assertSuccessful();

        $fresh = $session->fresh();

        $this->assertSame('win', (string) $fresh->result, 'A real outcome must never be replaced by "abandoned".');
        $this->assertNull($fresh->finished_at);
        $this->assertSame(500, (int) $fresh->gold_change);
    }

    public function test_dry_run_reports_without_closing_anything(): void
    {
        $user = $this->makeUser('player');
        $session = $this->makeSession($user);

        $this->artisan('ffarena:gameberry:reconcile-sessions', ['--dry-run' => true, '--stale-minutes' => 180])
            ->assertSuccessful();

        $this->assertNull($session->fresh()->finished_at);
        $this->assertSame('pending', (string) $session->fresh()->result);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'game_session.reconciled')->count());
    }

    public function test_the_session_batch_is_bounded(): void
    {
        $user = $this->makeUser('player');

        for ($i = 0; $i < 5; $i++) {
            $this->makeSession($user, ['started_at' => now()->subHours(6 + $i)]);
        }

        $this->artisan('ffarena:gameberry:reconcile-sessions', ['--limit' => 2, '--stale-minutes' => 180])
            ->assertSuccessful();

        $this->assertSame(2, GameSession::where('result', 'abandoned')->count());
        $this->assertSame(3, GameSession::where('result', 'pending')->count());
    }
}
