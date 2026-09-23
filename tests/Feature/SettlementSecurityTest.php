<?php

namespace Tests\Feature;

use App\Models\FinancialSettlement;
use App\Models\GameMatch;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\PrizeDistribution;
use App\Models\PrizeSnapshotItem;
use App\Models\PrizeTier;
use App\Models\Score;
use App\Models\SettlementAdjustment;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\PayoutService;
use App\Services\PrizeDistributionService;
use App\Services\WalletService;
use DomainException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 09 — settlement/payout security: authorization, tampering, mass
 * assignment, cross-tournament access and duplicate prevention.
 */
class SettlementSecurityTest extends TestCase
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
        $t->name = $o['name'] ?? 'Security Tournament';
        $t->slug = $o['slug'] ?? ('sec-'.Str::random(8));
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

    protected function addScore(Tournament $tournament, Team $team, int $kills): Score
    {
        $match = new GameMatch();
        $match->tournament_id = $tournament->id;
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

    protected function distributions(): PrizeDistributionService
    {
        return app(PrizeDistributionService::class);
    }

    protected function payouts(): PayoutService
    {
        return app(PayoutService::class);
    }

    protected function wallets(): WalletService
    {
        return app(WalletService::class);
    }

    protected function settledDistribution(Tournament $t, User $admin, Team $team, string $prizeValue = '5000'): PrizeDistribution
    {
        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => $prizeValue],
        ], $admin);
        $this->distributions()->calculate($t, $admin);
        $this->distributions()->approve($t, $admin);

        return PrizeDistribution::where('tournament_id', $t->id)->firstOrFail();
    }

    // ------------------------------------------------------------------
    // Authorization
    // ------------------------------------------------------------------

    public function test_guest_cannot_access_settlement_pages(): void
    {
        $this->get(route('admin.settlements.index'))->assertRedirect(route('login'));
        $this->get(route('admin.payouts.index'))->assertRedirect(route('login'));
    }

    public function test_organizer_cannot_access_settlement_pages(): void
    {
        $org = $this->makeUser('organizer');

        $this->actingAs($org)->get(route('admin.settlements.index'))->assertForbidden();
        $this->actingAs($org)->get(route('admin.payouts.index'))->assertForbidden();
    }

    public function test_player_cannot_access_settlement_pages(): void
    {
        $player = $this->makeUser('player');

        $this->actingAs($player)->get(route('admin.settlements.index'))->assertForbidden();
        $this->actingAs($player)->get(route('admin.payouts.index'))->assertForbidden();
    }

    public function test_organizer_cannot_calculate_or_approve_or_process(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished');
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);

        $this->actingAs($org)->post(route('admin.settlements.calculate', $t))->assertForbidden();
        $this->actingAs($org)->post(route('admin.settlements.approve', $t))->assertForbidden();
        $this->actingAs($org)->post(route('admin.settlements.process', $t))->assertForbidden();
        $this->actingAs($org)->post(route('admin.settlements.prizes', $t), [])->assertForbidden();
        $this->actingAs($org)->post(route('admin.settlements.adjust', $t), [])->assertForbidden();
    }

    public function test_player_cannot_act_on_payouts(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished');
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);

        $this->settledDistribution($t, $admin, $team);
        $payout = Payout::where('tournament_id', $t->id)->firstOrFail();

        $this->actingAs($captain)->post(route('admin.payouts.process', $payout))->assertForbidden();
        $this->actingAs($captain)->post(route('admin.payouts.approve', $payout))->assertForbidden();
        $this->actingAs($captain)->post(route('admin.payouts.cancel', $payout))->assertForbidden();
        $this->actingAs($captain)->post(route('admin.payouts.fail', $payout))->assertForbidden();
    }

    public function test_snapshot_only_contains_teams_of_its_tournament(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $capA = $this->makeUser('player');
        $capB = $this->makeUser('player');

        $tA = $this->makeTournament($org, 'finished', ['prize_pool' => 5000, 'name' => 'Alpha']);
        $teamA = $this->makeTeam($tA, $capA);
        $this->addScore($tA, $teamA, 10);

        // A second finished tournament with a foreign team + scores.
        $tB = $this->makeTournament($org, 'finished', ['prize_pool' => 5000, 'name' => 'Beta']);
        $teamB = $this->makeTeam($tB, $capB);
        $this->addScore($tB, $teamB, 99);

        $this->distributions()->saveTiers($tA, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);

        $dist = $this->distributions()->calculate($tA, $admin);

        $teamIds = $dist->snapshotItems()->pluck('team_id')->all();
        $this->assertContains($teamA->id, $teamIds);
        $this->assertNotContains($teamB->id, $teamIds);
    }

    // ------------------------------------------------------------------
    // Mass assignment
    // ------------------------------------------------------------------

    public function test_payout_fields_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        (new Payout())->fill([
            'distribution_id' => 1,
            'tournament_id' => 1,
            'recipient_user_id' => 1,
            'rank' => 1,
            'amount_minor' => 999999,
            'status' => 'completed',
        ]);
    }

    public function test_prize_tier_fields_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        (new PrizeTier())->fill([
            'tournament_id' => 1,
            'position' => 1,
            'type' => 'fixed',
            'amount_minor' => 999999,
        ]);
    }

    public function test_distribution_fields_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        (new PrizeDistribution())->fill([
            'tournament_id' => 1,
            'status' => 'completed',
            'pool_minor' => 999999,
        ]);
    }

    public function test_snapshot_item_fields_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        (new PrizeSnapshotItem())->fill([
            'distribution_id' => 1,
            'position' => 1,
            'amount_minor' => 999999,
        ]);
    }

    public function test_financial_settlement_fields_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        (new FinancialSettlement())->fill([
            'tournament_id' => 1,
            'net_collected_minor' => 999999,
            'reconciliation_status' => 'balanced',
        ]);
    }

    public function test_settlement_adjustment_fields_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        (new SettlementAdjustment())->fill([
            'tournament_id' => 1,
            'amount_minor' => -999999,
        ]);
    }

    // ------------------------------------------------------------------
    // State machine + idempotency
    // ------------------------------------------------------------------

    public function test_completed_payout_cannot_be_reprocessed_or_cancelled(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished');
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);

        $this->settledDistribution($t, $admin, $team);
        $payout = Payout::where('tournament_id', $t->id)->firstOrFail();
        $this->payouts()->process($payout, $admin);

        $payout->refresh();
        $this->assertSame(Payout::STATUS_COMPLETED, $payout->status);
        $this->assertTrue($payout->isTerminal());

        // completed has no outgoing transitions.
        $this->assertFalse($payout->canTransitionTo(Payout::STATUS_PROCESSING));
        $this->assertFalse($payout->canTransitionTo(Payout::STATUS_APPROVED));

        // Re-processing is idempotent — no new credit.
        $again = $this->payouts()->process($payout, $admin);
        $this->assertSame(Payout::STATUS_COMPLETED, $again->status);

        // Cancelling a completed payout is refused.
        $this->expectException(DomainException::class);
        $this->payouts()->cancel($payout->fresh(), $admin);
    }

    public function test_completed_distribution_cannot_be_processed_again(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished');
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);
        $this->distributions()->calculate($t, $admin);
        $this->distributions()->approve($t, $admin);
        $dist = $this->distributions()->process($t, $admin);
        $this->assertSame(PrizeDistribution::STATUS_COMPLETED, $dist->status);

        // Re-processing returns the completed distribution without new effects.
        $again = $this->distributions()->process($t, $admin);
        $this->assertSame($dist->id, $again->id);
        $this->assertSame(1, Payout::where('tournament_id', $t->id)->count());
    }

    public function test_calculated_distribution_cannot_be_completed_directly(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished');
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
        ], $admin);
        $dist = $this->distributions()->calculate($t, $admin);
        $this->assertSame(PrizeDistribution::STATUS_CALCULATED, $dist->status);

        // completed is not a legal transition from calculated.
        $this->assertFalse($dist->canTransitionTo(PrizeDistribution::STATUS_COMPLETED));
        $this->assertFalse($dist->canTransitionTo(PrizeDistribution::STATUS_PROCESSING));

        $this->expectException(DomainException::class);
        $this->distributions()->process($t, $admin);
    }

    // ------------------------------------------------------------------
    // Payout integrity under tampering
    // ------------------------------------------------------------------

    public function test_payout_rank_and_amount_come_only_from_the_snapshot(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 5000]);
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);

        // Try to influence the payout via bogus request data — it is ignored.
        $this->actingAs($admin)->post(route('admin.settlements.prizes', $t), [
            'tiers' => [
                1 => ['position' => 1, 'type' => 'fixed', 'value' => '5000'],
            ],
            'payout_amount' => 1,
            'recipient_user_id' => 99999,
        ])->assertRedirect();

        $this->distributions()->calculate($t, $admin);
        $this->distributions()->approve($t, $admin);

        $payout = Payout::where('tournament_id', $t->id)->firstOrFail();
        $this->assertSame($captain->id, $payout->recipient_user_id);
        $this->assertSame(1, $payout->rank);
        $this->assertSame(500000, $payout->amount_minor);
    }

    public function test_prize_pool_tampering_is_rejected_by_validation(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $t = $this->makeTournament($org, 'finished', ['prize_pool' => 100]);

        // A tier larger than the pool is rejected server-side.
        $this->expectException(DomainException::class);

        $this->distributions()->saveTiers($t, [
            ['position' => 1, 'type' => 'fixed', 'value' => '999999'],
        ], $admin);
    }

    public function test_wallet_balance_is_only_mutated_by_wallet_service(): void
    {
        $org = $this->makeUser('organizer');
        $admin = $this->makeUser('admin');
        $captain = $this->makeUser('player');
        $t = $this->makeTournament($org, 'finished');
        $team = $this->makeTeam($t, $captain);
        $this->addScore($t, $team, 10);

        $this->settledDistribution($t, $admin, $team);
        $payout = Payout::where('tournament_id', $t->id)->firstOrFail();
        $this->payouts()->process($payout, $admin);

        $wallet = $this->wallets()->walletFor($captain);
        $this->assertSame(500000, $wallet->balanceMinor());
        $this->assertSame(0, $this->wallets()->reconciliationDelta($wallet));
        $this->assertSame(1, $wallet->ledgerEntries()->where('type', LedgerEntry::TYPE_PAYOUT)->count());
    }
}
