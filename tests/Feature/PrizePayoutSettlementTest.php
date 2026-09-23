<?php

namespace Tests\Feature;

use App\Models\Dispute;
use App\Models\FinancialSettlement;
use App\Models\GameMatch;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\PrizeDistribution;
use App\Models\PrizeSnapshotItem;
use App\Models\PrizeTier;
use App\Models\Score;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PayoutService;
use App\Services\PrizeDistributionService;
use App\Services\ReconciliationService;
use App\Services\WalletService;
use App\Support\Money;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 09 — prize configuration, snapshots, standings, eligibility, payouts,
 * wallet integration and financial reconciliation.
 */
class PrizePayoutSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->save();

        return $u;
    }

    protected function makeTournament(User $organizer, string $status = 'finished', array $o = []): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = $o['name'] ?? 'Settlement Tournament';
        $t->slug = $o['slug'] ?? ('settle-'.Str::random(8));
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = $o['entry_fee'] ?? 100;
        $t->prize_pool = $o['prize_pool'] ?? 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->starts_at = now()->subDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function makeTeam(Tournament $tournament, ?User $captain, string $status = 'confirmed'): Team
    {
        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain?->id;
        $team->name = 'Team '.Str::random(6);
        $team->captain_name = $captain?->name ?? 'Captain';
        $team->phone = '01700000000';
        $team->game_uid = 'UID'.strtoupper(Str::random(8));
        $team->status = $status;
        $team->save();

        return $team;
    }

    /**
     * Attach a completed match + score to a team so the Phase 06 standings
     * engine ranks it. Kills control total points (placement 1 = 12 points).
     */
    protected function addScore(Tournament $tournament, Team $team, int $kills): Score
    {
        $match = new GameMatch();
        $match->tournament_id = $tournament->id;
        $match->round = 1;
        $match->match_no = 1;
        $match->team1_id = $team->id;
        $match->winner_team_id = $team->id;
        $match->status = GameMatch::STATUS_COMPLETED;
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

    protected function makePayment(Tournament $t, Team $team, User $payer, int $minor, string $status = 'verified'): Payment
    {
        $p = new Payment();
        $p->tournament_id = $t->id;
        $p->team_id = $team->id;
        $p->payer_user_id = $payer->id;
        $p->amount_minor = $minor;
        $p->amount = Money::toDecimal($minor);
        $p->currency = 'BDT';
        $p->method = 'bkash';
        $p->trx_id = 'TX'.Str::random(6);
        $p->provider = 'bkash';
        $p->provider_reference = $p->trx_id;
        $p->status = $status;
        $p->paid_at = now();
        $p->save();

        return $p;
    }

    protected function distributions(): PrizeDistributionService
    {
        return app(PrizeDistributionService::class);
    }

    protected function payouts(): PayoutService
    {
        return app(PayoutService::class);
    }

    protected function reconciliation(): ReconciliationService
    {
        return app(ReconciliationService::class);
    }

    protected function wallets(): WalletService
    {
        return app(WalletService::class);
    }

    // ------------------------------------------------------------------
    // Prize configuration
    // ------------------------------------------------------------------

    public function test_valid_fixed_prize_configuration_is_saved(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '3000'],
            ['position' => 2, 'type' => 'fixed', 'value' => '1500'],
            ['position' => 3, 'type' => 'fixed', 'value' => '500'],
        ], $admin);

        $this->assertSame(3, PrizeTier::where('tournament_id', $t->id)->count());
        $this->assertSame(300000, PrizeTier::where('tournament_id', $t->id)->where('position', 1)->firstOrFail()->amount_minor);
    }

    public function test_valid_percentage_configuration_resolves_against_pool(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 5);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'percentage', 'value' => '60'],
            ['position' => 2, 'type' => 'percentage', 'value' => '40'],
        ], $admin);

        $dist = $this->distributions()->calculate($t, $admin);

        $first = PrizeSnapshotItem::where('distribution_id', $dist->id)->where('position', 1)->firstOrFail();
        $this->assertSame(300000, $first->amount_minor); // 60% of ৳5000
    }

    public function test_negative_fixed_prize_is_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);

        $this->expectException(DomainException::class);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '-500'],
        ], $admin);
    }

    public function test_negative_percentage_is_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);

        $this->expectException(DomainException::class);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'percentage', 'value' => '-10'],
        ], $admin);
    }

    public function test_percentages_over_100_are_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);

        $this->expectException(DomainException::class);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'percentage', 'value' => '60'],
            ['position' => 2, 'type' => 'percentage', 'value' => '50'],
        ], $admin);
    }

    public function test_duplicate_position_is_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);

        $this->expectException(DomainException::class);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '1000'],
            ['position' => 1, 'type' => 'fixed', 'value' => '500'],
        ], $admin);
    }

    public function test_allocation_exceeding_pool_is_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);

        $this->expectException(DomainException::class);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '6000'],
        ], $admin);
    }

    public function test_invalid_position_is_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);

        $this->expectException(DomainException::class);

        $this->distributions()->saveTiers($t, [
            ['position' => 0, 'type' => 'fixed', 'value' => '1000'],
        ], $admin);
    }

    // ------------------------------------------------------------------
    // Standings + snapshot
    // ------------------------------------------------------------------

    public function test_correct_winner_and_amount_are_snapshotted(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $capA = $this->makeUser('player');
        $capB = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $teamA = $this->makeTeam($t, $capA);
        $teamB = $this->makeTeam($t, $capB);
        $this->addScore($t, $teamA, 10); // 22 pts → rank 1
        $this->addScore($t, $teamB, 5);  // 17 pts → rank 2

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '4000'],
            ['position' => 2, 'type' => 'fixed', 'value' => '1000'],
        ], $admin);

        $dist = $this->distributions()->calculate($t, $admin);

        $snapshot = $dist->snapshotItems()->orderBy('position')->get();
        $this->assertCount(2, $snapshot);
        $this->assertSame(1, $snapshot[0]->position);
        $this->assertSame($teamA->id, $snapshot[0]->team_id);
        $this->assertSame(400000, $snapshot[0]->amount_minor);
        $this->assertSame(2, $snapshot[1]->position);
        $this->assertSame($teamB->id, $snapshot[1]->team_id);
        $this->assertSame(100000, $snapshot[1]->amount_minor);
    }

    public function test_ties_are_broken_deterministically(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $capA = $this->makeUser('player');
        $capB = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $teamA = $this->makeTeam($t, $capA); // created first → lower id
        $teamB = $this->makeTeam($t, $capB);
        $this->addScore($t, $teamA, 3);
        $this->addScore($t, $teamB, 3);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);

        $dist = $this->distributions()->calculate($t, $admin);

        $first = $dist->snapshotItems()->where('position', 1)->firstOrFail();
        $this->assertSame($teamA->id, $first->team_id);
    }

    public function test_snapshot_is_immutable_after_calculation(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'percentage', 'value' => '50'],
        ], $admin);

        $dist = $this->distributions()->calculate($t, $admin);
        $before = $dist->snapshotItems()->firstOrFail()->amount_minor;

        // Editing the declared pool afterwards never rewrites the snapshot.
        $t->prize_pool = 99999;
        $t->save();

        $this->assertSame($before, $dist->fresh()->snapshotItems()->firstOrFail()->amount_minor);

        // Tier edits are locked once calculated.
        try {
            $this->distributions()->saveTiers($t, [
                ['position' => 1, 'type' => 'percentage', 'value' => '99'],
            ], $admin);
            $this->fail('Expected tier edits to be locked after calculation.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('locked', $e->getMessage());
        }

        // Re-calculating is idempotent — the snapshot is untouched.
        $again = $this->distributions()->calculate($t, $admin);
        $this->assertSame($dist->id, $again->id);
        $this->assertSame($before, $again->snapshotItems()->firstOrFail()->amount_minor);
    }

    // ------------------------------------------------------------------
    // Eligibility
    // ------------------------------------------------------------------

    public function test_open_tournament_blocks_distribution(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $t = $this->makeTournament($org, 'open');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('finished');

        $this->distributions()->calculate($t, $admin);
    }

    public function test_cancelled_tournament_blocks_distribution(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $t = $this->makeTournament($org, 'cancelled');

        $this->expectException(DomainException::class);

        $this->distributions()->calculate($t, $admin);
    }

    public function test_incomplete_match_blocks_distribution(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished');
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 5);

        $live = new GameMatch();
        $live->tournament_id = $t->id;
        $live->status = GameMatch::STATUS_LIVE;
        $live->save();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('completed');

        $this->distributions()->calculate($t, $admin);
    }

    public function test_unresolved_dispute_blocks_distribution(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished');
        $team = $this->makeTeam($t, $captain);
        $score = $this->addScore($t, $team, 5);

        $dispute = new Dispute();
        $dispute->tournament_id = $t->id;
        $dispute->match_id = $score->match_id;
        $dispute->opened_by = $org->id;
        $dispute->category = 'wrong_winner';
        $dispute->description = 'Result contested';
        $dispute->status = Dispute::STATUS_OPEN;
        $dispute->save();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('dispute');

        $this->distributions()->calculate($t, $admin);
    }

    public function test_unresolved_standings_block_distribution(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $t = $this->makeTournament($org, 'finished'); // no scores

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('standings');

        $this->distributions()->calculate($t, $admin);
    }

    // ------------------------------------------------------------------
    // Payout + wallet integration
    // ------------------------------------------------------------------

    public function test_payout_recipient_is_the_teams_captain(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);

        $this->distributions()->calculate($t, $admin);
        $this->distributions()->approve($t, $admin);

        $payout = Payout::where('tournament_id', $t->id)->firstOrFail();
        $this->assertSame($captain->id, $payout->recipient_user_id);
        $this->assertSame($team->id, $payout->recipient_team_id);
        $this->assertSame(1, $payout->rank);
        $this->assertSame(500000, $payout->amount_minor);
        $this->assertSame('BDT', $payout->currency);
    }

    public function test_payout_credits_wallet_and_writes_ledger(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);
        $this->distributions()->calculate($t, $admin);
        $this->distributions()->approve($t, $admin);
        $this->distributions()->process($t, $admin);

        $wallet = $this->wallets()->walletFor($captain);
        $this->assertSame(500000, $wallet->balanceMinor());

        $entry = LedgerEntry::where('wallet_id', $wallet->id)->where('type', LedgerEntry::TYPE_PAYOUT)->firstOrFail();
        $this->assertSame(LedgerEntry::DIRECTION_CREDIT, $entry->direction);
        $this->assertSame(500000, $entry->amount_minor);
        $this->assertSame(0, $this->wallets()->reconciliationDelta($wallet));
    }

    public function test_payout_is_never_processed_twice(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);
        $this->distributions()->calculate($t, $admin);
        $this->distributions()->approve($t, $admin);
        $this->distributions()->process($t, $admin);

        // Re-processing the completed distribution must be a no-op.
        $this->distributions()->process($t, $admin);

        $wallet = $this->wallets()->walletFor($captain);
        $this->assertSame(500000, $wallet->balanceMinor());
        $this->assertSame(1, LedgerEntry::where('wallet_id', $wallet->id)->where('type', LedgerEntry::TYPE_PAYOUT)->count());
    }

    public function test_frozen_wallet_fails_payout_and_distribution(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);

        $wallet = $this->wallets()->walletFor($captain);
        $wallet->status = Wallet::STATUS_FROZEN;
        $wallet->save();

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);
        $this->distributions()->calculate($t, $admin);
        $this->distributions()->approve($t, $admin);
        $dist = $this->distributions()->process($t, $admin);

        $this->assertSame(PrizeDistribution::STATUS_FAILED, $dist->status);

        $payout = Payout::where('tournament_id', $t->id)->firstOrFail();
        $this->assertSame(Payout::STATUS_FAILED, $payout->status);
        $this->assertSame(0, $wallet->fresh()->balanceMinor());
    }

    public function test_duplicate_payout_for_same_rank_is_prevented(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);
        $this->distributions()->calculate($t, $admin);

        // Approving twice must not create duplicate payouts for the same rank.
        $this->distributions()->approve($t, $admin);
        $this->distributions()->approve($t, $admin);

        $this->assertSame(1, Payout::where('tournament_id', $t->id)->where('rank', 1)->count());
    }

    public function test_manual_payout_requires_manual_completion(): void
    {
        config(['finance.default_payout_provider' => 'manual']);

        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);
        $this->distributions()->calculate($t, $admin);
        $this->distributions()->approve($t, $admin);

        $payout = Payout::where('tournament_id', $t->id)->firstOrFail();
        $this->assertSame(Payout::METHOD_MANUAL, $payout->payout_method);

        $this->distributions()->process($t, $admin);

        // Manual payout stays processing; no wallet credit; distribution not complete.
        $payout->refresh();
        $this->assertSame(Payout::STATUS_PROCESSING, $payout->status);
        $this->assertSame(0, $this->wallets()->walletFor($captain)->balanceMinor());
        $this->assertSame(PrizeDistribution::STATUS_PROCESSING, PrizeDistribution::where('tournament_id', $t->id)->firstOrFail()->status);

        $this->payouts()->completeManually($payout, $admin, 'REF-123');
        $this->assertSame(Payout::STATUS_COMPLETED, $payout->fresh()->status);
        $this->assertSame('REF-123', $payout->fresh()->provider_reference);
    }

    // ------------------------------------------------------------------
    // Reconciliation
    // ------------------------------------------------------------------

    public function test_gross_refund_and_net_collection_are_computed(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $capA = $this->makeUser('player');
        $capB = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $teamA = $this->makeTeam($t, $capA);
        $teamB = $this->makeTeam($t, $capB);

        $this->makePayment($t, $teamA, $capA, 500000, Payment::STATUS_VERIFIED);
        $this->makePayment($t, $teamB, $capB, 500000, Payment::STATUS_REFUNDED);

        $summary = $this->reconciliation()->summary($t);

        $this->assertSame(1000000, $summary['gross_collected_minor']);
        $this->assertSame(500000, $summary['refunded_minor']);
        $this->assertSame(500000, $summary['net_collected_minor']);
    }

    public function test_reconciliation_reports_underfunded(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);
        $this->distributions()->calculate($t, $admin);

        // No entry fees collected → allocated prizes exceed net collection.
        $summary = $this->reconciliation()->summary($t);
        $this->assertSame(FinancialSettlement::STATUS_UNDERFUNDED, $summary['reconciliation_status']);
    }

    public function test_reconciliation_reports_balanced_after_settlement(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);
        $this->makePayment($t, $team, $captain, 500000, Payment::STATUS_VERIFIED);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);
        $this->distributions()->calculate($t, $admin);
        $this->distributions()->approve($t, $admin);
        $this->distributions()->process($t, $admin);

        $summary = $this->reconciliation()->summary($t);
        $this->assertSame(FinancialSettlement::STATUS_BALANCED, $summary['reconciliation_status']);
    }

    public function test_reconciliation_reports_overallocated(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 100]);

        // Data anomaly: allocation exceeds the declared pool.
        $dist = new PrizeDistribution();
        $dist->tournament_id = $t->id;
        $dist->status = PrizeDistribution::STATUS_COMPLETED;
        $dist->pool_minor = 10000;
        $dist->total_allocated_minor = 20000;
        $dist->save();

        $item = new PrizeSnapshotItem();
        $item->distribution_id = $dist->id;
        $item->tournament_id = $t->id;
        $item->position = 1;
        $item->team_id = null;
        $item->type = 'fixed';
        $item->amount_minor = 20000;
        $item->save();

        $summary = $this->reconciliation()->summary($t);
        $this->assertSame(FinancialSettlement::STATUS_OVERALLOCATED, $summary['reconciliation_status']);
    }

    public function test_reconciliation_reports_mismatch_before_completion(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);
        $this->makePayment($t, $team, $captain, 500000, Payment::STATUS_VERIFIED);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);
        $this->distributions()->calculate($t, $admin);

        // Calculated but not yet processed → mismatch.
        $summary = $this->reconciliation()->summary($t);
        $this->assertSame(FinancialSettlement::STATUS_MISMATCH, $summary['reconciliation_status']);
    }

    public function test_commission_defaults_to_zero(): void
    {
        $this->assertSame(0, $this->reconciliation()->platformRevenueMinor(100000));
    }

    public function test_percentage_commission_is_applied_in_basis_points(): void
    {
        config(['finance.commission.type' => 'percentage']);
        config(['finance.commission.percentage_bp' => 800]); // 8%

        $this->assertSame(8000, $this->reconciliation()->platformRevenueMinor(100000));

        config(['finance.commission.percentage_bp' => 0]);
    }

    public function test_fixed_commission_cannot_exceed_revenue(): void
    {
        config(['finance.commission.type' => 'fixed']);
        config(['finance.commission.fixed_minor' => 999999]);

        $this->assertSame(100000, $this->reconciliation()->platformRevenueMinor(100000));

        config(['finance.commission.type' => 'percentage']);
        config(['finance.commission.fixed_minor' => 0]);
    }

    public function test_adjustments_flow_into_reconciliation(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);
        $this->makePayment($t, $team, $captain, 500000, Payment::STATUS_VERIFIED);

        $this->reconciliation()->addAdjustment($t, 10000, 'correction', 'Sponsor top-up', $admin);

        $summary = $this->reconciliation()->summary($t);
        $this->assertSame(10000, $summary['adjustments_minor']);
        $this->assertSame(490000, $summary['remaining_minor']);
    }

    public function test_settlement_snapshot_is_frozen_once_finalized(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);
        $this->makePayment($t, $team, $captain, 500000, Payment::STATUS_VERIFIED);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);
        $this->distributions()->calculate($t, $admin);
        $this->distributions()->approve($t, $admin);
        $this->distributions()->process($t, $admin);

        $settlement = FinancialSettlement::where('tournament_id', $t->id)->firstOrFail();
        $this->assertSame(500000, $settlement->net_collected_minor);
        $this->assertSame($admin->id, $settlement->finalized_by);

        // A second finalize never overwrites the frozen snapshot.
        $this->reconciliation()->finalize($t, $admin, PrizeDistribution::where('tournament_id', $t->id)->firstOrFail());
        $this->assertSame(1, FinancialSettlement::where('tournament_id', $t->id)->count());

        // Post-finalization adjustments are refused.
        $this->expectException(DomainException::class);
        $this->reconciliation()->addAdjustment($t, 100, 'correction', 'late edit', $admin);
    }

    // ------------------------------------------------------------------
    // Full HTTP flow
    // ------------------------------------------------------------------

    public function test_full_distribution_flow_via_http(): void
    {
        $admin = $this->makeUser('admin');
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);

        $this->actingAs($admin)->post(route('admin.settlements.prizes', $t), [
            'tiers' => [
                1 => ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
            ],
        ])->assertRedirect();

        $this->assertSame(1, PrizeTier::where('tournament_id', $t->id)->count());

        $this->actingAs($admin)->post(route('admin.settlements.calculate', $t))->assertRedirect();
        $dist = PrizeDistribution::where('tournament_id', $t->id)->firstOrFail();
        $this->assertSame(PrizeDistribution::STATUS_CALCULATED, $dist->status);

        $this->actingAs($admin)->post(route('admin.settlements.approve', $t))->assertRedirect();
        $payout = Payout::where('tournament_id', $t->id)->firstOrFail();
        $this->assertSame(Payout::STATUS_APPROVED, $payout->status);
        $this->assertSame($captain->id, $payout->recipient_user_id);

        $this->actingAs($admin)->post(route('admin.settlements.process', $t))->assertRedirect();

        $payout->refresh();
        $dist->refresh();
        $this->assertSame(Payout::STATUS_COMPLETED, $payout->status);
        $this->assertSame(PrizeDistribution::STATUS_COMPLETED, $dist->status);
        $this->assertSame(500000, $this->wallets()->walletFor($captain)->balanceMinor());
        $this->assertNotNull(FinancialSettlement::where('tournament_id', $t->id)->first());
    }

    public function test_user_sees_only_their_own_payouts_on_wallet_page(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $capA = $this->makeUser('player');
        $capB = $this->makeUser('player');

        $tA = $this->makeTournament($org, 'finished', ['prize_pool' => 5000, 'name' => 'Alpha Cup']);
        $teamA = $this->makeTeam($tA, $capA);
        $this->addScore($tA, $teamA, 10);
        $this->distributions()->saveTiers($tA, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);
        $this->distributions()->calculate($tA, $admin);
        $this->distributions()->approve($tA, $admin);
        $this->distributions()->process($tA, $admin);

        $tB = $this->makeTournament($org, 'finished', ['prize_pool' => 5000, 'name' => 'Beta Cup']);
        $teamB = $this->makeTeam($tB, $capB);
        $this->addScore($tB, $teamB, 10);
        $this->distributions()->saveTiers($tB, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);
        $this->distributions()->calculate($tB, $admin);
        $this->distributions()->approve($tB, $admin);
        $this->distributions()->process($tB, $admin);

        $response = $this->actingAs($capA)->get(route('wallet.index'))->assertOk();
        $response->assertSee('Alpha Cup');
        $response->assertDontSee('Beta Cup');
    }
}
