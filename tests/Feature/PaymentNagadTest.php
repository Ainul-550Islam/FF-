<?php

namespace Tests\Feature;

use App\Gateways\NagadGateway;
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
 * Phase 20/G2-B — Nagad Checkout API integration.
 *
 * Uses Http::fake and an in-test RSA keypair; no real Nagad request is made.
 * Covers honest two-mode design, RSA request signing, signed redirect state,
 * server-side `verify`, amount re-validation, idempotency, and the honest
 * refusal of external refunds (Nagad's standard API has no refund endpoint).
 */
class PaymentNagadTest extends TestCase
{
    use RefreshDatabase;

    protected string $nagadPrivateKey = '';

    protected string $nagadPublicKey = '';

    /**
     * @return array{0:string,1:string} [privatePem, publicPem]
     */
    protected function rsaKeys(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        openssl_pkey_export($key, $private);
        $details = openssl_pkey_get_details($key);

        return [$private, $details['key']];
    }

    protected function configureNagad(array $overrides = []): void
    {
        [$this->nagadPrivateKey, $this->nagadPublicKey] = $this->rsaKeys();

        config()->set('payments.providers.nagad', array_merge([
            'label' => 'Nagad',
            'enabled' => true,
            'mode' => 'sandbox',
            'base_url' => null,
            'merchant_id' => 'MERCHANT01',
            'merchant_private_key' => $this->nagadPrivateKey,
            'pg_public_key' => $this->nagadPublicKey,
            'merchant_number' => '01700000000',
        ], $overrides));
    }

    protected function fakeNagad(string $verifyStatus = 'Success', string $verifyAmount = '100.00'): void
    {
        Http::fake(function (Request $request) use ($verifyStatus, $verifyAmount) {
            $url = $request->url();

            if (str_contains($url, '/check-out/initialize/')) {
                return Http::response([
                    'callBackUrl' => 'https://sandbox.mynagad.com:10083/pay/NAGADREF123',
                    'paymentRefId' => 'NAGADREF123',
                    'status' => 'Success',
                ]);
            }

            if (str_contains($url, '/check-out/complete/')) {
                return Http::response(['paymentRefId' => 'NAGADREF123', 'status' => 'Success']);
            }

            if (str_contains($url, '/dfs/verify/payment/')) {
                return Http::response([
                    'merchantId' => 'MERCHANT01',
                    'orderId' => 'FFA-1',
                    'paymentRefId' => 'NAGADREF123',
                    'amount' => $verifyAmount,
                    'status' => $verifyStatus,
                    'statusCode' => $verifyStatus === 'Success' ? '000' : '030',
                    'issuerPaymentRefNo' => 'ISSUER123',
                ]);
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
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = 'Nagad Tournament';
        $t->slug = 'nagad-'.Str::random(8);
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

    // ------------------------------------------------------------------
    // Honest configuration reporting
    // ------------------------------------------------------------------

    public function test_nagad_reports_unconfigured_without_credentials(): void
    {
        $gateway = app(NagadGateway::class);

        $this->assertFalse($gateway->configured());
        $this->assertFalse($gateway->supportsCallbacks());
        $this->assertFalse($gateway->supportsRefunds());
    }

    public function test_nagad_reports_configured_with_credentials(): void
    {
        $this->configureNagad();

        $gateway = app(NagadGateway::class);

        $this->assertTrue($gateway->configured());
        $this->assertTrue($gateway->supportsCallbacks());
        // Honest: Nagad's standard API has no refund endpoint.
        $this->assertFalse($gateway->supportsRefunds());
    }

    // ------------------------------------------------------------------
    // Hosted checkout creation + request signing
    // ------------------------------------------------------------------

    public function test_configured_nagad_creates_hosted_checkout_and_signs_request(): void
    {
        $this->configureNagad();
        $this->fakeNagad();

        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $payment = app(PaymentService::class)->createForTeam($tournament, $team, $captain, 'nagad', 'PENDING', 'nagad', null);

        $result = app(NagadGateway::class)->createExternalPayment($payment);

        $this->assertSame('https://sandbox.mynagad.com:10083/pay/NAGADREF123', $result['redirect_url']);
        $this->assertSame('NAGADREF123', $result['provider_reference']);
        $this->assertSame(Payment::STATUS_PROCESSING, $payment->fresh()->status);

        // The initialize request must carry a valid RSA-SHA256 signature over
        // the sensitiveData payload (verified with the merchant's own public
        // key).
        $init = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->first(fn (Request $r) => str_contains($r->url(), '/check-out/initialize/'));

        $this->assertNotNull($init);

        $body = $init->data();
        $sensitive = (string) ($body['sensitiveData'] ?? '');
        $signature = (string) ($body['signature'] ?? '');

        $this->assertNotSame('', $sensitive);
        $this->assertNotSame('', $signature);

        $payload = base64_decode($sensitive, true);
        $this->assertIsString($payload);

        $this->assertSame(1, openssl_verify($payload, base64_decode($signature, true), $this->nagadPublicKey, OPENSSL_ALGO_SHA256));

        // The signed payload contains the callback URL with our signed state.
        $decoded = json_decode($payload, true);
        $this->assertArrayHasKey('orderId', $decoded);
    }

    public function test_unconfigured_nagad_falls_back_to_manual_pending(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->post(route('payment.initiate', [$tournament, $team]), [
            'provider' => 'nagad',
            'trx_id' => 'NAGADTX1',
        ])->assertRedirect(route('payment.pending', [$tournament, $team, Payment::where('team_id', $team->id)->first()]));

        $this->assertSame(Payment::STATUS_PENDING, Payment::where('team_id', $team->id)->first()->status);
    }

    // ------------------------------------------------------------------
    // Callback handling (server-side verify)
    // ------------------------------------------------------------------

    protected function startHostedNagad(array &$captured, string $verifyStatus = 'Success', string $verifyAmount = '100.00'): array
    {
        $this->configureNagad();
        $this->fakeNagad($verifyStatus, $verifyAmount);

        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $payment = app(PaymentService::class)->createForTeam($tournament, $team, $captain, 'nagad', 'PENDING', 'nagad', null);
        app(NagadGateway::class)->createExternalPayment($payment);
        $payment->refresh();

        $captured = compact('org', 'captain', 'tournament', 'team', 'payment');

        return $captured;
    }

    protected function callbackUrl(array $captured, string $state): string
    {
        return route('payments.callback', ['provider' => 'nagad'])
            .'?payment_id='.$captured['payment']->id
            .'&state='.urlencode($state);
    }

    public function test_callback_settles_payment_when_nagad_confirms(): void
    {
        $captured = [];
        $this->startHostedNagad($captured);

        $state = PaymentCallbackState::build($captured['payment']);

        $this->actingAs($captured['captain'])
            ->get($this->callbackUrl($captured, $state))
            ->assertRedirect(route('payment.pending', [$captured['tournament'], $captured['team'], $captured['payment']]));

        $payment = $captured['payment']->fresh();

        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertSame('ISSUER123', $payment->trx_id);
        $this->assertSame(Team::STATUS_CONFIRMED, $captured['team']->fresh()->status);

        $this->assertDatabaseHas('payment_events', [
            'payment_id' => $payment->id,
            'event' => PaymentEvent::EVENT_GATEWAY_CONFIRMED,
        ]);
    }

    public function test_callback_rejects_forged_state(): void
    {
        $captured = [];
        $this->startHostedNagad($captured);

        $this->actingAs($captured['captain'])
            ->get($this->callbackUrl($captured, 'forged-state'))
            ->assertForbidden();

        $this->assertSame(Payment::STATUS_PROCESSING, $captured['payment']->fresh()->status);
    }

    public function test_callback_rejects_amount_mismatch(): void
    {
        $captured = [];
        $this->startHostedNagad($captured, 'Success', '1.00');

        $state = PaymentCallbackState::build($captured['payment']);

        $this->actingAs($captured['captain'])
            ->get($this->callbackUrl($captured, $state))
            ->assertRedirect(route('payment.pending', [$captured['tournament'], $captured['team'], $captured['payment']]));

        $this->assertSame(Payment::STATUS_PROCESSING, $captured['payment']->fresh()->status);
    }

    public function test_callback_is_idempotent_on_replay(): void
    {
        $captured = [];
        $this->startHostedNagad($captured);

        $state = PaymentCallbackState::build($captured['payment']);

        $this->actingAs($captured['captain'])->get($this->callbackUrl($captured, $state))->assertRedirect();
        $this->actingAs($captured['captain'])->get($this->callbackUrl($captured, $state))->assertRedirect();

        $payment = $captured['payment']->fresh();

        $this->assertSame(Payment::STATUS_PAID, $payment->status);

        $this->assertSame(1, PaymentEvent::where('payment_id', $payment->id)
            ->where('event', PaymentEvent::EVENT_PAID)
            ->count());
    }

    public function test_callback_fails_payment_when_nagad_reports_failure(): void
    {
        $captured = [];
        $this->startHostedNagad($captured, 'Failed', '0.00');

        $state = PaymentCallbackState::build($captured['payment']);

        $this->actingAs($captured['captain'])
            ->get($this->callbackUrl($captured, $state))
            ->assertRedirect(route('payment.pending', [$captured['tournament'], $captured['team'], $captured['payment']]));

        $this->assertSame(Payment::STATUS_FAILED, $captured['payment']->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Refunds stay honest
    // ------------------------------------------------------------------

    public function test_refund_external_is_refused_even_when_configured(): void
    {
        $this->configureNagad();

        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $payment = app(PaymentService::class)->createForTeam($tournament, $team, $captain, 'nagad', 'PENDING', 'nagad', null);

        $refund = new Refund();
        $refund->payment_id = $payment->id;
        $refund->amount_minor = $payment->amountMinor();
        $refund->currency = 'BDT';
        $refund->reason = 'Test';
        $refund->save();

        $this->expectException(\DomainException::class);
        app(NagadGateway::class)->refundExternal($payment, $refund);
    }
}
