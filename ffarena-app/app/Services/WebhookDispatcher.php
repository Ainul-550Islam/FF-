<?php

namespace App\Services;

use App\Jobs\SendWebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Str;

/**
 * Phase 15 — outbound webhook dispatch.
 *
 * Given a domain event + payload, enqueues a signed delivery for every
 * active endpoint subscribed to that event. Dispatching is best-effort by
 * contract (`dispatchQuietly`) and a delivery failure never rolls back the
 * originating business state.
 */
class WebhookDispatcher
{
    /**
     * Enqueue deliveries for a domain event.
     *
     * @param  array<string, mixed>  $payload  a redacted, non-sensitive payload
     * @return int number of deliveries enqueued
     */
    public function dispatch(string $event, array $payload, ?int $sourceUserId = null): int
    {
        if (! $this->isKnownEvent($event)) {
            return 0;
        }

        $endpoints = WebhookEndpoint::where('status', WebhookEndpoint::STATUS_ACTIVE)
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint) => $endpoint->subscribesTo($event));

        $sent = 0;

        foreach ($endpoints as $endpoint) {
            SendWebhookDelivery::dispatch($endpoint, $event, $this->redact($payload), (string) Str::uuid());
            $sent++;
        }

        return $sent;
    }

    /**
     * Dispatch without ever throwing into the caller.
     */
    public function dispatchQuietly(string $event, array $payload, ?int $sourceUserId = null): int
    {
        try {
            return $this->dispatch($event, $payload, $sourceUserId);
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    /**
     * Whether an event is in the closed outbound vocabulary.
     */
    public function isKnownEvent(string $event): bool
    {
        return in_array($event, (array) config('webhooks.events', []), true);
    }

    /**
     * Strip fields that must never leave the platform (raw identifiers are
     * replaced by opaque ids where possible; sensitive keys are removed).
     */
    protected function redact(array $payload): array
    {
        $forbidden = [
            'email', 'phone', 'ip', 'ip_address', 'device', 'device_hash',
            'ip_hash', 'risk_score', 'risk_level', 'secret', 'token',
            'password', 'evidence', 'identity_document', 'ledger', 'wallet',
        ];

        $clean = [];

        foreach ($payload as $key => $value) {
            if (in_array($key, $forbidden, true)) {
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }
}
