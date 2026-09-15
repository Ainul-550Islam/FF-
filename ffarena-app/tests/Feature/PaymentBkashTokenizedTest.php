<?php

namespace Tests\Feature;

use App\Gateways\BkashGateway;
use App\Gateways\BkashTokenizedClient;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Refund;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\PaymentService;
use App\Support\PaymentCallbackState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 20/G2-A — bKash Tokenized Checkout integration.
 *
 * Uses Http::fake so no real bKash request is ever made. Covers the honest
 * two-mode design (manual fallback vs. live hosted checkout), the signed
 * redirect `state`, server-side status confirmation, amount/currency
 * re-validation, idempotency and the tokenized refund path.
 */
class PaymentBkashTokenizedTest extends TestCase
{
    use RefreshDatabase;

    protected function configureBkash(array $overrides = []): void
    {
        config()->set('payments.providers.bkash', array_merge([
            'label' => 'bKash',
            'enabled' => true,
            'mode' => 'sandbox',
            'base_url' => null,
            'app_key' => 'test-app-key',
            'app_secret' => 'test-app-secret',
            'username' => 'test-username',
            'password' => 'test-password',
            'merchant_number' => '01700000000',
            'token_ttl_seconds' => 3300,
        ], $overrides));
    }

    protected function fakeBkash(string $queryStatus = 'Completed', string $queryAmount = '100.00', string $queryTrxId = 'TRXCONF'): void
    {
        Http::fake(function (Request $request) use ($queryStatus, $queryAmount, $queryTrxId) {
            $url = $request->url();

            if (str_ends_with($url, '/tokenized/checkout/token/grant')) {
                return Http::response(['id_token' => 'id-token-abc', 'token_type' => 'Bearer', 'expires_in' => 3600]);
            }

            if (str_ends_with($url, '/tokenized/checkout/create')) {
                return Http::response([
                    'paymentID' => 'PAYID123',
                    'bkashURL' => 'https://sandbox.bka.sh/redirect?paymentID=PAYID123',
                    'statusCode' => '0000',
                    'statusMessage' => 'Successful',
                ]);
            }

            if (str_ends_with($url, '/tokenized/checkout/execute')) {
                return Http::response(['paymentID' => 'PAYID123', 'trxID' => $queryTrxId, 'transactionStatus' => $queryStatus, 'amount' => $queryAmount, 'currency' => 'BDT']);
            }

            if (str_ends_with($url, '/tokenized/checkout/payment/status')) {
                return Http::response(['paymentID' => 'PAYID123', 'trxID' => $queryTrxId, 'transactionStatus' => $queryStatus, 'amount' => $queryAmount, 'currency' => 'BDT']);
            }

            if (str_ends_with($url, '/tokenized/checkout/payment/refund')) {
                return Http::response(['refundTrxID' => 'RFND001', 'transactionStatus' => 'Completed', 'statusCode' => '0000']);
            }

            return Http::response([], 404);
        });
    }

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
        $t = new Tournament;
        $t->organizer_id = $organizer->id;
        $t->name = 'bKash Tournament';
        $t->slug = 'bkash-'.Str::random(8);
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
        $team = new Team;
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

    // ------------------------------------------------------------------
    // Honest configuration reporting
    // ------------------------------------------------------------------

    public function test_bkash_reports_unconfigured_without_credentials(): void
    {
        $client = new BkashTokenizedClient([]);
        $gateway = new BkashGateway($client);

        $this->assertFalse($client->configured());
        $this->assertFalse($gateway->configured());
        $this->assertFalse($gateway->supportsCallbacks());
        $this->assertFalse($gateway->supportsRefunds());
    }

    public function test_bkash_reports_configured_with_credentials(): void
    {
        $this->configureBkash();

        $gateway = app(BkashGateway::class);

        $this->assertTrue($gateway->configured());
        $this->assertTrue($gateway->supportsCallbacks());
        $this->assertTrue($gateway->supportsRefunds());
    }

    // ------------------------------------------------------------------
    // Hosted checkout creation
    // ------------------------------------------------------------------

    public function test_configured_bkash_creates_hosted_checkout_with_redirect(): void
    {
        $this->configureBkash();
        $this->fakeBkash();

        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $payment = app(PaymentService::class)->createForTeam(
            $tournament,
            $team,
            $captain,
            'bkash',
            'PENDING',
            'bkash',
            null,
        );

        $gateway = app(BkashGateway::class);
        $result = $gateway->createExternalPayment($payment);

        $this->assertNotEmpty($result['redirect_url']);
        $this->assertSame('PAYID123', $result['provider_reference']);
        $this->assertSame(Payment::STATUS_PROCESSING, $payment->fresh()->status);
        $this->assertSame('PAYID123', $payment->fresh()->provider_reference);

        // The callback URL we handed bKash carries the signed state token
        // (the bkashURL itself is bKash's, so it does not contain our state).
        $create = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->first(fn (Request $r) => str_ends_with($r->url(), '/tokenized/checkout/create'));

        $this->assertNotNull($create);
        $this->assertStringContainsString('state=', (string) $create->data()['callbackURL']);
        $this->assertStringContainsString('payment_id=', (string) $create->data()['callbackURL']);
    }

    public function test_unconfigured_bkash_falls_back_to_manual_pending(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->post(route('payment.initiate', [$tournament, $team]), [
            'provider' => 'bkash',
            'trx_id' => 'MANUALTRX1',
        ])->assertRedirect(route('payment.pending', [$tournament, $team, Payment::where('team_id', $team->id)->first()]));

        $payment = Payment::where('team_id', $team->id)->first();
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
        $this->assertSame(Team::STATUS_PENDING, $team->fresh()->status);
    }

    public function test_configured_bkash_checkout_redirects_payer_to_bkash(): void
    {
        $this->configureBkash();
        $this->fakeBkash();

        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->post(route('payment.initiate', [$tournament, $team]), [
            'provider' => 'bkash',
        ])->assertRedirect('https://sandbox.bka.sh/redirect?paymentID=PAYID123');

        $payment = Payment::where('team_id', $team->id)->first();
        $this->assertSame(Payment::STATUS_PROCESSING, $payment->status);
    }

    // ------------------------------------------------------------------
    // Access-token caching
    // ------------------------------------------------------------------

    public function test_grant_token_is_cached_across_calls(): void
    {
        $this->configureBkash();

        Http::fake([
            '*tokenized/checkout/token/grant' => Http::response(['id_token' => 'id-token-cached']),
            '*tokenized/checkout/create' => Http::response(['paymentID' => 'PAYID123', 'bkashURL' => 'https://sandbox.bka.sh/redirect']),
        ]);

        $client = new BkashTokenizedClient((array) config('payments.providers.bkash'));

        $client->createPayment('100.00', '1', 'https://example.test/cb', 'INV-1');
        $client->createPayment('100.00', '1', 'https://example.test/cb', 'INV-2');

        // Total: 1 grant + 2 create. The token grant must fire exactly once.
        Http::assertSentCount(3);

        $grants = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $request) => str_ends_with($request->url(), '/tokenized/checkout/token/grant'));

        $this->assertCount(1, $grants);
    }

    // ------------------------------------------------------------------
    // Callback state token
    // ------------------------------------------------------------------

    public function test_callback_state_roundtrip_and_tamper_detection(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $payment = app(PaymentService::class)->createForTeam($tournament, $team, $captain, 'bkash', 'PENDING', 'bkash', null);

        $state = PaymentCallbackState::build($payment);

        $this->assertTrue(PaymentCallbackState::verify($payment, $state));
        $this->assertFalse(PaymentCallbackState::verify($payment, $state.'x'));
        $this->assertFalse(PaymentCallbackState::verify($payment, ''));

        // A state minted for another payment must not verify. (A different
        // captain is used because a captain may only hold one team per
        // tournament.)
        $otherCaptain = $this->makeUser();
        $other = app(PaymentService::class)->createForTeam(
            $tournament,
            $this->makeTeam($tournament, $otherCaptain),
            $otherCaptain,
            'bkash',
            'PENDING',
            'bkash',
            null,
        );

        $this->assertFalse(PaymentCallbackState::verify($other, $state));
    }

    // ------------------------------------------------------------------
    // Callback handling (server-side confirmation)
    // ------------------------------------------------------------------

    protected function startHostedPayment(array &$captured, string $queryStatus = 'Completed', string $queryAmount = '100.00', string $queryTrxId = 'TRXCONF'): array
    {
        $this->configureBkash();
        $this->fakeBkash($queryStatus, $queryAmount, $queryTrxId);

        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $payment = app(PaymentService::class)->createForTeam($tournament, $team, $captain, 'bkash', 'PENDING', 'bkash', null);
        app(BkashGateway::class)->createExternalPayment($payment);
        $payment->refresh();

        $captured = compact('org', 'captain', 'tournament', 'team', 'payment');

        return $captured;
    }

    protected function callbackUrl(array $captured, string $state): string
    {
        return route('payments.callback', ['provider' => 'bkash'])
            .'?payment_id='.$captured['payment']->id
            .'&state='.urlencode($state)
            .'&paymentID=PAYID123';
    }

    public function test_callback_settles_payment_when_provider_confirms(): void
    {
        $captured = [];
        $this->startHostedPayment($captured);

        $state = PaymentCallbackState::build($captured['payment']);

        $this->actingAs($captured['captain'])
            ->get($this->callbackUrl($captured, $state))
            ->assertRedirect(route('payment.pending', [$captured['tournament'], $captured['team'], $captured['payment']]));

        $payment = $captured['payment']->fresh();

        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertSame('TRXCONF', $payment->trx_id);
        $this->assertSame(Team::STATUS_CONFIRMED, $captured['team']->fresh()->status);

        $this->assertDatabaseHas('payment_events', [
            'payment_id' => $payment->id,
            'event' => PaymentEvent::EVENT_GATEWAY_CONFIRMED,
        ]);
    }

    public function test_callback_rejects_forged_state(): void
    {
        $captured = [];
        $this->startHostedPayment($captured);

        $this->actingAs($captured['captain'])
            ->get($this->callbackUrl($captured, 'forged-state-token'))
            ->assertForbidden();

        $this->assertSame(Payment::STATUS_PROCESSING, $captured['payment']->fresh()->status);
    }

    public function test_callback_never_settles_on_unconfirmed_status(): void
    {
        // The provider reports the checkout is still "Initiated" — the payer
        // never completed it.
        $captured = [];
        $this->startHostedPayment($captured, 'Initiated', '0.00', '');

        $state = PaymentCallbackState::build($captured['payment']);

        $this->actingAs($captured['captain'])
            ->get($this->callbackUrl($captured, $state))
            ->assertRedirect(route('payment.pending', [$captured['tournament'], $captured['team'], $captured['payment']]));

        $this->assertSame(Payment::STATUS_PROCESSING, $captured['payment']->fresh()->status);
        $this->assertSame(Team::STATUS_PENDING, $captured['team']->fresh()->status);
    }

    public function test_callback_rejects_amount_mismatch(): void
    {
        // Provider reports a different amount than the server-side entry fee.
        $captured = [];
        $this->startHostedPayment($captured, 'Completed', '1.00', 'TRXCONF');

        $state = PaymentCallbackState::build($captured['payment']);

        $this->actingAs($captured['captain'])
            ->get($this->callbackUrl($captured, $state))
            ->assertRedirect(route('payment.pending', [$captured['tournament'], $captured['team'], $captured['payment']]));

        $this->assertSame(Payment::STATUS_PROCESSING, $captured['payment']->fresh()->status);
    }

    public function test_callback_is_idempotent_on_replay(): void
    {
        $captured = [];
        $this->startHostedPayment($captured);

        $state = PaymentCallbackState::build($captured['payment']);

        $first = $this->actingAs($captured['captain'])->get($this->callbackUrl($captured, $state));
        $first->assertRedirect();

        $this->assertSame(Payment::STATUS_PAID, $captured['payment']->fresh()->status);

        // Replay the identical callback — must not settle twice.
        $second = $this->actingAs($captured['captain'])->get($this->callbackUrl($captured, $state));
        $second->assertRedirect();

        $payment = $captured['payment']->fresh();

        $this->assertSame(Payment::STATUS_PAID, $payment->status);

        // Exactly one non-duplicate gateway_confirmed event.
        $confirmed = PaymentEvent::where('payment_id', $payment->id)
            ->where('event', PaymentEvent::EVENT_GATEWAY_CONFIRMED)
            ->get()
            ->filter(fn (PaymentEvent $e) => ($e->metadata['duplicate'] ?? null) !== true);

        $this->assertCount(1, $confirmed);

        // Exactly one paid event (no double effect).
        $this->assertSame(1, PaymentEvent::where('payment_id', $payment->id)
            ->where('event', PaymentEvent::EVENT_PAID)
            ->count());
    }

    public function test_callback_fails_payment_when_provider_reports_failure(): void
    {
        $captured = [];
        $this->startHostedPayment($captured, 'Failed', '0.00', '');

        $state = PaymentCallbackState::build($captured['payment']);

        $this->actingAs($captured['captain'])
            ->get($this->callbackUrl($captured, $state))
            ->assertRedirect(route('payment.pending', [$captured['tournament'], $captured['team'], $captured['payment']]));

        $this->assertSame(Payment::STATUS_FAILED, $captured['payment']->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Tokenized refund
    // ------------------------------------------------------------------

    public function test_refund_external_uses_tokenized_api(): void
    {
        $this->configureBkash();
        $this->fakeBkash();

        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $payment = app(PaymentService::class)->createForTeam($tournament, $team, $captain, 'bkash', 'PENDING', 'bkash', null);
        app(BkashGateway::class)->createExternalPayment($payment);
        $payment->refresh();
        $payment->status = Payment::STATUS_PAID;
        $payment->trx_id = 'TRXCONF';
        $payment->save();

        $refund = new Refund;
        $refund->payment_id = $payment->id;
        $refund->amount_minor = $payment->amountMinor();
        $refund->currency = 'BDT';
        $refund->reason = 'Test refund';
        $refund->processed_by = $org->id;
        $refund->save();

        $result = app(BkashGateway::class)->refundExternal($payment, $refund);

        $this->assertSame('refunded', $result['status']);
        $this->assertSame('RFND001', $result['provider_reference']);
    }

    public function test_refund_external_throws_without_credentials(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $payment = app(PaymentService::class)->createForTeam($tournament, $team, $captain, 'bkash', 'PENDING', 'bkash', null);

        $refund = new Refund;
        $refund->payment_id = $payment->id;
        $refund->amount_minor = $payment->amountMinor();
        $refund->currency = 'BDT';
        $refund->reason = 'Test refund';
        $refund->save();

        $this->expectException(\DomainException::class);
        app(BkashGateway::class)->refundExternal($payment, $refund);
    }

    // ------------------------------------------------------------------
    // Status normalization
    // ------------------------------------------------------------------

    public function test_query_payment_status_normalizes_provider_statuses(): void
    {
        $this->configureBkash();
        $this->fakeBkash('Completed');

        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $payment = app(PaymentService::class)->createForTeam($tournament, $team, $captain, 'bkash', 'PENDING', 'bkash', null);
        app(BkashGateway::class)->createExternalPayment($payment);
        $payment->refresh();

        $gateway = app(BkashGateway::class);

        $completed = $gateway->queryPaymentStatus($payment);
        $this->assertSame('completed', $completed['status']);
        $this->assertSame('TRXCONF', $completed['gateway_transaction_id']);
        $this->assertSame('100.00', $completed['amount']);
        $this->assertSame('BDT', $completed['currency']);
    }
}
