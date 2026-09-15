<?php

namespace App\Services;

use App\Models\WebhookEndpoint;
use App\Models\WebhookEvent;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Crypt;

/**
 * Phase 15 — outbound webhook subscription management (admin-only).
 *
 * Endpoints are created with a one-time signing secret (returned exactly
 * once, stored encrypted). Secret rotation is admin-only. Subscriptions are
 * event-scoped: a subscriber only ever receives its subscribed events.
 */
class WebhookSubscriptionService
{
    public function __construct(
        protected WebhookSignatureService $signatures,
        protected AuditLogService $audit,
    ) {
    }

    /**
     * Create an endpoint and return it with the plaintext secret attached
     * transiently (shown exactly once).
     *
     * @param  string[]  $events
     */
    public function create(User $admin, string $url, string $description, array $events): array
    {
        $url = trim($url);
        $description = trim($description);

        if (! preg_match('#^https?://#i', $url)) {
            throw new DomainException('The webhook URL must start with http:// or https://.');
        }

        $events = $this->sanitizeEvents($events);

        if ($events === []) {
            throw new DomainException('Select at least one event to subscribe to.');
        }

        $secret = $this->signatures->generateSecret();

        $endpoint = new WebhookEndpoint();
        $endpoint->user_id = $admin->id;
        $endpoint->url = mb_substr($url, 0, 500);
        $endpoint->description = $description !== '' ? mb_substr($description, 0, 255) : null;
        $endpoint->status = WebhookEndpoint::STATUS_ACTIVE;
        $endpoint->secret_encrypted = Crypt::encryptString($secret);
        $endpoint->events = $events;
        $endpoint->consecutive_failures = 0;
        $endpoint->save();

        $this->audit->recordQuietly($admin, 'webhook.endpoint_created', 'webhook_endpoint', $endpoint->id, [
            'metadata' => ['url' => $endpoint->url, 'events' => $events],
        ]);

        return ['endpoint' => $endpoint, 'secret' => $secret];
    }

    /**
     * Rotate the signing secret (admin-only). The new secret is shown once.
     */
    public function rotateSecret(User $admin, WebhookEndpoint $endpoint): string
    {
        $secret = $this->signatures->generateSecret();

        $endpoint->secret_encrypted = Crypt::encryptString($secret);
        $endpoint->save();

        $this->audit->recordQuietly($admin, 'webhook.secret_rotated', 'webhook_endpoint', $endpoint->id, [
            'metadata' => ['url' => $endpoint->url],
        ]);

        return $secret;
    }

    /**
     * Enable/disable an endpoint.
     */
    public function setStatus(User $admin, WebhookEndpoint $endpoint, string $status): WebhookEndpoint
    {
        if (! in_array($status, [WebhookEndpoint::STATUS_ACTIVE, WebhookEndpoint::STATUS_DISABLED], true)) {
            throw new DomainException('Invalid endpoint status.');
        }

        $endpoint->status = $status;
        $endpoint->save();

        $this->audit->recordQuietly($admin, 'webhook.endpoint_status', 'webhook_endpoint', $endpoint->id, [
            'metadata' => ['status' => $status, 'url' => $endpoint->url],
        ]);

        return $endpoint;
    }

    /**
     * All endpoints, newest first.
     */
    public function all()
    {
        return WebhookEndpoint::orderByDesc('id')->get();
    }

    /**
     * The closed event vocabulary a subscriber may select from.
     *
     * @return string[]
     */
    public function vocabulary(): array
    {
        return (array) config('webhooks.events', []);
    }

    /**
     * @param  string[]  $events
     * @return string[]
     */
    protected function sanitizeEvents(array $events): array
    {
        $known = $this->vocabulary();

        $clean = array_values(array_unique(array_filter(
            array_map('trim', $events),
            fn (string $event) => $event !== '' && in_array($event, $known, true),
        )));

        sort($clean);

        return $clean;
    }
}
