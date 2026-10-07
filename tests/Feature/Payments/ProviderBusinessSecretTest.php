<?php

namespace Tests\Feature\Payments;

use App\Models\Payment;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\WebhookSignatureService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GAP-10 A5 — the business signature: per-provider secrets and a real
 * timestamp window.
 *
 * Two independent checks protect an inbound payment callback:
 *
 *   1. the transport signature, verified by `WebhookIngressService` against the
 *      provider's inbound secret (`webhooks.inbound.providers`);
 *   2. the business signature, verified here by `PaymentService` against the
 *      business secret (`webhooks.business.providers`, falling back to the
 *      Phase 08 shared secret), immediately before the payment state machine
 *      runs.
 *
 * The second check existed before this pass, but with a single shared secret
 * and no timestamp: a leaked key was enough to forge a settlement, and a
 * captured body stayed valid forever. Neither is true now:
 *
 *   - a provider with its own business secret is verified against THAT secret;
 *     another provider's secret cannot sign for it, and the shared secret can
 *     no longer sign for it either;
 *   - a timestamped signature must be fresh. A stale timestamp is refused
 *     outright — the verifier does not quietly fall back to the legacy scheme
 *     when the sender supplied a timestamp, because that is precisely the
 *     replay the window exists to stop;
 *   - a sender that has never shipped a timestamp keeps working through the
 *     legacy raw-body scheme, which is what "hardening, not a breaking change"
 *     has to mean for providers we do not control.
 *
 * Every refusal is asserted to happen BEFORE any state change: a rejected
 * callback must leave the payment exactly as it was.
 */
class ProviderBusinessSecretTest extends TestCase
{
    use RefreshDatabase;

    protected const PROVIDER = 'bkash';

    protected const OTHER_PROVIDER = 'nagad';

    protected function setUp(): void
    {
        parent::setUp();

        // A known shared secret: this is the Phase 08 fallback and the value a
        // deployment without per-provider keys still uses.
        config(['services.payments.webhook_secret' => 'shared-business-secret-for-tests']);
    }

    protected function signatures(): WebhookSignatureService
    {
        return app(WebhookSignatureService::class);
    }

    protected function payments(): PaymentService
    {
        return app(PaymentService::class);
    }

    // -----------------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------------

    protected function makeUser(string $role): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function makePendingPayment(int $entryFee = 100, string $provider = self::PROVIDER): Payment
    {
        $organizer = $this->makeUser('organizer');
        $captain = $this->makeUser('player');

        $tournament = new Tournament();
        $tournament->organizer_id = $organizer->id;
        $tournament->name = 'Business Signature Cup';
        $tournament->slug = 'bizsig-'.Str::random(8);
        $tournament->game_mode = 'squad';
        $tournament->map = 'Bermuda';
        $tournament->entry_fee = $entryFee;
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
        $team->phone = '01700000000';
        $team->game_uid = 'UID'.strtoupper(Str::random(8));
        $team->status = 'pending';
        $team->save();

        return $this->payments()->createForTeam($tournament, $team, $captain, $provider, 'BTRX'.Str::upper(Str::random(6)));
    }

    /**
     * The payload a provider would POST back for a payment.
     *
     * @return array<string, mixed>
     */
    protected function callbackPayload(Payment $payment, string $status = Payment::STATUS_PAID): array
    {
        return [
            'payment_id' => $payment->id,
            'provider_reference' => (string) $payment->provider_reference,
            'amount_minor' => $payment->amountMinor(),
            'currency' => 'BDT',
            'status' => $status,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function rawBody(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    // -----------------------------------------------------------------------
    // 1. Per-provider secrets
    // -----------------------------------------------------------------------

    public function test_a_provider_with_its_own_business_secret_is_verified_against_it(): void
    {
        config(['webhooks.business.providers.'.self::PROVIDER => 'bkash-only-secret']);

        $this->assertSame(
            'bkash-only-secret',
            $this->payments()->businessSecretFor(self::PROVIDER),
            'The per-provider business secret must win over the shared one.'
        );

        // The shared secret must no longer verify for this provider.
        $this->assertFalse(
            $this->payments()->verifySignature(
                'body',
                hash_hmac('sha256', 'body', 'shared-business-secret-for-tests'),
                self::PROVIDER,
            ),
            'The shared secret still signs for a provider that has its own.'
        );

        // The provider's own secret does.
        $this->assertTrue(
            $this->payments()->verifySignature(
                'body',
                hash_hmac('sha256', 'body', 'bkash-only-secret'),
                self::PROVIDER,
            ),
            'The provider-specific signature was refused.'
        );
    }

    public function test_one_providers_secret_cannot_sign_for_another(): void
    {
        config([
            'webhooks.business.providers.'.self::PROVIDER => 'bkash-only-secret',
            'webhooks.business.providers.'.self::OTHER_PROVIDER => 'nagad-only-secret',
        ]);

        $signedWithBkash = hash_hmac('sha256', 'body', 'bkash-only-secret');

        $this->assertTrue($this->payments()->verifySignature('body', $signedWithBkash, self::PROVIDER));
        $this->assertFalse(
            $this->payments()->verifySignature('body', $signedWithBkash, self::OTHER_PROVIDER),
            'A signature made with one provider\'s secret verified for another provider.'
        );
    }

    public function test_a_provider_without_a_configured_secret_falls_back_to_the_shared_one(): void
    {
        config(['webhooks.business.providers.'.self::PROVIDER => null]);

        $this->assertSame(
            'shared-business-secret-for-tests',
            $this->payments()->businessSecretFor(self::PROVIDER),
            'The fallback must keep existing deployments working without new keys.'
        );

        $this->assertTrue(
            $this->payments()->verifySignature(
                'body',
                hash_hmac('sha256', 'body', 'shared-business-secret-for-tests'),
                self::PROVIDER,
            )
        );
    }

    // -----------------------------------------------------------------------
    // 2. Timestamps: fresh accepted, stale refused, absent tolerated
    // -----------------------------------------------------------------------

    public function test_a_fresh_timestamped_signature_is_accepted(): void
    {
        $timestamp = time();
        $body = '{"payment_id":1}';

        $signature = $this->signatures()->sign('shared-business-secret-for-tests', $timestamp, $body);

        $this->assertTrue(
            $this->payments()->verifySignature($body, $signature, self::PROVIDER, $timestamp),
            'A fresh timestamped signature was refused.'
        );
    }

    public function test_a_stale_timestamped_signature_is_refused_and_does_not_fall_back(): void
    {
        config(['webhooks.business.timestamp_tolerance' => 300]);

        $stale = time() - 3600;
        $body = '{"payment_id":1}';

        // Correctly signed, but an hour old: exactly the replay the window
        // exists to stop. A correct HMAC must not be enough on its own.
        $staleSignature = $this->signatures()->sign('shared-business-secret-for-tests', $stale, $body);

        $this->assertFalse(
            $this->payments()->verifySignature($body, $staleSignature, self::PROVIDER, $stale),
            'A stale timestamped signature was accepted.'
        );

        // Future timestamps beyond the window are refused too: a clock skew
        // cannot be used to mint a long-lived signature.
        $future = time() + 3600;

        $this->assertFalse(
            $this->payments()->verifySignature(
                $body,
                $this->signatures()->sign('shared-business-secret-for-tests', $future, $body),
                self::PROVIDER,
                $future,
            ),
            'A signature timestamped in the future was accepted.'
        );
    }

    public function test_a_callback_without_a_timestamp_still_verifies_through_the_legacy_scheme(): void
    {
        $body = '{"payment_id":1}';
        $signature = $this->signatures()->signRaw('shared-business-secret-for-tests', $body);

        $this->assertTrue(
            $this->payments()->verifySignature($body, $signature, self::PROVIDER, null),
            'A legacy sender (no timestamp) must keep working — this is hardening, not a new requirement.'
        );
    }

    public function test_an_empty_or_malformed_signature_is_refused(): void
    {
        $this->assertFalse($this->payments()->verifySignature('body', '', self::PROVIDER));
        $this->assertFalse($this->payments()->verifySignature('body', '   ', self::PROVIDER));
        $this->assertFalse($this->payments()->verifySignature('body', 'not-a-digest', self::PROVIDER));

        // Whitespace around an otherwise valid digest is tolerated: header
        // trimming happens in transit and rejecting it would be a false refusal.
        $this->assertTrue(
            $this->payments()->verifySignature(
                'body',
                '  '.hash_hmac('sha256', 'body', 'shared-business-secret-for-tests').'  ',
                self::PROVIDER,
            )
        );
    }

    // -----------------------------------------------------------------------
    // 3. The state machine: refusals happen before any state change
    // -----------------------------------------------------------------------

    public function test_a_stale_callback_is_refused_before_the_payment_changes(): void
    {
        $payment = $this->makePendingPayment();
        $payload = $this->callbackPayload($payment);
        $body = $this->rawBody($payload);
        $stale = time() - 3600;

        $signature = $this->signatures()->sign('shared-business-secret-for-tests', $stale, $body);

        try {
            $this->payments()->handleProviderCallback(self::PROVIDER, $payload, $signature, $body, $stale);

            $this->fail('A stale business signature settled a payment.');
        } catch (DomainException $e) {
            $this->assertSame('Invalid webhook signature.', $e->getMessage());
        }

        $this->assertSame(
            Payment::STATUS_PENDING,
            $payment->fresh()->status,
            'The payment moved even though the signature was refused.'
        );
    }

    public function test_a_legacy_callback_still_settles_without_a_timestamp(): void
    {
        $payment = $this->makePendingPayment();
        $payload = $this->callbackPayload($payment);
        $body = $this->rawBody($payload);

        $signature = $this->signatures()->signRaw('shared-business-secret-for-tests', $body);

        $settled = $this->payments()->handleProviderCallback(self::PROVIDER, $payload, $signature, $body);

        $this->assertSame(
            Payment::STATUS_PAID,
            $settled->status,
            'The legacy (timestampless) callback must still settle the payment.'
        );
    }

    public function test_a_timestamped_callback_settles_and_a_wrong_provider_secret_does_not(): void
    {
        config(['webhooks.business.providers.'.self::PROVIDER => 'bkash-only-secret']);

        // (a) signed with the shared secret while the provider has its own:
        // refused, and nothing moves.
        $payment = $this->makePendingPayment();
        $payload = $this->callbackPayload($payment);
        $body = $this->rawBody($payload);
        $timestamp = time();

        $wrong = $this->signatures()->sign('shared-business-secret-for-tests', $timestamp, $body);

        try {
            $this->payments()->handleProviderCallback(self::PROVIDER, $payload, $wrong, $body, $timestamp);

            $this->fail('A callback signed with the retired shared secret settled a payment.');
        } catch (DomainException) {
            // expected
        }

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);

        // (b) signed with the provider's own secret: settles.
        $correct = $this->signatures()->sign('bkash-only-secret', $timestamp, $body);

        $settled = $this->payments()->handleProviderCallback(self::PROVIDER, $payload, $correct, $body, $timestamp);

        $this->assertSame(Payment::STATUS_PAID, $settled->status);
    }
}
