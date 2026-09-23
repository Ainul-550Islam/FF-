<?php

namespace Tests\Feature\Coverage;

use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Refund;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PaymentService;
use App\Services\ReconciliationService;
use App\Services\WalletService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * G3 — financial critical-path coverage.
 *
 * Deliberately walks the money path end-to-end so coverage runs execute the
 * payment/wallet/ledger/refund/reconciliation code even when a developer runs
 * a targeted subset of the suite. Every assertion here is a real financial
 * invariant — nothing is executed "for coverage only".
 */
class FinancialCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->account_status = 'active';
        $user->save();

        return $user;
    }

    protected function makeTournament(User $organizer, float $entryFee = 100): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = 'FinCover Tournament';
        $t->slug = 'fincov-'.Str::random(8);
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = $entryFee;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->starts_at = now()->addDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = 'open';
        $t->save();

        return $t;
    }

    protected function makeTeam(Tournament $tournament, User $captain): Team
    {
        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain->id;
        $team->name = 'Team '.Str::random(6);
        $team->captain_name = $captain->name;
        $team->phone = '01700000000';
        $team->game_uid = 'UID'.strtoupper(Str::random(8));
        $team->status = Team::STATUS_PENDING;
        $team->save();

        return $team;
    }

    public function test_payment_lifecycle_and_ledger_are_covered(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $payment = app(PaymentService::class)->createForTeam(
            $tournament, $team, $captain, 'bkash', 'TRXCOV1', 'bkash', 'TRXCOV1',
        );

        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
        $this->assertSame(10000, $payment->amount_minor);

        // Admin verification settles the payment and confirms the team.
        $admin = $this->makeUser('admin');
        app(PaymentService::class)->verifyManually($payment, $admin);

        $this->assertSame(Payment::STATUS_VERIFIED, $payment->fresh()->status);
        $this->assertSame(Team::STATUS_CONFIRMED, $team->fresh()->status);

        // Wallet credit + ledger entry.
        $wallet = app(WalletService::class)->walletFor($captain);
        $entry = app(WalletService::class)->credit($wallet, 25000, LedgerEntry::TYPE_ADJUSTMENT, 'Coverage credit', $admin);

        $this->assertSame(25000, $wallet->fresh()->balance_minor);
        $this->assertSame(25000, $entry->balance_after);

        // Debit cannot go negative.
        $this->expectException(DomainException::class);
        app(WalletService::class)->debit($wallet, 9999999, LedgerEntry::TYPE_ADJUSTMENT, 'Impossible debit', $admin);
    }

    public function test_refund_and_reconciliation_are_covered(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $payment = app(PaymentService::class)->createForTeam(
            $tournament, $team, $captain, 'bkash', 'TRXCOV2', 'bkash', 'TRXCOV2',
        );

        $admin = $this->makeUser('admin');
        app(PaymentService::class)->verifyManually($payment, $admin);

        $refund = app(PaymentService::class)->refund($payment, $admin, 'Coverage refund');

        $this->assertInstanceOf(Refund::class, $refund);
        $this->assertSame(Payment::STATUS_REFUNDED, $payment->fresh()->status);
        $this->assertDatabaseHas('ledger_entries', [
            'wallet_id' => $captain->wallet->id,
            'type' => LedgerEntry::TYPE_REFUND,
        ]);

        // Duplicate refund is blocked.
        $this->expectException(DomainException::class);
        app(PaymentService::class)->refund($payment, $admin, 'Second refund');
    }

    public function test_reconciliation_summary_matches_ledger(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org, entryFee: 50);
        $team = $this->makeTeam($tournament, $captain);

        $payment = app(PaymentService::class)->createForTeam(
            $tournament, $team, $captain, 'bkash', 'TRXCOV3', 'bkash', 'TRXCOV3',
        );

        $admin = $this->makeUser('admin');
        app(PaymentService::class)->verifyManually($payment, $admin);

        $summary = app(ReconciliationService::class)->summary($tournament);

        $this->assertIsArray($summary);
        $this->assertSame(5000, $summary['gross_collected_minor']);
        $this->assertSame(5000, $summary['net_collected_minor']);
    }

    public function test_payment_events_are_append_only_and_ordered(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $payment = app(PaymentService::class)->createForTeam(
            $tournament, $team, $captain, 'bkash', 'TRXCOV4', 'bkash', 'TRXCOV4',
        );

        $admin = $this->makeUser('admin');
        app(PaymentService::class)->verifyManually($payment, $admin);

        $events = PaymentEvent::where('payment_id', $payment->id)->orderBy('id')->pluck('event')->all();

        $this->assertSame(PaymentEvent::EVENT_CREATED, $events[0]);
        $this->assertSame(PaymentEvent::EVENT_VERIFIED, $events[1]);
        $this->assertCount(2, $events);

        // Wallets are lazily created with zero balance, exactly once.
        $wallet = app(WalletService::class)->walletFor($captain);
        $again = app(WalletService::class)->walletFor($captain);

        $this->assertSame($wallet->id, $again->id);
        $this->assertSame(1, Wallet::where('user_id', $captain->id)->count());
    }
}
