<?php

namespace Tests\Feature\Finance;

use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Refund;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\WalletService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Settlement idempotency (2026-10-07 audit, FIX-06 evidence).
 *
 * The row-lock race guards in PaymentService (locked team re-check on
 * intent, locked payment re-check on every transition) are proven under
 * real concurrency by the PostgreSQL CI job. These tests pin the same
 * state machine sequentially on SQLite: replays and double calls must be
 * absorbed without double effects.
 */
class PaymentSettlementIdempotencyTest extends TestCase
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
        $t->name = $o['name'] ?? 'Settlement Tournament';
        $t->slug = $o['slug'] ?? ('settle-'.Str::random(8));
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

    /**
     * @return array{Payment, User, User}
     */
    protected function settledPayment(): array
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $admin = $this->makeUser('admin');
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 100]);
        $team = $this->makeTeam($tournament, $captain);

        $payment = $this->payments()->createForTeam($tournament, $team, $captain, 'bkash', 'TRXSET'.Str::random(6));
        $this->payments()->verifyManually($payment, $admin);

        return [$payment->fresh(), $captain, $admin];
    }

    public function test_duplicate_payment_intent_for_one_team_is_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 100]);
        $team = $this->makeTeam($tournament, $captain);

        $this->payments()->createForTeam($tournament, $team, $captain, 'bkash', 'TRXDUP1');

        try {
            $this->payments()->createForTeam($tournament, $team, $captain, 'bkash', 'TRXDUP2');
            $this->fail('A second active payment intent for the same team must be rejected.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('already has an active payment', $e->getMessage());
        }

        $this->assertSame(1, Payment::where('team_id', $team->id)->count());
    }

    public function test_repeated_provider_callback_settles_only_once(): void
    {
        config(['services.payments.webhook_secret' => 'test-secret']);

        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 100]);
        $team = $this->makeTeam($tournament, $captain);

        $payment = $this->payments()->createForTeam($tournament, $team, $captain, 'bkash', 'TRXCB1');

        $payload = [
            'payment_id' => $payment->id,
            'amount_minor' => $payment->amountMinor(),
            'currency' => $payment->currency,
            'status' => Payment::STATUS_PAID,
        ];
        $rawBody = (string) json_encode($payload);
        $signature = hash_hmac('sha256', $rawBody, 'test-secret');

        $first = $this->payments()->handleProviderCallback($payment->provider, $payload, $signature, $rawBody);
        $this->assertSame(Payment::STATUS_PAID, $first->status);
        $this->assertSame(Team::STATUS_CONFIRMED, $team->fresh()->status);

        // A webhook retry / replay reports the settled state and records a
        // duplicate marker — it never settles twice.
        $second = $this->payments()->handleProviderCallback($payment->provider, $payload, $signature, $rawBody);
        $this->assertSame($payment->id, $second->id);
        $this->assertSame(Payment::STATUS_PAID, $second->status);

        $this->assertSame(1, PaymentEvent::where('payment_id', $payment->id)->where('event', PaymentEvent::EVENT_PAID)->count());
        $this->assertSame(1, PaymentEvent::where('payment_id', $payment->id)->where('event', PaymentEvent::EVENT_CALLBACK)->count());
    }

    public function test_double_refund_attempt_credits_wallet_only_once(): void
    {
        [$payment, $captain, $admin] = $this->settledPayment();

        $before = $this->wallets()->walletFor($captain)->balanceMinor();

        $this->payments()->refund($payment, $admin, 'first refund');
        $this->assertSame($before + $payment->amountMinor(), $this->wallets()->walletFor($captain)->balanceMinor());

        try {
            $this->payments()->refund($payment->fresh(), $admin, 'second refund');
            $this->fail('A second refund for the same payment must be rejected.');
        } catch (DomainException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertSame(1, Refund::where('payment_id', $payment->id)->count());
        $this->assertSame($before + $payment->amountMinor(), $this->wallets()->walletFor($captain)->balanceMinor());
        $this->assertSame(Payment::STATUS_REFUNDED, $payment->fresh()->status);
    }

    public function test_failed_payment_cannot_be_verified_or_failed_again(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $admin = $this->makeUser('admin');
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 100]);
        $team = $this->makeTeam($tournament, $captain);

        $payment = $this->payments()->createForTeam($tournament, $team, $captain, 'bkash', 'TRXFL1');
        $this->payments()->markFailed($payment, $admin, 'wrong trx');

        try {
            $this->payments()->verifyManually($payment->fresh(), $admin);
            $this->fail('Verifying a failed payment must be rejected.');
        } catch (DomainException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        try {
            $this->payments()->markFailed($payment->fresh(), $admin, 'again');
            $this->fail('Failing a failed payment must be rejected.');
        } catch (DomainException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertSame(Payment::STATUS_FAILED, $payment->fresh()->status);
    }

    public function test_verified_payment_rejects_repeat_verify_and_fail(): void
    {
        [$payment, $captain, $admin] = $this->settledPayment();

        try {
            $this->payments()->verifyManually($payment, $admin);
            $this->fail('Verifying a verified payment must be rejected.');
        } catch (DomainException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        try {
            $this->payments()->markFailed($payment, $admin, 'too late');
            $this->fail('Failing a verified payment must be rejected.');
        } catch (DomainException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertSame(Payment::STATUS_VERIFIED, $payment->fresh()->status);
    }
}
