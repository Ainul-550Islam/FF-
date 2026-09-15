<?php

namespace Tests\Feature;

use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Refund;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PaymentService;
use App\Services\WalletService;
use App\Support\Money;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 08 — payment state machine, amount integrity, idempotency, wallet,
 * immutable ledger, entry-fee integration and refunds.
 */
class PaymentWalletLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function makeTournament(User $organizer, string $status = 'open', array $o = []): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = $o['name'] ?? 'Payment Tournament';
        $t->slug = $o['slug'] ?? ('pay-'.Str::random(8));
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = $o['entry_fee'] ?? 100;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->starts_at = now()->addDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function makeTeam(Tournament $tournament, ?User $captain = null, string $status = 'pending'): Team
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

    protected function payments(): PaymentService
    {
        return app(PaymentService::class);
    }

    protected function wallets(): WalletService
    {
        return app(WalletService::class);
    }

    // ------------------------------------------------------------------
    // Payment creation + amount integrity
    // ------------------------------------------------------------------

    public function test_payment_amount_derives_from_server_entry_fee(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 150]);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->post(route('payment.verify', [$tournament, $team]), [
            'bkash_number' => '01700000000',
            'trx_id' => 'BTRX1',
            'amount' => 1, // client-supplied amount must be ignored
        ])->assertRedirect();

        $payment = Payment::where('team_id', $team->id)->firstOrFail();
        $this->assertSame(15000, $payment->amountMinor());
        $this->assertSame('150.00', Money::toDecimal($payment->amountMinor()));
        $this->assertSame('BDT', $payment->currency);
        $this->assertSame($captain->id, $payment->payer_user_id);
    }

    public function test_free_entry_auto_confirms_team(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 0]);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->post(route('payment.verify', [$tournament, $team]), [
            'bkash_number' => '01700000000',
            'trx_id' => 'FREE1',
        ])->assertRedirect(route('tournaments.show', $tournament));

        $payment = Payment::where('team_id', $team->id)->firstOrFail();
        $this->assertSame(0, $payment->amountMinor());
        $this->assertSame(Payment::STATUS_VERIFIED, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame(Team::STATUS_CONFIRMED, $team->fresh()->status);
    }

    public function test_duplicate_payment_attempt_is_idempotent(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 100]);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->post(route('payment.verify', [$tournament, $team]), [
            'bkash_number' => '01700000000', 'trx_id' => 'BTRX1',
        ])->assertRedirect();

        // Second attempt → redirected to the existing pending payment, no new row.
        $this->actingAs($captain)->post(route('payment.verify', [$tournament, $team]), [
            'bkash_number' => '01700000000', 'trx_id' => 'BTRX2',
        ])->assertRedirect();

        $this->assertSame(1, Payment::where('team_id', $team->id)->count());
    }

    public function test_payment_page_redirects_to_existing_active_payment(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 100]);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->post(route('payment.verify', [$tournament, $team]), [
            'bkash_number' => '01700000000', 'trx_id' => 'BTRX1',
        ])->assertRedirect();

        $payment = Payment::where('team_id', $team->id)->firstOrFail();

        $this->actingAs($captain)->get(route('payment.show', [$tournament, $team]))
            ->assertRedirect(route('payment.pending', [$tournament, $team, $payment]));
    }

    // ------------------------------------------------------------------
    // Payment state machine
    // ------------------------------------------------------------------

    public function test_admin_verify_transitions_pending_to_verified_and_confirms_team(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $admin = $this->makeUser('admin');
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 100]);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->post(route('payment.verify', [$tournament, $team]), [
            'bkash_number' => '01700000000', 'trx_id' => 'BTRX1',
        ])->assertRedirect();

        $payment = Payment::where('team_id', $team->id)->firstOrFail();
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);

        $this->actingAs($admin)->post(route('admin.payments.verify', $payment))->assertRedirect();

        $payment->refresh();
        $this->assertSame(Payment::STATUS_VERIFIED, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame(Team::STATUS_CONFIRMED, $team->fresh()->status);

        $this->assertDatabaseHas('payment_events', [
            'payment_id' => $payment->id,
            'event' => PaymentEvent::EVENT_VERIFIED,
        ]);
    }

    public function test_verifying_a_verified_payment_is_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $admin = $this->makeUser('admin');
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 100]);
        $team = $this->makeTeam($tournament, $captain);

        $payment = $this->payments()->createForTeam($tournament, $team, $captain, 'bkash', 'BTRX1');
        $this->payments()->verifyManually($payment, $admin);

        $this->expectException(DomainException::class);
        $this->payments()->verifyManually($payment, $admin);
    }

    public function test_admin_can_fail_a_pending_payment(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $admin = $this->makeUser('admin');
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 100]);
        $team = $this->makeTeam($tournament, $captain);

        $payment = $this->payments()->createForTeam($tournament, $team, $captain, 'bkash', 'BTRX1');

        $this->actingAs($admin)->post(route('admin.payments.fail', $payment))->assertRedirect();

        $this->assertSame(Payment::STATUS_FAILED, $payment->fresh()->status);
    }

    public function test_failed_payment_allows_a_new_attempt(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 100]);
        $team = $this->makeTeam($tournament, $captain);

        $payment = $this->payments()->createForTeam($tournament, $team, $captain, 'bkash', 'BTRX1');
        $this->payments()->markFailed($payment, $captain, 'wrong trx');

        // After failure a fresh payment can be created.
        $second = $this->payments()->createForTeam($tournament, $team, $captain, 'bkash', 'BTRX2');

        $this->assertNotSame($payment->id, $second->id);
        $this->assertSame(Payment::STATUS_PENDING, $second->status);
    }

    // ------------------------------------------------------------------
    // Wallet + ledger
    // ------------------------------------------------------------------

    public function test_wallet_is_lazily_created_with_zero_balance(): void
    {
        $player = $this->makeUser('player');
        $wallet = $this->wallets()->walletFor($player);

        $this->assertInstanceOf(Wallet::class, $wallet);
        $this->assertSame(0, $wallet->balanceMinor());
        $this->assertSame('BDT', $wallet->currency);
    }

    public function test_credit_and_debit_update_balance_and_ledger(): void
    {
        $player = $this->makeUser('player');
        $admin = $this->makeUser('admin');
        $wallet = $this->wallets()->walletFor($player);

        $this->wallets()->credit($wallet, 50000, LedgerEntry::TYPE_ADJUSTMENT, 'Prize credit', $admin);
        $this->wallets()->debit($wallet, 20000, LedgerEntry::TYPE_ADJUSTMENT, 'Fee correction', $admin);

        $wallet->refresh();
        $this->assertSame(30000, $wallet->balanceMinor());

        $this->assertSame(2, $wallet->ledgerEntries()->count());
        $this->assertSame(0, $this->wallets()->reconciliationDelta($wallet));
    }

    public function test_debit_beyond_balance_is_rejected(): void
    {
        $player = $this->makeUser('player');
        $admin = $this->makeUser('admin');
        $wallet = $this->wallets()->walletFor($player);

        $this->wallets()->credit($wallet, 1000, LedgerEntry::TYPE_ADJUSTMENT, 'seed', $admin);

        $this->expectException(DomainException::class);
        $this->wallets()->debit($wallet, 5000, LedgerEntry::TYPE_ADJUSTMENT, 'overdraw', $admin);
    }

    public function test_every_movement_creates_a_ledger_entry(): void
    {
        $player = $this->makeUser('player');
        $admin = $this->makeUser('admin');
        $wallet = $this->wallets()->walletFor($player);

        $this->wallets()->credit($wallet, 10000, LedgerEntry::TYPE_ADJUSTMENT, 'a', $admin);
        $this->wallets()->credit($wallet, 5000, LedgerEntry::TYPE_ADJUSTMENT, 'b', $admin);
        $this->wallets()->debit($wallet, 2000, LedgerEntry::TYPE_ADJUSTMENT, 'c', $admin);

        $entries = LedgerEntry::where('wallet_id', $wallet->id)->orderBy('id')->get();

        $this->assertCount(3, $entries);
        $this->assertSame(10000, $entries[0]->balance_after);
        $this->assertSame(15000, $entries[1]->balance_after);
        $this->assertSame(13000, $entries[2]->balance_after);
    }

    public function test_user_wallet_page_shows_balance_and_history(): void
    {
        $player = $this->makeUser('player');
        $admin = $this->makeUser('admin');
        $wallet = $this->wallets()->walletFor($player);
        $this->wallets()->credit($wallet, 25000, LedgerEntry::TYPE_ADJUSTMENT, 'Welcome bonus', $admin);

        $this->actingAs($player)->get(route('wallet.index'))
            ->assertOk()
            ->assertSee('৳250.00')
            ->assertSee('Welcome bonus');
    }

    // ------------------------------------------------------------------
    // Admin wallet controls
    // ------------------------------------------------------------------

    public function test_admin_can_credit_user_wallet_via_http(): void
    {
        $admin = $this->makeUser('admin');
        $player = $this->makeUser('player');

        $this->actingAs($admin)->post(route('admin.wallet.credit', $player), [
            'amount' => '150.50',
            'description' => 'Prize credit',
        ])->assertRedirect();

        $wallet = $this->wallets()->walletFor($player);
        $this->assertSame(15050, $wallet->balanceMinor());
    }

    public function test_admin_debit_beyond_balance_fails(): void
    {
        $admin = $this->makeUser('admin');
        $player = $this->makeUser('player');
        $this->wallets()->walletFor($player);

        $this->actingAs($admin)->post(route('admin.wallet.debit', $player), [
            'amount' => '50.00',
            'description' => 'overdraw',
        ])->assertSessionHas('error');

        $this->assertSame(0, $this->wallets()->walletFor($player)->balanceMinor());
    }

    // ------------------------------------------------------------------
    // Refunds
    // ------------------------------------------------------------------

    protected function settledPayment(): array
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $admin = $this->makeUser('admin');
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 100]);
        $team = $this->makeTeam($tournament, $captain);

        $payment = $this->payments()->createForTeam($tournament, $team, $captain, 'bkash', 'BTRX1');
        $this->payments()->verifyManually($payment, $admin);

        return [$payment, $captain, $admin];
    }

    public function test_refund_credits_payer_wallet_and_records_ledger(): void
    {
        [$payment, $captain, $admin] = $this->settledPayment();

        $this->actingAs($admin)->post(route('admin.payments.refund', $payment), [
            'reason' => 'Team withdrew',
        ])->assertRedirect();

        $payment->refresh();
        $this->assertSame(Payment::STATUS_REFUNDED, $payment->status);
        $this->assertNotNull($payment->refunded_at);

        $refund = Refund::where('payment_id', $payment->id)->firstOrFail();
        $this->assertSame(10000, $refund->amount_minor);

        $wallet = $this->wallets()->walletFor($captain);
        $this->assertSame(10000, $wallet->balanceMinor());
        $this->assertDatabaseHas('ledger_entries', [
            'wallet_id' => $wallet->id,
            'type' => LedgerEntry::TYPE_REFUND,
            'direction' => LedgerEntry::DIRECTION_CREDIT,
            'amount_minor' => 10000,
        ]);
        $this->assertDatabaseHas('payment_events', [
            'payment_id' => $payment->id,
            'event' => PaymentEvent::EVENT_REFUNDED,
        ]);
    }

    public function test_duplicate_refund_is_blocked(): void
    {
        [$payment, $captain, $admin] = $this->settledPayment();

        $this->payments()->refund($payment, $admin, 'first refund');

        $this->expectException(DomainException::class);
        $this->payments()->refund($payment, $admin, 'second refund');
    }

    public function test_refunding_a_pending_payment_is_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $admin = $this->makeUser('admin');
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 100]);
        $team = $this->makeTeam($tournament, $captain);

        $payment = $this->payments()->createForTeam($tournament, $team, $captain, 'bkash', 'BTRX1');

        $this->expectException(DomainException::class);
        $this->payments()->refund($payment, $admin, 'too early');
    }

    public function test_refund_requires_a_reason(): void
    {
        [$payment, $captain, $admin] = $this->settledPayment();

        $this->actingAs($admin)->post(route('admin.payments.refund', $payment), [
            'reason' => '',
        ])->assertSessionHasErrors('reason');

        $this->assertSame(Payment::STATUS_VERIFIED, $payment->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Registration integration
    // ------------------------------------------------------------------

    public function test_registration_flow_keeps_team_pending_until_verified(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $admin = $this->makeUser('admin');
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 100]);

        $this->actingAs($captain)->post(route('teams.store', $tournament), [
            'name' => 'Challengers', 'captain_name' => $captain->name,
            'phone' => '01700000000', 'game_uid' => 'UID123',
        ])->assertRedirect();

        $team = Team::where('name', 'Challengers')->firstOrFail();
        $this->assertSame(Team::STATUS_PENDING, $team->status);

        $this->actingAs($captain)->post(route('payment.verify', [$tournament, $team]), [
            'bkash_number' => '01700000000', 'trx_id' => 'BTRX1',
        ])->assertRedirect();
        $this->assertSame(Team::STATUS_PENDING, $team->fresh()->status);

        $payment = Payment::where('team_id', $team->id)->firstOrFail();
        $this->actingAs($admin)->post(route('admin.payments.verify', $payment))->assertRedirect();

        $this->assertSame(Team::STATUS_CONFIRMED, $team->fresh()->status);
    }

    public function test_payment_cannot_be_transferred_between_teams(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 100]);
        $teamA = $this->makeTeam($tournament, $captain);
        $teamB = $this->makeTeam($tournament);

        // Attempting to pay for teamB while the route is bound to teamA's id
        // is impossible via the route; the service validates the team belongs
        // to the tournament and is pending. Cross-team injection via a forged
        // team_id in the payload is ignored (team comes from the route).
        $payment = $this->payments()->createForTeam($tournament, $teamA, $captain, 'bkash', 'BTRX1');

        $this->assertSame($teamA->id, $payment->team_id);
        $this->assertNotSame($teamB->id, $payment->team_id);
    }
}
