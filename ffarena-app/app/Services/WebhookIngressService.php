<?php

namespace App\Services;

use App\Models\WebhookEvent;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Phase 15 — inbound webhook ingestion.
 *
 * Verifies the provider signature, enforces timestamp tolerance and event-id
 * idempotency, stores a WebhookEvent (encrypted raw payload + safe
 * metadata), and then hands verified `payment.*` events to the existing
 * Phase 08 PaymentService callback logic. The business state machine is
 * never duplicated here.
 */
class WebhookIngressService
{
    public function __construct(
        protected WebhookSignatureService $signatures,
        protected PaymentService $payments,
    ) {
    }

    /**
     * Accept a provider webhook and dispatch it to the matching handler.
     *
     * @return array{event: WebhookEvent, replay: bool, payment?: array}
     *
     * @throws DomainException on invalid provider, signature, timestamp,
     *                          payload, or business validation failure.
     */
    public function handle(Request $request, string $provider): array
    {
        $this->assertKnownProvider($provider);

        $rawBody = (string) $request->getContent();

        $this->assertSize($rawBody);
        $this->assertJson($rawBody);
        $this->assertContentType($request);

        $payload = json_decode($rawBody, true);
        $eventId = $this->eventId($payload);

        $secret = $this->secretFor($provider);
        $signature = (string) $request->header('X-Signature', '');
        $timestamp = (int) $request->header('X-Timestamp', 0);

        // Raw-body HMAC-SHA256 (the Phase 08 provider convention).
        $signatureStatus = $this->signatures->verify($secret, $rawBody, $signature)
            ? WebhookEvent::SIGNATURE_VERIFIED
            : WebhookEvent::SIGNATURE_INVALID;

        if ($signatureStatus !== WebhookEvent::SIGNATURE_VERIFIED) {
            // Record the rejected attempt, then refuse.
            $this->recordRejected($provider, $eventId, $payload, $signatureStatus, $rawBody);

            throw new DomainException('Invalid webhook signature.', 401);
        }

        if (! $this->signatures->timestampIsFresh($timestamp, (int) config('webhooks.inbound.timestamp_tolerance', 300))) {
            $this->recordRejected($provider, $eventId, $payload, WebhookEvent::SIGNATURE_VERIFIED, $rawBody, WebhookEvent::STATUS_REPLAYED);

            throw new DomainException('Webhook timestamp is outside the tolerated window.', 401);
        }

        $eventType = $this->eventType($payload);

        // Event-id idempotency: a duplicate provider event is idempotent and
        // never processed twice.
        $existing = $eventId !== null
            ? WebhookEvent::where('provider', $provider)->where('external_event_id', $eventId)->first()
            : null;

        if ($existing !== null) {
            return ['event' => $this->markReplay($provider, $eventId) ?? $existing, 'replay' => true];
        }

        try {
            $event = $this->record($provider, $eventId, $eventType, $signatureStatus, $payload, $rawBody);
        } catch (UniqueConstraintViolationException) {
            // Concurrent duplicate delivery: another request inserted the same
            // (provider, external_event_id) first. Resolve to a replay instead
            // of failing — the payment must never be processed twice. On
            // PostgreSQL the failed insert aborts only the savepoint created by
            // record()'s transaction, so the surrounding request stays usable.
            $event = $this->markReplay($provider, $eventId);

            if ($event === null) {
                throw new DomainException('Webhook event could not be recorded.', 500);
            }

            return ['event' => $event, 'replay' => true];
        }

        $result = ['event' => $event, 'replay' => false];

        // Payment events continue through the Phase 08 state machine (which
        // re-verifies the signature against the business secret and validates
        // amount/currency/state). Other event types are logged and ignored.
        if (str_starts_with($eventType, 'payment.')) {
            try {
                $result['payment'] = $this->processPayment($provider, $payload, $rawBody);
                $this->mark($event, WebhookEvent::STATUS_PROCESSED);
            } catch (DomainException $e) {
                // Business validation failed (amount/currency/provider/state)
                // — record the failure, then surface the rejection.
                $this->mark($event, WebhookEvent::STATUS_FAILED);

                throw $e;
            }
        } else {
            $this->mark($event, WebhookEvent::STATUS_IGNORED);
        }

        return $result;
    }

    /**
     * Route a verified payment webhook into the existing Phase 08 handler.
     *
     * The business rules (signature, amount, currency, provider, state)
     * remain PaymentService's single source of truth. The ingress layer has
     * already verified the provider's signature against the ingress secret;
     * here we re-compute the signature against the business secret so the
     * two secrets can differ without weakening either check.
     *
     * @return array{payment_id: int, status: string}
     */
    protected function processPayment(string $provider, array $payload, string $rawBody): array
    {
        $businessSecret = (string) config('services.payments.webhook_secret', '');
        $businessSignature = $this->signatures->signRaw($businessSecret, $rawBody);

        $payment = $this->payments->handleProviderCallback($provider, $payload, $businessSignature, $rawBody);

        return ['payment_id' => $payment->id, 'status' => $payment->status];
    }

    /**
     * The secret used to verify a provider's signature. Falls back to the
     * Phase 08 payment webhook secret so inbound payment events share the
     * same trust root as the legacy /webhooks/payments/* endpoint.
     */
    protected function secretFor(string $provider): string
    {
        $configured = config("webhooks.inbound.providers.{$provider}");

        if (! empty($configured)) {
            return (string) $configured;
        }

        return (string) config('services.payments.webhook_secret', 'ffarena-local-webhook-secret');
    }

    protected function assertKnownProvider(string $provider): void
    {
        $known = array_keys((array) config('webhooks.inbound.providers', []));

        if (! in_array($provider, $known, true)) {
            throw new DomainException('Unknown webhook provider.', 404);
        }
    }

    protected function assertSize(string $rawBody): void
    {
        $max = (int) config('webhooks.inbound.max_payload_bytes', 65536);

        if (strlen($rawBody) > $max) {
            throw new DomainException('Webhook payload is too large.', 413);
        }
    }

    protected function assertJson(string $rawBody): void
    {
        if (json_decode($rawBody, true) === null) {
            throw new DomainException('Webhook payload must be valid JSON.', 400);
        }
    }

    protected function assertContentType(Request $request): void
    {
        $contentType = strtolower((string) $request->header('Content-Type', ''));

        if ($contentType === '' || ! str_contains($contentType, 'application/json')) {
            throw new DomainException('Webhook Content-Type must be application/json.', 415);
        }
    }

    protected function eventId(array $payload): ?string
    {
        $id = $payload['event_id'] ?? $payload['id'] ?? null;

        if (! is_string($id) && ! is_numeric($id)) {
            return null;
        }

        $id = (string) $id;

        return $id === '' ? null : mb_substr($id, 0, 128);
    }

    protected function eventType(array $payload): string
    {
        $type = $payload['event'] ?? $payload['event_type'] ?? $payload['type'] ?? 'unknown';

        return mb_substr((string) $type, 0, 60);
    }

    protected function record(string $provider, ?string $eventId, string $eventType, string $signatureStatus, array $payload, string $rawBody): WebhookEvent
    {
        return DB::transaction(function () use ($provider, $eventId, $eventType, $signatureStatus, $payload, $rawBody) {
            $event = new WebhookEvent();
            $event->provider = $provider;
            $event->external_event_id = $eventId;
            $event->event_type = $eventType;
            $event->signature_status = $signatureStatus;
            $event->status = WebhookEvent::STATUS_VERIFIED;
            $event->attempts = 1;
            $event->received_at = now();
            $event->payload_encrypted = $this->encryptPayload($rawBody);
            $event->metadata = $this->safeMetadata($payload);
            $event->save();

            return $event;
        });
    }

    protected function recordRejected(string $provider, ?string $eventId, array $payload, string $signatureStatus, string $rawBody, string $status = WebhookEvent::STATUS_FAILED): void
    {
        try {
            // The nested transaction becomes a savepoint inside a wider
            // transaction, so a duplicate (provider, external_event_id) — e.g.
            // a previously accepted delivery — rolls back cleanly instead of
            // aborting the request's transaction on PostgreSQL.
            DB::transaction(function () use ($provider, $eventId, $payload, $signatureStatus, $rawBody, $status) {
                $event = new WebhookEvent();
                $event->provider = $provider;
                $event->external_event_id = $eventId;
                $event->event_type = $this->eventType($payload);
                $event->signature_status = $signatureStatus;
                $event->status = $status;
                $event->attempts = 1;
                $event->received_at = now();
                $event->payload_encrypted = $this->encryptPayload($rawBody);
                $event->metadata = $this->safeMetadata($payload);
                $event->save();
            });
        } catch (UniqueConstraintViolationException) {
            // Best-effort rejected-attempt logging; a record for this event id
            // already exists. Never fail the rejection on this.
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Mark an existing event as a replay (idempotent duplicate) and bump its
     * attempt counter. Returns null when the event row is unexpectedly gone.
     */
    protected function markReplay(string $provider, ?string $eventId): ?WebhookEvent
    {
        if ($eventId === null) {
            return null;
        }

        $existing = WebhookEvent::where('provider', $provider)
            ->where('external_event_id', $eventId)
            ->first();

        if ($existing === null) {
            return null;
        }

        $existing->status = $existing->status === WebhookEvent::STATUS_PROCESSED
            ? WebhookEvent::STATUS_REPLAYED
            : $existing->status;
        $existing->attempts = $existing->attempts + 1;
        $existing->save();

        return $existing;
    }

    protected function mark(WebhookEvent $event, string $status): void
    {
        $event->status = $status;
        $event->processed_at = now();
        $event->save();
    }

    /**
     * Encrypt the raw payload at rest (never stored in plaintext).
     */
    protected function encryptPayload(string $rawBody): string
    {
        return Crypt::encryptString($rawBody);
    }

    /**
     * A deliberately minimal, safe metadata subset — never amounts, statuses
     * or identity claims that the business layer will re-validate anyway.
     */
    protected function safeMetadata(array $payload): array
    {
        $safe = [];

        foreach (['event', 'event_type', 'provider_reference', 'currency'] as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key])) {
                $safe[$key] = (string) $payload[$key];
            }
        }

        return $safe;
    }
}
