<?php

namespace Tests\Feature;

use App\Gateways\SslCommerzGateway;
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
 * Phase 20/G2-C — SSLCommerz hosted checkout integration.
 *
 * Uses Http::fake; no real SSLCommerz request is made. Covers the honest
 * two-mode design, session creation, IPN MD5 signature verification, the
 * authoritative order-validation re-check, amount re-validation, idempotency
 * and the external refund path.
 */
class PaymentSslCommerzTest extends TestCase
{
    use RefreshDatabase;

    protected string $storePassword = 'test-store-pass';

    protected function configureSslCommerz(array $overrides = []): void
    {
        config()->set('payments.providers.sslcommerz', array_merge([
            'label' => 'SSLCommerz',
            'enabled' => true,
            'mode' => 'sandbox',
            'store_id' => 'testbox',
            'store_password' => $this->storePassword,
            'base_url' => null,
        ], $overrides));
    }

    protected function fakeSslCommerz(string $validateStatus = 'VALID', string $validateAmount = '100.00'): void
    {
        Http::fake(function (Request $request) use ($validateStatus, $validateAmount) {
            $url = $request->url();

            if (str_contains($url, '/gwprocess/v4/api.php')) {
                return Http::response([
                    'status' => 'SUCCESS',
                    'sessionkey' => 'SESSKEY123',
                    'GatewayPageURL' => 'https://sandbox.sslcommerz.com/gwprocess/v4/gw.php?Q=pay',
                ]);
            }

            if (str_contains($url, '/validator/api/validationserverAPI.php')) {
                return Http::response([
                    'status' => $validateStatus,
                    'tran_id' => 'FFA1',
                    'val_id' => 'VAL123',
                    'amount' => $validateAmount,
                    'currency_type' => 'BDT',
                    'bank_tran_id' => 'BANK123',
                    'card_type' => 'VISA',
                ]);
            }

            if (str_contains($url, '/validator/api/merchantTransIDvalidationAPI.php')) {
                return Http::response(['status' => 'SUCCESS', 'refund_ref_id' => 'REFUND123']);
            }

            return Http::response([], 404);
        });
    }

    /**
     * Build an SSLCommerz return POST with a valid MD5 verify_sign.
     *
     * @return array<string, mixed>
     */
    protected function returnBody(int $paymentId, string $verifyStatus = 'VALID', string $amount = '100.00'): array
    {
        $body = [
            'verify_key' => 'amount,bank_tran_id,currency_type,status,tran_id,val_id',
            'val_id' => 'VAL123',
            'tran_id' => 'FFA'.$paymentId,
            'amount' => $amount,
            'currency_type' => 'BDT',
            'bank_tran_id' => 'BANK123',
            'status' => $verifyStatus,
        ];

        $hash = $this->storePassword;

        foreach (explode(',', $body['verify_key']) as $field) {
            $hash .= (string) ($body[$field] ?? '');
        }

        $body['verify_sign'] = md5($hash);

        return $body;
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
        $t->name = 'SSLCommerz Tournament';
        $t->slug = 'ssl-'.Str::random(8);
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

    protected function startHostedSsl(array &$captured, string $validateStatus = 'VALID', string $validateAmount = '100.00'): array
    {
        $this->configureSslCommerz();
        $this->fakeSslCommerz($validateStatus, $validateAmount);

        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $payment = app(PaymentService::class)->createForTeam($tournament, $team, $captain, 'sslcommerz', 'PENDING', 'sslcommerz', null);
        app(SslCommerzGateway::class)->createExternalPayment($payment);
        $payment->refresh();

        $captured = compact('org', 'captain', 'tournament', 'team', 'payment');

        return $captured;
    }

    protected function callbackUrl(array $captured): string
    {
        return route('payments.callback', ['provider' => 'sslcommerz'])
            .'?payment_id='.$captured['payment']->id
            .'&state='.urlencode(PaymentCallbackState::build($captured['payment']));
    }

    // ------------------------------------------------------------------
    // Honest configuration reporting
    // ------------------------------------------------------------------

    public function test_sslcommerz_reports_unconfigured_without_credentials(): void
    {
        $gateway = app(SslCommerzGateway::class);

        $this->assertFalse($gateway->configured());
        $this->assertFalse($gateway->supportsCallbacks());
        $this->assertFalse($gateway->supportsRefunds());
    }

    public function test_sslcommerz_reports_configured_with_credentials(): void
    {
        $this->configureSslCommerz();

        $gateway = app(SslCommerzGateway::class);

        $this->assertTrue($gateway->configured());
        $this->assertTrue($gateway->supportsCallbacks());
        $this->assertTrue($gateway->supportsRefunds());
    }

    // ------------------------------------------------------------------
    // Hosted session creation
    // ------------------------------------------------------------------

    public function test_configured_sslcommerz_creates_hosted_session(): void
    {
        $this->configureSslCommerz();
        $this->fakeSslCommerz();

        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $payment = app(PaymentService::class)->createForTeam($tournament, $team, $captain, 'sslcommerz', 'PENDING', 'sslcommerz', null);

        $result = app(SslCommerzGateway::class)->createExternalPayment($payment);

        $this->assertSame('https://sandbox.sslcommerz.com/gwprocess/v4/gw.php?Q=pay', $result['redirect_url']);
        $this->assertSame(Payment::STATUS_PROCESSING, $payment->fresh()->status);
        $this->assertSame('SESSKEY123', $payment->fresh()->provider_reference);
        $this->assertSame('FFA'.$payment->id, $payment->fresh()->trx_id);
    }

    // ------------------------------------------------------------------
    // Callback: IPN signature + order validation
    // ------------------------------------------------------------------

    public function test_callback_verifies_ipn_hash_and_settles(): void
    {
        $captured = [];
        $this->startHostedSsl($captured);

        $this->actingAs($captured['captain'])
            ->post($this->callbackUrl($captured), $this->returnBody($captured['payment']->id))
            ->assertRedirect(route('payment.pending', [$captured['tournament'], $captured['team'], $captured['payment']]));

        $payment = $captured['payment']->fresh();

        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertSame('BANK123', $payment->trx_id);
        $this->assertSame(Team::STATUS_CONFIRMED, $captured['team']->fresh()->status);

        $this->assertDatabaseHas('payment_events', [
            'payment_id' => $payment->id,
            'event' => PaymentEvent::EVENT_GATEWAY_CONFIRMED,
        ]);
    }

    public function test_callback_rejects_tampered_ipn_signature(): void
    {
        $captured = [];
        $this->startHostedSsl($captured);

        $body = $this->returnBody($captured['payment']->id);
        $body['verify_sign'] = 'deadbeef';

        $this->actingAs($captured['captain'])
            ->post($this->callbackUrl($captured), $body)
            ->assertRedirect(route('payment.pending', [$captured['tournament'], $captured['team'], $captured['payment']]));

        $this->assertSame(Payment::STATUS_PROCESSING, $captured['payment']->fresh()->status);
    }

    public function test_callback_rejects_forged_state(): void
    {
        $captured = [];
        $this->startHostedSsl($captured);

        $url = route('payments.callback', ['provider' => 'sslcommerz'])
            .'?payment_id='.$captured['payment']->id
            .'&state=forged';

        $this->actingAs($captured['captain'])
            ->post($url, $this->returnBody($captured['payment']->id))
            ->assertForbidden();

        $this->assertSame(Payment::STATUS_PROCESSING, $captured['payment']->fresh()->status);
    }

    public function test_callback_rejects_amount_mismatch(): void
    {
        $captured = [];
        $this->startHostedSsl($captured, 'VALID', '1.00');

        $this->actingAs($captured['captain'])
            ->post($this->callbackUrl($captured), $this->returnBody($captured['payment']->id, 'VALID', '1.00'))
            ->assertRedirect(route('payment.pending', [$captured['tournament'], $captured['team'], $captured['payment']]));

        $this->assertSame(Payment::STATUS_PROCESSING, $captured['payment']->fresh()->status);
    }

    public function test_callback_is_idempotent_on_replay(): void
    {
        $captured = [];
        $this->startHostedSsl($captured);

        $this->actingAs($captured['captain'])->post($this->callbackUrl($captured), $this->returnBody($captured['payment']->id))->assertRedirect();
        $this->actingAs($captured['captain'])->post($this->callbackUrl($captured), $this->returnBody($captured['payment']->id))->assertRedirect();

        $payment = $captured['payment']->fresh();

        $this->assertSame(Payment::STATUS_PAID, $payment->status);

        $this->assertSame(1, PaymentEvent::where('payment_id', $payment->id)
            ->where('event', PaymentEvent::EVENT_PAID)
            ->count());
    }

    public function test_callback_fails_payment_when_validation_fails(): void
    {
        $captured = [];
        $this->startHostedSsl($captured, 'FAILED', '0.00');

        $this->actingAs($captured['captain'])
            ->post($this->callbackUrl($captured), $this->returnBody($captured['payment']->id, 'FAILED', '0.00'))
            ->assertRedirect(route('payment.pending', [$captured['tournament'], $captured['team'], $captured['payment']]));

        $this->assertSame(Payment::STATUS_FAILED, $captured['payment']->fresh()->status);
    }

    // ------------------------------------------------------------------
    // External refund
    // ------------------------------------------------------------------

    public function test_refund_external_uses_refund_api(): void
    {
        $this->configureSslCommerz();
        $this->fakeSslCommerz();

        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $payment = app(PaymentService::class)->createForTeam($tournament, $team, $captain, 'sslcommerz', 'PENDING', 'sslcommerz', null);
        $payment->status = Payment::STATUS_PAID;
        $payment->trx_id = 'BANK123';
        $payment->save();

        $refund = new Refund;
        $refund->payment_id = $payment->id;
        $refund->amount_minor = $payment->amountMinor();
        $refund->currency = 'BDT';
        $refund->reason = 'Test refund';
        $refund->processed_by = $org->id;
        $refund->save();

        $result = app(SslCommerzGateway::class)->refundExternal($payment, $refund);

        $this->assertSame('refunded', $result['status']);
        $this->assertSame('REFUND123', $result['provider_reference']);
    }

    public function test_refund_external_throws_without_credentials(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $payment = app(PaymentService::class)->createForTeam($tournament, $team, $captain, 'sslcommerz', 'PENDING', 'sslcommerz', null);

        $refund = new Refund;
        $refund->payment_id = $payment->id;
        $refund->amount_minor = $payment->amountMinor();
        $refund->currency = 'BDT';
        $refund->reason = 'Test';
        $refund->save();

        $this->expectException(\DomainException::class);
        app(SslCommerzGateway::class)->refundExternal($payment, $refund);
    }
}
