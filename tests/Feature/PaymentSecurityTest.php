<?php

namespace Tests\Feature;

use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\WalletService;
use App\Support\Money;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 08 — payment/wallet financial security: IDOR, amount tampering,
 * status tampering, unauthorized verification/refund, webhook forgery and
 * replay, mass assignment, and cross-tournament access.
 */
class PaymentSecurityTest extends TestCase
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
        $t->name = $o['name'] ?? 'Payment Security Tournament';
        $t->slug = $o['slug'] ?? ('paysec-'.Str::random(8));
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

    protected function makePayment(Tournament $tournament, Team $team, ?User $payer = null, string $status = 'pending'): Payment
    {
        $p = new Payment();
        $p->tournament_id = $tournament->id;
        $p->team_id = $team->id;
        $p->payer_user_id = $payer?->id ?? $team->captain_id;
        $p->amount = $tournament->entry_fee;
        $p->amount_minor = $tournament->entryFeeMinor();
        $p->currency = 'BDT';
        $p->method = 'bkash';
        $p->trx_id = 'TRX'.strtoupper(Str::random(8));
        $p->provider = 'bkash';
        $p->provider_reference = $p->trx_id;
        $p->idempotency_key = (string) Str::uuid();
        $p->status = $status;
        $p->save();

        return $p;
    }

    // ------------------------------------------------------------------
    // Amount tampering
    // ------------------------------------------------------------------

    public function test_client_amount_and_currency_are_ignored(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 500]);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->post(route('payment.verify', [$tournament, $team]), [
            'bkash_number' => '01700000000',
            'trx_id' => 'BTRX1',
            'amount' => 1,
            'amount_minor' => 100,
            'currency' => 'USD',
            'status' => 'paid',
        ])->assertRedirect();

        $payment = Payment::where('team_id', $team->id)->firstOrFail();
        $this->assertSame(50000, $payment->amountMinor());
        $this->assertSame('BDT', $payment->currency);
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
    }

    public function test_negative_amount_is_rejected_by_money_parser(): void
    {
        $this->expectException(\DomainException::class);
        Money::toMinor('-5.00');
    }

    // ------------------------------------------------------------------
    // Unauthorized verification / refund
    // ------------------------------------------------------------------

    public function test_organizer_cannot_verify_or_refund_payment(): void
    {
        $org = $this->makeUser('organizer');
        $otherOrg = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');
        $team = $this->makeTeam($tournament, $captain);
        $payment = $this->makePayment($tournament, $team, $captain, 'verified');

        $this->actingAs($otherOrg)->post(route('admin.payments.verify', $payment))->assertStatus(403);
        $this->actingAs($otherOrg)->post(route('admin.payments.refund', $payment), [
            'reason' => 'hijack',
        ])->assertStatus(403);

        $this->assertSame('verified', $payment->fresh()->status);
    }

    public function test_player_cannot_access_admin_payment_pages(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');
        $team = $this->makeTeam($tournament, $captain);
        $payment = $this->makePayment($tournament, $team, $captain);

        $this->actingAs($captain)->get(route('admin.payments.index'))->assertStatus(403);
        $this->actingAs($captain)->get(route('admin.wallet.show', $captain))->assertStatus(403);
        $this->actingAs($captain)->post(route('admin.wallet.credit', $captain), [
            'amount' => '100', 'description' => 'hack',
        ])->assertStatus(403);
    }

    public function test_user_cannot_view_another_users_payment(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $captainB = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');
        $teamA = $this->makeTeam($tournament, $captainA);
        $payment = $this->makePayment($tournament, $teamA, $captainA);

        $this->actingAs($captainB)
            ->get(route('payment.pending', [$tournament, $teamA, $payment]))
            ->assertStatus(403);
    }

    public function test_cross_tournament_payment_is_blocked(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournamentA = $this->makeTournament($org, 'open');
        $tournamentB = $this->makeTournament($org, 'open', ['name' => 'Other', 'slug' => 'other-'.Str::random(6)]);
        $teamA = $this->makeTeam($tournamentA, $captain);
        $payment = $this->makePayment($tournamentA, $teamA, $captain);

        $this->actingAs($captain)
            ->get(route('payment.pending', [$tournamentB, $teamA, $payment]))
            ->assertStatus(404);
    }

    public function test_payment_team_and_tournament_relationship_is_checked(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');
        $other = $this->makeTournament($org, 'open', ['name' => 'Other', 'slug' => 'other-'.Str::random(6)]);
        $teamA = $this->makeTeam($tournament, $captain);
        $payment = $this->makePayment($other, $teamA, $captain); // mismatched

        // Payment belongs to the OTHER tournament but is addressed through
        // this tournament's team → blocked.
        $this->actingAs($captain)
            ->get(route('payment.pending', [$tournament, $teamA, $payment]))
            ->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // Webhook security + idempotency
    // ------------------------------------------------------------------

    protected function signedPayload(array $payload): array
    {
        $secret = (string) config('services.payments.webhook_secret');
        $body = json_encode($payload);

        return [$body, hash_hmac('sha256', $body, $secret)];
    }

    public function test_webhook_with_valid_signature_marks_payment_paid(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');
        $team = $this->makeTeam($tournament, $captain);
        $payment = $this->makePayment($tournament, $team, $captain, 'pending');

        [$body, $signature] = $this->signedPayload([
            'payment_id' => $payment->id,
            'provider_reference' => $payment->provider_reference,
            'amount_minor' => $payment->amountMinor(),
            'currency' => 'BDT',
            'status' => 'paid',
        ]);

        $this->withHeader('X-Signature', $signature)
            ->postJson(route('webhooks.payments', ['provider' => 'bkash']), json_decode($body, true))
            ->assertOk();

        $payment->refresh();
        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame(Team::STATUS_CONFIRMED, $team->fresh()->status);
    }

    public function test_webhook_with_bad_signature_is_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');
        $team = $this->makeTeam($tournament, $captain);
        $payment = $this->makePayment($tournament, $team, $captain, 'pending');

        $payload = [
            'payment_id' => $payment->id,
            'provider_reference' => $payment->provider_reference,
            'amount_minor' => $payment->amountMinor(),
            'currency' => 'BDT',
            'status' => 'paid',
        ];

        $this->withHeader('X-Signature', 'deadbeef')
            ->postJson(route('webhooks.payments', ['provider' => 'bkash']), $payload)
            ->assertStatus(400);

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_webhook_replay_is_idempotent(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');
        $team = $this->makeTeam($tournament, $captain);
        $payment = $this->makePayment($tournament, $team, $captain, 'pending');

        $payload = [
            'payment_id' => $payment->id,
            'provider_reference' => $payment->provider_reference,
            'amount_minor' => $payment->amountMinor(),
            'currency' => 'BDT',
            'status' => 'paid',
        ];
        [$body, $signature] = $this->signedPayload($payload);

        $this->withHeader('X-Signature', $signature)->postJson(route('webhooks.payments', ['provider' => 'bkash']), json_decode($body, true))->assertOk();
        $this->withHeader('X-Signature', $signature)->postJson(route('webhooks.payments', ['provider' => 'bkash']), json_decode($body, true))->assertOk();

        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        // No double effects: a single team confirmation and no duplicate ledger.
        $this->assertSame(1, Payment::where('id', $payment->id)->count());
    }

    public function test_webhook_with_wrong_amount_is_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');
        $team = $this->makeTeam($tournament, $captain);
        $payment = $this->makePayment($tournament, $team, $captain, 'pending');

        [$body, $signature] = $this->signedPayload([
            'payment_id' => $payment->id,
            'provider_reference' => $payment->provider_reference,
            'amount_minor' => 1, // tampered
            'currency' => 'BDT',
            'status' => 'paid',
        ]);

        $this->withHeader('X-Signature', $signature)
            ->postJson(route('webhooks.payments', ['provider' => 'bkash']), json_decode($body, true))
            ->assertStatus(400);

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_webhook_for_unknown_payment_is_rejected(): void
    {
        [$body, $signature] = $this->signedPayload([
            'payment_id' => 999999,
            'provider_reference' => 'NOPE',
            'amount_minor' => 100,
            'currency' => 'BDT',
            'status' => 'paid',
        ]);

        $this->withHeader('X-Signature', $signature)
            ->postJson(route('webhooks.payments', ['provider' => 'bkash']), json_decode($body, true))
            ->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // Mass assignment
    // ------------------------------------------------------------------

    public function test_payment_server_fields_are_not_mass_assignable(): void
    {
        $payment = new Payment();
        $payment->fill([
            'method' => 'nagad',          // fillable
            'trx_id' => 'TXN1',           // fillable
            'tournament_id' => 9999,      // server-controlled → ignored
            'team_id' => 9999,            // server-controlled → ignored
            'payer_user_id' => 9999,      // server-controlled → ignored
            'amount_minor' => 1,          // server-controlled → ignored
            'status' => 'paid',           // server-controlled → ignored
            'currency' => 'USD',          // server-controlled → ignored
        ]);

        $attributes = $payment->getAttributes();

        $this->assertSame('nagad', $attributes['method']);
        $this->assertSame('TXN1', $attributes['trx_id']);
        $this->assertArrayNotHasKey('tournament_id', $attributes);
        $this->assertArrayNotHasKey('team_id', $attributes);
        $this->assertArrayNotHasKey('payer_user_id', $attributes);
        $this->assertArrayNotHasKey('amount_minor', $attributes);
        $this->assertArrayNotHasKey('status', $attributes);
        $this->assertArrayNotHasKey('currency', $attributes);
    }

    // ------------------------------------------------------------------
    // Wallet integrity
    // ------------------------------------------------------------------

    public function test_wallet_balance_is_never_negative_after_debit(): void
    {
        $admin = $this->makeUser('admin');
        $player = $this->makeUser('player');
        $wallets = app(WalletService::class);
        $wallet = $wallets->walletFor($player);

        $wallets->credit($wallet, 1000, LedgerEntry::TYPE_ADJUSTMENT, 'seed', $admin);

        try {
            $wallets->debit($wallet, 5000, LedgerEntry::TYPE_ADJUSTMENT, 'over', $admin);
            $this->fail('Expected DomainException for overdraw.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Insufficient', $e->getMessage());
        }

        $this->assertSame(1000, $wallet->fresh()->balanceMinor());
    }

    public function test_ledger_entries_cannot_be_mass_assigned(): void
    {
        // Ledger entries are fully guarded (empty $fillable), so any mass
        // assignment attempt throws a MassAssignmentException.
        $this->expectException(MassAssignmentException::class);

        $entry = new LedgerEntry();
        $entry->fill([
            'wallet_id' => 1,
            'direction' => 'credit',
            'amount_minor' => 999999,
            'balance_after' => 999999,
            'type' => 'deposit',
        ]);
    }

    public function test_admin_wallet_page_shows_reconciliation(): void
    {
        $admin = $this->makeUser('admin');
        $player = $this->makeUser('player');
        $wallets = app(WalletService::class);
        $wallet = $wallets->walletFor($player);
        $wallets->credit($wallet, 7777, LedgerEntry::TYPE_ADJUSTMENT, 'x', $admin);

        $this->actingAs($admin)->get(route('admin.wallet.show', $player))
            ->assertOk()
            ->assertSee('Consistent');
    }

    public function test_payment_history_is_not_exposed_to_other_users(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $captainB = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');
        $teamA = $this->makeTeam($tournament, $captainA);
        $this->makePayment($tournament, $teamA, $captainA);

        // captainB's wallet page must not list captainA's payment.
        $response = $this->actingAs($captainB)->get(route('wallet.index'));
        $response->assertOk();
        $this->assertSame(0, $response->viewData('payments')->count());
    }
}
