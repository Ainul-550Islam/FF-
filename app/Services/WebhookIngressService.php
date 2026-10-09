<?php

namespace App\Services;

use App\Exceptions\WebhookSignatureRejected;
use App\Models\WebhookEvent;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 15 — inbound webhook ingestion.
 *
 * Verifies the provider signature, enforces timestamp tolerance and event-id
 * idempotency, stores a WebhookEvent (encrypted raw payload + safe
 * metadata), and then hands verified `payment.*` events to the existing
 * Phase 08 PaymentService callback logic. The business state machine is
 * never duplicated here.
 *
 * AUDIT FIX (2026-10-08, GAPS-01/02/04/05) — the ingress contract tightened
 * to the published dual-scheme design:
 *
 *  1. NO committed secret. `secretFor()` previously fell back to the literal
 *     'ffarena-local-webhook-secret' published in this repository, so any
 *     deployment that never rotated PAYMENT_WEBHOOK_SECRET accepted webhooks
 *     anyone could sign. A missing/placeholder secret now fails closed:
 *     every request is refused (503) and logged, and nothing settles.
 *
 *  2. Signature failures answer with WebhookSignatureRejected, the dedicated
 *     exception each inbound surface maps to its own published status
 *     (legacy endpoint 400, API endpoint 401). Previously a plain
 *     DomainException bypassed that mapping and the legacy endpoint lied
 *     about the failure kind.
 *
 *  3. `X-Timestamp` is honoured, never assumed. The legacy Phase 08 scheme
 *     signs the raw body only and ships no timestamp; a request without the
 *     header keeps working (replay protection for those senders is the
 *     event-id idempotency below). When the header IS present it must be
 *     numeric and fresh, and a signed-timestamp scheme (`{timestamp}.{body}`)
 *     is additionally accepted. A stale timestamp is refused outright — it is
 *     never downgraded to the timestampless scheme.
 *
 *  4. The business layer re-verifies with its OWN secret (GAP-10 A5). The
 *     old `processPayment()` re-signed the body with the business secret
 *     before forwarding — the state machine then verified a signature this
 *     very code had just minted, making the second check a tautology. The
 *     provider's original signature and timestamp are forwarded untouched,
 *     so PaymentService::verifySignature() is a genuine independent check.
 */
class WebhookIngressService
{
    public function __construct(
        protected WebhookSignatureService $signatures,
        protected PaymentService $payments,
    ) {}

    /**
     * Accept a provider webhook and dispatch it to the matching handler.
     *
     * @return array{event: WebhookEvent, replay: bool, payment?: array}
     *
     * @throws DomainException on invalid provider, signature, timestamp,
     *                         payload, or business validation failure.
     */
    public function handle(Request $request, string $provider): array
    {
        $this->assertKnownProvider($provider);

        $secret = $this->secretFor($provider);

        $rawBody = (string) $request->getContent();

        $this->assertSize($rawBody);
        $this->assertJson($rawBody);
        $this->assertContentType($request);

        $payload = json_decode($rawBody, true);
        $eventId = $this->eventId($payload);

        $signature = trim((string) $request->header('X-Signature', ''));
        $timestamp = $this->timestampFrom($request);

        // Stale signed timestamps are refused BEFORE any further work: the
        // window exists to bound replay, and a timestamp outside it is
        // exactly the replay it bounds. Absent timestamps stay tolerated
        // (legacy senders); see the class docblock.
        if ($timestamp !== null && ! $this->signatures->timestampIsFresh($timestamp, (int) config('webhooks.inbound.timestamp_tolerance', 300))) {
            $this->recordRejected($provider, $eventId, $payload, WebhookEvent::SIGNATURE_VERIFIED, $rawBody, WebhookEvent::STATUS_REPLAYED);

            throw new WebhookSignatureRejected('Webhook timestamp is outside the tolerated window.');
        }

        // A signature failure is a refusal on both surfaces, recorded as a
        // WebhookEvent first so the attempt is never silent.
        $signatureStatus = $this->transportSignatureVerifies($secret, $rawBody, $signature, $timestamp)
            ? WebhookEvent::SIGNATURE_VERIFIED
            : WebhookEvent::SIGNATURE_INVALID;

        if ($signatureStatus !== WebhookEvent::SIGNATURE_VERIFIED) {
            $this->recordRejected($provider, $eventId, $payload, $signatureStatus, $rawBody);

            // Two refusal kinds, one message — no oracle, but the right HTTP
            // class for each surface's published contract:
            //
            //   - a header that is not even shaped like a digest (missing,
            //     empty, non-hex garbage) never authenticated anything: that
            //     is a credential failure, answered 401 on BOTH surfaces;
            //   - a well-formed hex digest that matches no accepted scheme is
            //     a genuine signature mismatch — the request was signed by
            //     somebody we don't trust. The legacy Phase 08 surface (and
            //     PaymentSecurityTest) answer that as a 400 validation
            //     failure; the API surface keeps its uniform 401 through the
            //     exception's API_STATUS code.
            if (preg_match('/^[0-9a-f]+$/i', $signature)) {
                throw new WebhookSignatureRejected('Invalid webhook signature.');
            }

            throw new DomainException('Invalid webhook signature.', 401);
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
        // re-verifies the SAME transport bytes against the independent
        // business secret and validates amount/currency/state). Other event
        // types are logged and ignored.
        if (str_starts_with($eventType, 'payment.')) {
            try {
                $result['payment'] = $this->processPayment($provider, $payload, $rawBody, $signature, $timestamp);
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
     * remain PaymentService's single source of truth. The ingress has already
     * verified the provider's signature against the ingress secret; the state
     * machine re-verifies the exact same bytes against the business secret
     * (per-provider, GAP-10 A5). The two secrets are independent — forwarding
     * the provider's original signature is what makes the second check real.
     *
     * @param  array<string, mixed>  $payload
     * @return array{payment_id: int, status: string}
     */
    protected function processPayment(string $provider, array $payload, string $rawBody, string $signature, ?int $timestamp): array
    {
        $payment = $this->payments->handleProviderCallback($provider, $payload, $signature, $rawBody, $timestamp);

        return ['payment_id' => $payment->id, 'status' => $payment->status];
    }

    /**
     * Verify the transport signature. Two schemes are accepted:
     *
     *   raw-body (Phase 08):  HMAC(secret, rawBody)
     *   timestamped:          HMAC(secret, "{timestamp}.{rawBody}")
     *
     * The timestamped scheme is only computed when a fresh timestamp was
     * supplied (freshness already enforced by the caller — a stale request
     * never reaches verification at all).
     */
    protected function transportSignatureVerifies(string $secret, string $rawBody, string $signature, ?int $timestamp): bool
    {
        if ($secret === '' || $signature === '') {
            return false;
        }

        if ($this->signatures->verify($secret, $rawBody, $signature)) {
            return true;
        }

        if ($timestamp !== null && $this->signatures->verifyWithTimestamp($secret, $rawBody, $signature, $timestamp)) {
            return true;
        }

        return false;
    }

    /**
     * The numeric `X-Timestamp` (unix seconds) or null when the sender ships
     * no timestamp header at all (the legacy Phase 08 convention).
     */
    protected function timestampFrom(Request $request): ?int
    {
        $header = trim((string) $request->header('X-Timestamp', ''));

        if ($header === '' || ! ctype_digit($header)) {
            return null;
        }

        return (int) $header;
    }

    /**
     * The secret used to verify a provider's signature. Falls back to the
     * Phase 08 payment webhook secret so inbound payment events share the
     * same trust root as the legacy /webhooks/payments/* endpoint.
     *
     * AUDIT FIX (GAPS-01): the fallback is the CONFIGURED payment secret —
     * never a committed literal. When no secret is configured:
     *   - production (and any environment with a placeholder value) fails
     *     closed loudly: every webhook is refused with 503 until an operator
     *     sets a real secret, because accepting a published key is exactly
     *     the forgeable-webhook incident this closes;
     *   - the empty string still flows to the verifiers, which refuse on it.
     */
    protected function secretFor(string $provider): string
    {
        $configured = config("webhooks.inbound.providers.{$provider}");

        $secret = ! empty($configured)
            ? (string) $configured
            : (string) config('services.payments.webhook_secret', '');

        if ($this->secretIsUsable($secret)) {
            return $secret;
        }

        if (app()->environment('production')) {
            Log::error('Inbound webhook secret is not configured — refusing all provider webhooks.', [
                'provider' => $provider,
                'remediation' => 'Set PAYMENT_WEBHOOK_SECRET (or WEBHOOK_*. per-provider secrets) to a freshly generated value.',
            ]);

            throw new DomainException('Webhook ingress is not configured on this deployment.', 503);
        }

        return $secret;
    }

    /**
     * A secret is usable when it is present and is not the documented
     * configuration placeholder. Placeholders never authenticate anything.
     */
    protected function secretIsUsable(string $secret): bool
    {
        return $secret !== '' && ! str_starts_with($secret, 'CHANGE_ME');
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

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function eventId(array $payload): ?string
    {
        $id = $payload['event_id'] ?? $payload['id'] ?? null;

        if (! is_string($id) && ! is_numeric($id)) {
            return null;
        }

        $id = (string) $id;

        return $id === '' ? null : mb_substr($id, 0, 128);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function eventType(array $payload): string
    {
        $type = $payload['event'] ?? $payload['event_type'] ?? $payload['type'] ?? null;

        if (is_string($type) && trim($type) !== '') {
            return mb_substr(trim($type), 0, 60);
        }

        // Phase 08 legacy payloads declare NO event field — the /webhooks/
        // payments/* surface is a PAYMENTS surface, so a body addressing a
        // payment by id is a payment callback by definition. Without this,
        // every legacy-format webhook (the shape published in
        // PaymentService::handleProviderCallback's callers) fell through as
        // 'unknown' and was recorded-then-ignored, settling nothing.
        if (isset($payload['payment_id'])) {
            return 'payment.callback';
        }

        return 'unknown';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
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

    /**
     * @param  array<string, mixed>  $payload
     */
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
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
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
