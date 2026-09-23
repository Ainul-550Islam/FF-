<?php

namespace Tests\Feature\Api;

use App\Models\Payment;
use App\Models\WebhookEvent;
use App\Services\PaymentService;

/**
 * Phase 15 — inbound webhook verification: signature, timestamp tolerance,
 * provider identity, event-id idempotency, and business validation.
 */
class ApiWebhookTest extends ApiTestCase
{
    protected string $secret = 'ffarena-local-webhook-secret';

    protected function sign(string $rawBody): string
    {
        return hash_hmac('sha256', $rawBody, $this->secret);
    }

    protected function makePendingPayment(string $provider = 'bkash', int $amountMinor = 50000): Payment
    {
        $org = $this->user(['role' => 'organizer']);
        $player = $this->user();
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 500]);
        $team = $this->makeTeam($tournament, $player);

        return app(PaymentService::class)->createForTeam(
            $tournament, $team, $player, $provider, 'TRX0001', $provider, 'TRX0001'
        );
    }

    protected function webhookRequest(string $provider, array $payload, ?string $rawBody = null, array $extraHeaders = [])
    {
        $rawBody = $rawBody ?? json_encode($payload);
        $headers = array_merge([
            'X-Signature' => $this->sign($rawBody),
            'X-Timestamp' => (string) time(),
            'Content-Type' => 'application/json',
        ], $extraHeaders);

        return $this->withHeaders($headers)->postJson('/api/v1/webhooks/inbound/'.$provider, $payload);
    }

    public function test_payment_webhook_settles_payment(): void
    {
        $payment = $this->makePendingPayment();

        $payload = [
            'event' => 'payment.succeeded',
            'event_id' => 'evt-settle-1',
            'payment_id' => $payment->id,
            'provider_reference' => 'TRX0001',
            'amount_minor' => 50000,
            'currency' => 'BDT',
            'status' => 'paid',
        ];

        $res = $this->webhookRequest('bkash', $payload);

        $res->assertStatus(200)->assertJsonPath('data.status', 'processed');

        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        $this->assertSame(1, WebhookEvent::where('external_event_id', 'evt-settle-1')->count());
    }

    public function test_duplicate_event_id_is_idempotent(): void
    {
        $payment = $this->makePendingPayment();

        $payload = [
            'event' => 'payment.succeeded',
            'event_id' => 'evt-dup-1',
            'payment_id' => $payment->id,
            'provider_reference' => 'TRX0001',
            'amount_minor' => 50000,
            'currency' => 'BDT',
            'status' => 'paid',
        ];

        $first = $this->webhookRequest('bkash', $payload);
        $first->assertStatus(200)->assertJsonPath('data.replay', false);

        $second = $this->webhookRequest('bkash', $payload);
        $second->assertStatus(200)->assertJsonPath('data.replay', true)->assertJsonPath('data.status', 'replayed');

        // One stored event; the payment settled exactly once.
        $this->assertSame(1, WebhookEvent::where('external_event_id', 'evt-dup-1')->count());
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $payload = [
            'event' => 'payment.succeeded',
            'event_id' => 'evt-bad-1',
            'payment_id' => 1,
            'amount_minor' => 50000,
            'currency' => 'BDT',
            'status' => 'paid',
        ];

        $this->withHeaders([
            'X-Signature' => 'deadbeef',
            'X-Timestamp' => (string) time(),
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/webhooks/inbound/bkash', $payload)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'webhook_rejected');
    }

    public function test_stale_timestamp_is_rejected_as_replay(): void
    {
        $payload = [
            'event' => 'payment.succeeded',
            'event_id' => 'evt-stale-1',
            'payment_id' => 1,
            'amount_minor' => 50000,
            'currency' => 'BDT',
            'status' => 'paid',
        ];

        $this->webhookRequest('bkash', $payload, null, [
            'X-Timestamp' => (string) (time() - 600),
        ])->assertStatus(401);
    }

    public function test_unknown_provider_is_rejected(): void
    {
        $this->webhookRequest('unknown-provider', ['event' => 'payment.succeeded'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'webhook_rejected');
    }

    public function test_amount_mismatch_is_rejected_and_marked_failed(): void
    {
        $payment = $this->makePendingPayment();

        $payload = [
            'event' => 'payment.succeeded',
            'event_id' => 'evt-mismatch-1',
            'payment_id' => $payment->id,
            'provider_reference' => 'TRX0001',
            'amount_minor' => 1, // wrong amount
            'currency' => 'BDT',
            'status' => 'paid',
        ];

        $this->webhookRequest('bkash', $payload)
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'webhook_rejected');

        $event = WebhookEvent::where('external_event_id', 'evt-mismatch-1')->first();
        $this->assertSame(WebhookEvent::STATUS_FAILED, $event->status);

        // The payment was never touched.
        $this->assertNotSame(Payment::STATUS_PAID, $payment->fresh()->status);
    }

    public function test_non_payment_event_is_recorded_and_ignored(): void
    {
        $payload = [
            'event' => 'tournament.created',
            'event_id' => 'evt-ignore-1',
            'tournament_id' => 123,
        ];

        $this->webhookRequest('bkash', $payload)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'ignored');

        $this->assertSame(
            WebhookEvent::STATUS_IGNORED,
            WebhookEvent::where('external_event_id', 'evt-ignore-1')->first()->status
        );
    }
}
