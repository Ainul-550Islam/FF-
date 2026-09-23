<?php

namespace Tests\Feature;

use App\Gateways\CardGateway;
use App\Models\Payment;
use App\Models\PaymentEvent;
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
 * Phase 20/G2-D — card payments via the SSLCommerz aggregator.
 *
 * Card is a thin, honest delegation to the hosted checkout: it is configured
 * only when the card backend (sslcommerz) has store credentials. Uses
 * Http::fake; no real gateway request is made.
 */
class PaymentCardGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected string $storePassword = 'test-store-pass';

    protected function configureCard(array $overrides = []): void
    {
        config()->set('payments.providers.card', array_merge([
            'label' => 'Card',
            'enabled' => true,
            'mode' => 'sandbox',
            'gateway' => 'sslcommerz',
            'merchant_id' => null,
            'merchant_secret' => null,
        ], $overrides));

        config()->set('payments.providers.sslcommerz', [
            'label' => 'SSLCommerz',
            'enabled' => true,
            'mode' => 'sandbox',
            'store_id' => 'testbox',
            'store_password' => $this->storePassword,
            'base_url' => null,
        ]);
    }

    protected function fakeSslBackend(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/gwprocess/v4/api.php')) {
                return Http::response([
                    'status' => 'SUCCESS',
                    'sessionkey' => 'CARDSESS123',
                    'GatewayPageURL' => 'https://sandbox.sslcommerz.com/gwprocess/v4/gw.php?Q=cardpay',
                ]);
            }

            if (str_contains($url, '/validator/api/validationserverAPI.php')) {
                return Http::response([
                    'status' => 'VALID',
                    'tran_id' => 'CARD1',
                    'val_id' => 'VAL123',
                    'amount' => '100.00',
                    'currency_type' => 'BDT',
                    'bank_tran_id' => 'BANK123',
                ]);
            }

            return Http::response([], 404);
        });
    }

    protected function returnBody(int $paymentId): array
    {
        $body = [
            'verify_key' => 'amount,bank_tran_id,currency_type,status,tran_id,val_id',
            'val_id' => 'VAL123',
            'tran_id' => 'CARD'.$paymentId,
            'amount' => '100.00',
            'currency_type' => 'BDT',
            'bank_tran_id' => 'BANK123',
            'status' => 'VALID',
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

    protected function makeTournament(User $organizer): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = 'Card Tournament';
        $t->slug = 'card-'.Str::random(8);
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = 100;
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

    public function test_card_unconfigured_without_credentials(): void
    {
        $gateway = app(CardGateway::class);

        $this->assertFalse($gateway->configured());
        $this->assertFalse($gateway->supportsCallbacks());
        $this->assertFalse($gateway->supportsRefunds());
    }

    public function test_card_configured_when_sslcommerz_backend_has_credentials(): void
    {
        $this->configureCard();

        $gateway = app(CardGateway::class);

        $this->assertTrue($gateway->configured());
        $this->assertTrue($gateway->supportsCallbacks());
        $this->assertTrue($gateway->supportsRefunds());
    }

    public function test_configured_card_creates_hosted_session(): void
    {
        $this->configureCard();
        $this->fakeSslBackend();

        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $payment = app(PaymentService::class)->createForTeam($tournament, $team, $captain, 'card', 'PENDING', 'card', null);

        $result = app(CardGateway::class)->createExternalPayment($payment);

        $this->assertSame('https://sandbox.sslcommerz.com/gwprocess/v4/gw.php?Q=cardpay', $result['redirect_url']);
        $this->assertSame(Payment::STATUS_PROCESSING, $payment->fresh()->status);
        $this->assertSame('CARDSESS123', $payment->fresh()->provider_reference);
        $this->assertSame('CARD'.$payment->id, $payment->fresh()->trx_id);
    }

    public function test_unconfigured_card_refuses_checkout(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $payment = app(PaymentService::class)->createForTeam($tournament, $team, $captain, 'card', 'PENDING', 'card', null);

        $this->expectException(\DomainException::class);
        app(CardGateway::class)->createExternalPayment($payment);
    }

    public function test_card_callback_settles_via_sslcommerz_validation(): void
    {
        $this->configureCard();
        $this->fakeSslBackend();

        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $payment = app(PaymentService::class)->createForTeam($tournament, $team, $captain, 'card', 'PENDING', 'card', null);
        app(CardGateway::class)->createExternalPayment($payment);
        $payment->refresh();

        $url = route('payments.callback', ['provider' => 'card'])
            .'?payment_id='.$payment->id
            .'&state='.urlencode(PaymentCallbackState::build($payment));

        $this->actingAs($captain)
            ->post($url, $this->returnBody($payment->id))
            ->assertRedirect(route('payment.pending', [$tournament, $team, $payment]));

        $payment->refresh();

        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertSame('BANK123', $payment->trx_id);
        $this->assertSame(Team::STATUS_CONFIRMED, $team->fresh()->status);

        $this->assertDatabaseHas('payment_events', [
            'payment_id' => $payment->id,
            'event' => PaymentEvent::EVENT_GATEWAY_CONFIRMED,
        ]);
    }

    public function test_card_callback_rejects_forged_state(): void
    {
        $this->configureCard();
        $this->fakeSslBackend();

        $org = $this->makeUser('organizer');
        $captain = $this->makeUser();
        $tournament = $this->makeTournament($org);
        $team = $this->makeTeam($tournament, $captain);

        $payment = app(PaymentService::class)->createForTeam($tournament, $team, $captain, 'card', 'PENDING', 'card', null);
        app(CardGateway::class)->createExternalPayment($payment);
        $payment->refresh();

        $url = route('payments.callback', ['provider' => 'card'])
            .'?payment_id='.$payment->id
            .'&state=forged';

        $this->actingAs($captain)
            ->post($url, $this->returnBody($payment->id))
            ->assertForbidden();

        $this->assertSame(Payment::STATUS_PROCESSING, $payment->fresh()->status);
    }
}
