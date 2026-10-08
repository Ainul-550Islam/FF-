<?php

namespace Tests\Feature\Payments;

use App\Models\Payment;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GAP-10 A5 — the two payment ingress endpoints are rate limited.
 *
 * `POST /webhooks/payments/{provider}` (the Phase 08 provider webhook) and
 * `GET|POST /payments/callback/{provider}` (the hosted-gateway payer return)
 * are both signature-verified, and that is exactly why they were left
 * unthrottled: "the signature check rejects forgeries" is true, but it does not
 * bound the work an unauthenticated caller can ask for. Every request costs a
 * hash comparison, a provider lookup and (on a rejection) an audit row, and a
 * shared secret makes a brute-force attempt cheap to launch. A flood also
 * competes with real provider retries — the requests that actually settle
 * money.
 *
 * What is asserted here:
 *
 *  1. both routes really carry the throttle middleware (a structural check, so
 *     a route edit that drops it fails loudly rather than silently);
 *  2. the (limit + 1)th request from one provider + IP answers **429**, while a
 *     *different* provider or a *different* IP still gets through — the budget
 *     is per provider + IP, not global;
 *  3. a correctly signed webhook inside the limit still settles the payment —
 *     the limiter must never cost a legitimate provider a settlement.
 *
 * The limits are read from `config('payments.rate_limits.*')` and overridden
 * per test, so this file keeps testing the wiring when the production ceilings
 * are tuned.
 */
class PaymentEndpointThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A low ceiling keeps the test honest and fast: it exercises the same
        // code path the production value does.
        config([
            'payments.rate_limits.webhook' => 3,
            'payments.rate_limits.callback' => 2,
        ]);

        RateLimiter::clear('payment-webhook:bkash:127.0.0.1');
    }

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function makePayment(): Payment
    {
        $organizer = $this->makeUser('organizer');
        $captain = $this->makeUser('player');

        $tournament = new Tournament();
        $tournament->organizer_id = $organizer->id;
        $tournament->name = 'Throttle Cup';
        $tournament->slug = 'throttle-'.Str::random(8);
        $tournament->game_mode = 'squad';
        $tournament->map = 'Bermuda';
        $tournament->entry_fee = 100;
        $tournament->prize_pool = 5000;
        $tournament->team_slots = 8;
        $tournament->team_size = 4;
        $tournament->starts_at = now()->addDay();
        $tournament->format = Tournament::FORMAT_SINGLE_ELIM;
        $tournament->status = 'open';
        $tournament->save();

        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain->id;
        $team->name = 'Team '.Str::random(6);
        $team->captain_name = $captain->name;
        $team->status = 'pending';
        $team->save();

        $payment = new Payment();
        $payment->user_id = $captain->id;
        $payment->tournament_id = $tournament->id;
        $payment->team_id = $team->id;
        $payment->provider = 'bkash';
        $payment->provider_reference = 'REF-'.Str::upper(Str::random(10));
        $payment->amount_minor = 10000;
        $payment->currency = 'BDT';
        $payment->status = Payment::STATUS_PENDING;
        $payment->save();

        return $payment;
    }

    /**
     * @return array{0: string, 1: string} raw body and its HMAC signature
     */
    protected function signedPayload(array $payload): array
    {
        $secret = (string) config('services.payments.webhook_secret');
        $body = (string) json_encode($payload);

        return [$body, hash_hmac('sha256', $body, $secret)];
    }

    /**
     * @return array<string, mixed>
     */
    protected function rejectedPayload(): array
    {
        return ['payment_id' => 1, 'status' => 'paid'];
    }

    // -----------------------------------------------------------------------
    // 1. Structural: the routes carry the limiter
    // -----------------------------------------------------------------------

    public function test_both_payment_ingress_routes_carry_a_throttle(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes());

        $webhook = $routes->first(fn ($route): bool => $route->getName() === 'webhooks.payments');
        $callback = $routes->first(fn ($route): bool => $route->getName() === 'payments.callback');

        $this->assertNotNull($webhook, 'The provider webhook route must exist.');
        $this->assertNotNull($callback, 'The payer-return route must exist.');

        $this->assertContains(
            'throttle:payment-webhook',
            $webhook->gatherMiddleware(),
            'The provider webhook lost its rate limit — see GAP-10 A5.',
        );

        $this->assertContains(
            'throttle:payment-callback',
            $callback->gatherMiddleware(),
            'The payer return lost its rate limit — see GAP-10 A5.',
        );

        // Both named limiters must actually be defined: a route that points at
        // an undefined limiter fails at request time, in production.
        $this->assertArrayHasKey('webhook', (array) config('payments.rate_limits'));
        $this->assertArrayHasKey('callback', (array) config('payments.rate_limits'));
    }

    // -----------------------------------------------------------------------
    // 2. The limit is enforced, per provider + IP
    // -----------------------------------------------------------------------

    public function test_the_webhook_endpoint_is_rate_limited_per_provider_and_ip(): void
    {
        $limit = (int) config('payments.rate_limits.webhook');

        for ($i = 0; $i < $limit; $i++) {
            $this->withHeader('X-Signature', 'not-a-signature')
                ->postJson(route('webhooks.payments', ['provider' => 'bkash']), $this->rejectedPayload())
                ->assertStatus(401);   // refused for its signature, not for its rate
        }

        // The next request from the same provider + IP is refused before the
        // handler even looks at it.
        $this->withHeader('X-Signature', 'not-a-signature')
            ->postJson(route('webhooks.payments', ['provider' => 'bkash']), $this->rejectedPayload())
            ->assertStatus(429);

        RateLimiter::clear('payment-webhook:bkash:127.0.0.1');

        // A different provider keeps its own budget …
        $this->withHeader('X-Signature', 'not-a-signature')
            ->postJson(route('webhooks.payments', ['provider' => 'nagad']), $this->rejectedPayload())
            ->assertStatus(401);

        // … and so does a different client IP.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
            ->withHeader('X-Signature', 'not-a-signature')
            ->postJson(route('webhooks.payments', ['provider' => 'bkash']), $this->rejectedPayload())
            ->assertStatus(401);

        RateLimiter::clear('payment-webhook:nagad:127.0.0.1');
        RateLimiter::clear('payment-webhook:bkash:203.0.113.77');
    }

    public function test_the_payer_return_endpoint_is_rate_limited(): void
    {
        $limit = (int) config('payments.rate_limits.callback');

        for ($i = 0; $i < $limit; $i++) {
            $status = $this->get(route('payments.callback', ['provider' => 'bkash']))->getStatusCode();

            $this->assertNotSame(429, $status, 'Requests inside the limit must not be throttled.');
        }

        $this->get(route('payments.callback', ['provider' => 'bkash']))->assertStatus(429);

        RateLimiter::clear('payment-callback:bkash:127.0.0.1');

        // Another provider is unaffected (same IP).
        $this->assertNotSame(
            429,
            $this->get(route('payments.callback', ['provider' => 'sslcommerz']))->getStatusCode(),
        );

        RateLimiter::clear('payment-callback:sslcommerz:127.0.0.1');
    }

    // -----------------------------------------------------------------------
    // 3. A legitimate provider is never throttled out of a settlement
    // -----------------------------------------------------------------------

    public function test_a_valid_signed_webhook_within_the_limit_still_settles_the_payment(): void
    {
        $payment = $this->makePayment();

        [$body, $signature] = $this->signedPayload([
            'event' => 'payment.succeeded',
            'payment_id' => $payment->id,
            'provider_reference' => $payment->provider_reference,
            'amount_minor' => $payment->amountMinor(),
            'currency' => 'BDT',
            'status' => 'paid',
        ]);

        // Two rejected attempts (inside the limit of 3) …
        $this->withHeader('X-Signature', 'nope')
            ->postJson(route('webhooks.payments', ['provider' => 'bkash']), $this->rejectedPayload())
            ->assertStatus(401);

        $this->withHeader('X-Signature', 'nope')
            ->postJson(route('webhooks.payments', ['provider' => 'bkash']), $this->rejectedPayload())
            ->assertStatus(401);

        // … must not stop the real callback that follows.
        $this->withHeaders(['X-Signature' => $signature, 'X-Timestamp' => (string) time()])
            ->postJson(route('webhooks.payments', ['provider' => 'bkash']), (array) json_decode($body, true))
            ->assertOk();

        $payment->refresh();

        $this->assertSame(Payment::STATUS_PAID, $payment->status, 'A valid signed webhook inside the limit must still settle.');
        $this->assertNotNull($payment->paid_at);
        $this->assertSame(Team::STATUS_CONFIRMED, (string) $payment->team?->fresh()->status);
    }

    public function test_a_valid_signed_webhook_survives_the_requests_that_preceded_it(): void
    {
        $payment = $this->makePayment();

        [$body, $signature] = $this->signedPayload([
            'event' => 'payment.succeeded',
            'payment_id' => $payment->id,
            'provider_reference' => $payment->provider_reference,
            'amount_minor' => $payment->amountMinor(),
            'currency' => 'BDT',
            'status' => 'paid',
        ]);

        // Exhaust the budget for this provider + IP …
        for ($i = 0; $i < (int) config('payments.rate_limits.webhook'); $i++) {
            $this->withHeader('X-Signature', 'nope')
                ->postJson(route('webhooks.payments', ['provider' => 'bkash']), $this->rejectedPayload());
        }

        $this->withHeader('X-Signature', $signature)
            ->postJson(route('webhooks.payments', ['provider' => 'bkash']), (array) json_decode($body, true))
            ->assertStatus(429);

        // The settlement did NOT happen: a throttled request must never be
        // half-processed (the limiter runs before the controller).
        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);

        // A retry from a different client IP (the provider's own egress pool,
        // a second gateway node) settles normally — the bucket is per IP, so a
        // single abusive source cannot lock a provider out of settling money.
        // The retry is the same signed body: replays are idempotent, so the
        // payment still settles exactly once.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->withHeaders(['X-Signature' => $signature, 'X-Timestamp' => (string) time()])
            ->postJson(route('webhooks.payments', ['provider' => 'bkash']), (array) json_decode($body, true))
            ->assertOk();

        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
    }
}
