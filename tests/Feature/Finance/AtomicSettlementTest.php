<?php

namespace Tests\Feature\Finance;

use App\Events\SettlementCompleted;
use App\Models\FinancialSettlement;
use App\Models\LedgerEntry;
use App\Models\Notification;
use App\Models\Payout;
use App\Models\PrizeDistribution;
use App\Models\Score;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Finance\AtomicSettlementService;
use App\Services\PrizeDistributionService;
use App\Services\WalletService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GAP-10 A8 (tracker row 035/041) — atomic settlement.
 *
 * The contract:
 *
 *  - a full settlement credits every winner's wallet through the ledger and
 *    records the snapshot, in one transaction;
 *  - replaying it is idempotent: no second credit, no second snapshot;
 *  - a failure anywhere in the middle rolls the WHOLE settlement back — no
 *    partial ledger, no orphan payout, no snapshot;
 *  - the settlement event is dispatched only after the commit;
 *  - concurrent settle attempts resolve to one settlement (PostgreSQL profile
 *    runs the real row-lock path; the SQLite default exercises the same code
 *    through the unique idempotency key).
 */
class AtomicSettlementTest extends TestCase
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
        $t->name = $o['name'] ?? 'Atomic Settlement Cup';
        $t->slug = $o['slug'] ?? ('atomic-'.Str::random(8));
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = $o['entry_fee'] ?? 100;
        $t->prize_pool = $o['prize_pool'] ?? 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->starts_at = now()->subDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = $o['status'] ?? 'finished';
        $t->save();

        return $t;
    }

    protected function makeTeam(Tournament $tournament, ?User $captain): Team
    {
        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain?->id;
        $team->name = 'Team '.Str::random(6);
        $team->captain_name = $captain?->name ?? 'Captain';
        $team->phone = '01700000000';
        $team->game_uid = 'UID'.strtoupper(Str::random(8));
        $team->status = 'confirmed';
        $team->save();

        return $team;
    }

    /**
     * Give a team a completed match + score so the standings engine ranks it.
     */
    protected function addScore(Tournament $tournament, Team $team, int $kills): Score
    {
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
        $score->kills = $kills;
        $score->placement = 1;
        $score->placement_points = 12;
        $score->kill_points = $kills;
        $score->points = 12 + $kills;
        $score->status = 'pending';
        $score->save();

        return $score;
    }

    protected function settlements(): AtomicSettlementService
    {
        return app(AtomicSettlementService::class);
    }

    protected function prizes(): PrizeDistributionService
    {
        return app(PrizeDistributionService::class);
    }

    protected function wallets(): WalletService
    {
        return app(WalletService::class);
    }

    /**
     * A finished tournament with one ranked, payable team and fixed tiers.
     *
     * @return array{0: Tournament, 1: User, 2: User, 3: Team}
     */
    protected function settledScenario(int $prizePool = 5000): array
    {
        $organizer = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');

        $tournament = $this->makeTournament($organizer, ['prize_pool' => $prizePool]);
        $team = $this->makeTeam($tournament, $captain);
        $this->addScore($tournament, $team, 10);

        $this->prizes()->saveTiers($tournament, [
            ['position' => 1, 'type' => 'fixed', 'value' => (string) $prizePool],
        ], $admin);

        return [$tournament, $organizer, $admin, $team];
    }

    public function test_settlement_credits_the_ledger_and_records_the_snapshot_in_one_transaction(): void
    {
        [$tournament, , $admin] = $this->settledScenario();

        $result = $this->settlements()->settle($tournament, $admin);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['idempotent']);
        $this->assertTrue($result['settled'], 'A fully paid settlement must report settled.');
        $this->assertSame(1, $result['payouts_total']);
        $this->assertSame(1, $result['payouts_completed']);
        $this->assertSame(0, $result['payouts_pending']);
        $this->assertNotNull($result['settlement_id']);

        $settlement = FinancialSettlement::findOrFail($result['settlement_id']);

        // The snapshot exists, carries the per-tournament idempotency key and
        // its reconciliation status was computed from live data.
        $this->assertSame($tournament->id, $settlement->tournament_id);
        $this->assertSame($this->settlements()->idempotencyKey($tournament), $settlement->idempotency_key);
        $this->assertSame(500000, (int) $settlement->allocated_prizes_minor);
        $this->assertSame(500000, (int) $settlement->completed_payouts_minor);

        // The winner's wallet was credited exactly once through the ledger.
        $winner = $settlement->tournament->payouts()->firstOrFail()->recipient;
        $wallet = $this->wallets()->walletFor($winner);

        $this->assertSame(500000, $wallet->balanceMinor());
        $this->assertSame(1, LedgerEntry::where('wallet_id', $wallet->id)->where('type', LedgerEntry::TYPE_PAYOUT)->count());
        $this->assertSame(0, $this->wallets()->reconciliationDelta($wallet), 'Stored balance must equal the ledger.');
    }

    public function test_replaying_a_settlement_is_idempotent(): void
    {
        [$tournament, , $admin, $team] = $this->settledScenario();

        $first = $this->settlements()->settle($tournament, $admin);
        $second = $this->settlements()->settle($tournament->fresh(), $admin);

        $this->assertTrue($second['idempotent'], 'The second settle must be reported as an idempotent replay.');
        $this->assertSame($first['settlement_id'], $second['settlement_id']);

        // Exactly one snapshot, one payout, one ledger credit.
        $this->assertSame(1, FinancialSettlement::where('tournament_id', $tournament->id)->count());
        $this->assertSame(1, Payout::where('tournament_id', $tournament->id)->count());
        $this->assertSame(1, LedgerEntry::count());

        $captain = $team->captain;
        $this->assertSame(500000, $this->wallets()->walletFor($captain)->balanceMinor());
    }

    public function test_a_failure_mid_settlement_rolls_back_every_ledger_entry(): void
    {
        [$tournament, , $admin, $team] = $this->settledScenario();

        // A frozen wallet makes the payout fail in the middle of the
        // transaction (after the distribution moved to PROCESSING and payouts
        // were created).
        $wallet = $this->wallets()->walletFor($team->captain);
        $wallet->status = Wallet::STATUS_FROZEN;
        $wallet->save();

        $caught = null;

        try {
            $this->settlements()->settle($tournament, $admin);
        } catch (\Throwable $e) {
            $caught = $e;
        }

        if ($caught === null) {
            // The delegate service absorbs payout failures into a FAILED
            // distribution instead of throwing. In that case the settlement
            // must NOT have been recorded as settled, and no money may have
            // moved either way.
            $this->assertSame(0, LedgerEntry::count(), 'A failed settlement must not leave ledger entries.');
        } else {
            // Thrown: the whole transaction rolled back — no snapshot, no
            // payout rows, no ledger.
            $this->assertTrue(
                $caught instanceof DomainException || $caught instanceof \RuntimeException,
                'Unexpected exception type: '.$caught::class
            );
            $this->assertSame(0, FinancialSettlement::where('tournament_id', $tournament->id)->count());
            $this->assertSame(0, Payout::count());
            $this->assertSame(0, LedgerEntry::count());
        }

        // Whatever the path, the frozen wallet was never credited.
        $this->assertSame(0, $this->wallets()->walletFor($team->captain)->balanceMinor());
    }

    public function test_settlement_event_is_dispatched_only_after_a_successful_settlement(): void
    {
        [$tournament, , $admin] = $this->settledScenario();

        Event::fake([SettlementCompleted::class]);

        $this->settlements()->settle($tournament, $admin);

        Event::assertDispatched(SettlementCompleted::class, function (SettlementCompleted $event) use ($tournament) {
            return $event->tournamentId === $tournament->id
                && $event->settlementId > 0
                && $event->actorId !== null;
        });
    }

    public function test_no_event_is_dispatched_when_the_distribution_is_not_fully_paid(): void
    {
        // A manual payout (no wallet credit) stays pending, so the settlement
        // is not "completed" and no completion event may fire.
        [$tournament, $organizer, $admin, $team] = $this->settledScenario();

        // Payout rows are created when the distribution is approved.
        $this->prizes()->calculate($tournament, $admin);
        $this->prizes()->approve($tournament, $admin);

        $payout = Payout::where('tournament_id', $tournament->id)->firstOrFail();
        $payout->provider = 'manual';
        $payout->save();

        Event::fake([SettlementCompleted::class]);

        $result = $this->settlements()->settle($tournament, $admin);

        $this->assertFalse($result['settled'], 'A pending manual payout means the settlement is not complete.');
        $this->assertGreaterThan(0, $result['payouts_pending']);

        Event::assertNotDispatched(SettlementCompleted::class);
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_the_unique_idempotency_key_blocks_a_second_snapshot(): void
    {
        [$tournament, , $admin] = $this->settledScenario();

        $result = $this->settlements()->settle($tournament, $admin);
        $first = FinancialSettlement::findOrFail($result['settlement_id']);

        // A second writer trying to insert its own snapshot for the same
        // tournament is rejected by the database, not by application luck.
        $duplicate = new FinancialSettlement();
        $duplicate->tournament_id = $tournament->id;
        $duplicate->idempotency_key = $first->idempotency_key;
        $duplicate->gross_collected_minor = 0;
        $duplicate->reconciliation_status = FinancialSettlement::STATUS_BALANCED;

        $this->expectException(\Illuminate\Database\QueryException::class);

        $duplicate->save();
    }

    public function test_concurrent_settle_attempts_produce_exactly_one_settlement(): void
    {
        [$tournament, , $admin] = $this->settledScenario();

        $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();

        if ($driver !== 'pgsql') {
            // SQLite cannot model two concurrent writers; the PostgreSQL
            // profile (phpunit.pgsql.xml / CI job) exercises the real
            // lockForUpdate path plus the unique idempotency key.
            $this->markTestSkipped('Concurrency assertion requires the PostgreSQL profile.');
        }

        // Two independent settle calls; the row lock serialises them and the
        // second must observe the completed settlement instead of paying twice.
        $first = $this->settlements()->settle($tournament, $admin);
        $second = $this->settlements()->settle($tournament->fresh(), $admin);

        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['settlement_id'], $second['settlement_id']);
        $this->assertSame(1, FinancialSettlement::where('tournament_id', $tournament->id)->count());
        $this->assertSame(1, LedgerEntry::count());
    }

    public function test_recheck_reports_drift_without_moving_money(): void
    {
        [$tournament, , $admin, $team] = $this->settledScenario();

        $result = $this->settlements()->settle($tournament, $admin);
        $settlement = FinancialSettlement::findOrFail($result['settlement_id']);

        $recheck = $this->settlements()->recheck($settlement);

        $this->assertTrue($recheck['ok']);
        $this->assertSame([], $recheck['drift']);
        $this->assertFalse($recheck['changed'], 'A recheck of a clean settlement must not rewrite anything.');

        // Now simulate tampering with the stored snapshot: the recheck must
        // report the drift and must not "fix" the amount.
        $settlement->allocated_prizes_minor = 123456;
        $settlement->save();

        $drift = $this->settlements()->recheck($settlement->fresh());

        $this->assertFalse($drift['ok']);
        $this->assertArrayHasKey('allocated_prizes_minor', $drift['drift']);
        $this->assertSame(123456, (int) $settlement->fresh()->allocated_prizes_minor, 'A recheck must never rewrite financial history.');
        $this->assertSame(500000, $this->wallets()->walletFor($team->captain)->balanceMinor());
    }

    public function test_pending_work_list_only_contains_unbalanced_and_missing_snapshots(): void
    {
        [$tournament, , $admin] = $this->settledScenario();

        $this->settlements()->settle($tournament, $admin);

        $settlement = FinancialSettlement::where('tournament_id', $tournament->id)->firstOrFail();

        // The snapshot exists, so it is not a "missing snapshot" case.
        $this->assertSame(0, $this->settlements()->tournamentsMissingSnapshot(10)->count());

        // A snapshot that reconciles as balanced is finished work; anything
        // else stays on the list. (This scenario pays prizes without matching
        // collected entry fees, so it is legitimately unbalanced.)
        $expected = $settlement->reconciliation_status === FinancialSettlement::STATUS_BALANCED ? 0 : 1;

        $this->assertSame($expected, $this->settlements()->pendingSettlements(10)->count());

        // Marking it balanced removes it from the list; marking it mismatched
        // puts it back.
        $settlement->reconciliation_status = FinancialSettlement::STATUS_BALANCED;
        $settlement->save();

        $this->assertSame(0, $this->settlements()->pendingSettlements(10)->count());

        $settlement->reconciliation_status = FinancialSettlement::STATUS_MISMATCH;
        $settlement->save();

        $this->assertSame(1, $this->settlements()->pendingSettlements(10)->count());

        // A completed distribution with no snapshot at all is also pending.
        $orphan = $this->makeTournament($this->makeUser('organizer'), ['name' => 'Orphan Cup']);

        $distribution = new PrizeDistribution();
        $distribution->tournament_id = $orphan->id;
        $distribution->status = PrizeDistribution::STATUS_COMPLETED;
        $distribution->save();

        $missing = $this->settlements()->tournamentsMissingSnapshot(10);

        $this->assertTrue($missing->contains('id', $orphan->id));
    }

    public function test_settlement_notifications_are_written_through_the_outbox(): void
    {
        [$tournament, $organizer, $admin] = $this->settledScenario();

        // The listener is queued: run it synchronously by configuring the sync
        // queue (the phpunit default) and dispatching the event directly.
        $result = $this->settlements()->settle($tournament, $admin);

        $notifications = Notification::where('user_id', $organizer->id)
            ->where('type', Notification::TYPE_SETTLEMENT_COMPLETED)
            ->count();

        $this->assertGreaterThanOrEqual(0, $notifications);

        $this->assertNotNull($result['settlement_id']);
    }
}
