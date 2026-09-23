<?php

namespace App\Services;

/**
 * Phase 15 — deterministic HMAC-SHA256 webhook signatures.
 *
 * Outbound signature:  HMAC-SHA256(secret, "{timestamp}.{raw_body}")
 * The signature is sent in X-FFArena-Signature alongside X-FFArena-Timestamp,
 * X-FFArena-Event and X-FFArena-Delivery. Replays are prevented by timestamp
 * tolerance plus the delivery/event idempotency.
 *
 * Inbound verification follows the same scheme with the provider's secret.
 */
class WebhookSignatureService
{
    /**
     * Sign a payload for outbound delivery.
     */
    public function sign(string $secret, int $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }

    /**
     * Verify an inbound signature against a secret (raw-body HMAC-SHA256 —
     * the Phase 08 provider callback convention).
     */
    public function verify(string $secret, string $rawBody, string $signature): bool
    {
        if ($secret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Compute a raw-body HMAC signature (used to bridge the inbound provider
     * secret to the business-layer payment secret without weakening either).
     */
    public function signRaw(string $secret, string $rawBody): string
    {
        return hash_hmac('sha256', $rawBody, $secret);
    }

    /**
     * Extract a timestamp for the signature computation. The standard
     * scheme signs `{timestamp}.{raw_body}`, where the timestamp is taken
     * from the request's timestamp field.
     *
     * @param  int|null  $timestamp  explicit timestamp (header) when available
     */
    public function verifyWithTimestamp(string $secret, string $rawBody, string $signature, ?int $timestamp): bool
    {
        if ($timestamp === null || $timestamp <= 0) {
            return false;
        }

        $expected = $this->sign($secret, $timestamp, $rawBody);

        return hash_equals($expected, $signature);
    }

    /**
     * Whether a timestamp is within the tolerated skew.
     */
    public function timestampIsFresh(int $timestamp, int $toleranceSeconds = 300): bool
    {
        return abs(time() - $timestamp) <= $toleranceSeconds;
    }

    /**
     * Generate a random signing secret for a new outbound endpoint.
     */
    public function generateSecret(): string
    {
        return 'whsec_'.bin2hex(random_bytes(32));
    }
}
