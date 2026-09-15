<?php

namespace App\Services\Push;

/**
 * Phase 19 — a push delivery transport (FCM or APNs).
 *
 * Transports are credential-driven and must report honestly: when a provider
 * is not configured, `isConfigured()` returns false and the dispatcher skips
 * it. Delivery is never faked.
 */
interface PushTransport
{
    /**
     * Provider name reported by registered devices ('fcm' | 'apns' | 'none').
     */
    public function provider(): string;

    /**
     * True only when this transport has a real, usable credential set.
     */
    public function isConfigured(): bool;

    /**
     * Deliver one message to one device token. Never throws.
     */
    public function send(PushMessage $message, string $token): PushResult;
}
