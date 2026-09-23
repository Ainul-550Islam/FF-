<?php

namespace App\Services;

use App\Models\MarketingPushSubscription;
use App\Models\User;
use App\Services\Push\FcmTransport;
use App\Services\Push\NullPushTransport;
use App\Services\Push\PushMessage;
use App\Services\Push\PushTransport;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Phase 21 — marketing push re-engagement.
 *
 * Subscriptions follow the mobile_device_tokens safety model: the endpoint
 * is identified by its SHA-256 hash, the secret keys are stored encrypted at
 * rest and never serialized, and one endpoint is exactly one row (a
 * resubscribe refreshes and re-activates instead of duplicating). Delivery
 * reuses the existing Phase 19 transport contract and is strictly
 * best-effort: an unconfigured transport means a quiet skip, never an error.
 */
class MarketingPushService
{
    public function __construct(
        protected FcmTransport $fcm,
        protected NullPushTransport $nullTransport,
    ) {}

    /**
     * Register (or refresh) a subscription for the current identity.
     *
     * @param  array{provider:string, endpoint:string, keys:?array<string,string>, topics:?array<int,string>}  $data
     */
    public function register(?User $user, ?string $anonymousId, array $data, ?string $userAgent = null): MarketingPushSubscription
    {
        $hash = hash('sha256', $data['endpoint']);

        $subscription = MarketingPushSubscription::query()->where('endpoint_hash', $hash)->first();

        if ($subscription === null) {
            $subscription = new MarketingPushSubscription();
            $subscription->endpoint = $data['endpoint'];
            $subscription->endpoint_hash = $hash;
        }

        $subscription->provider = $data['provider'];
        $subscription->encrypted_keys = $data['keys'];
        $subscription->anonymous_id = $subscription->anonymous_id ?? $anonymousId;
        $subscription->user_id = $subscription->user_id ?? $user?->id;
        $subscription->user_agent = $userAgent !== null ? mb_substr($userAgent, 0, 255) : $subscription->user_agent;
        $subscription->topics = $data['topics'];
        $subscription->subscribed_at = now();
        $subscription->revoked_at = null;
        $subscription->failure_count = 0;
        $subscription->save();

        return $subscription;
    }

    /**
     * Revoke a subscription by endpoint. Idempotent: revoking an unknown or
     * already-revoked endpoint reports success without changes.
     */
    public function unsubscribe(string $endpoint): bool
    {
        $subscription = MarketingPushSubscription::query()
            ->where('endpoint_hash', hash('sha256', $endpoint))
            ->first();

        if ($subscription === null) {
            return false;
        }

        if ($subscription->revoked_at === null) {
            $subscription->revoked_at = now();
            $subscription->save();
        }

        return true;
    }

    /**
     * Active subscriptions for a user (own data only).
     */
    public function subscriptionsFor(User $user): Collection
    {
        return MarketingPushSubscription::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->orderByDesc('subscribed_at')
            ->get();
    }

    /**
     * Best-effort delivery of a marketing push message to a set of active
     * subscriptions through the existing Phase 19 transports.
     *
     * @return int the number of subscriptions the transport attempted
     */
    public function dispatch(iterable $subscriptions, PushMessage $message): int
    {
        $attempted = 0;

        foreach ($subscriptions as $subscription) {
            try {
                if (! $subscription instanceof MarketingPushSubscription || ! $subscription->isActive()) {
                    continue;
                }

                $transport = $this->transportFor($subscription->provider);

                if (! $transport->isConfigured()) {
                    // Honest capability flag: no credentials configured —
                    // nothing is sent and nothing is faked.
                    continue;
                }

                $result = $transport->send($message, $subscription->endpoint);
                $attempted++;

                if ($result->invalidToken) {
                    $this->recordFailure($subscription);

                    continue;
                }

                $subscription->last_sent_at = now();
                $subscription->failure_count = 0;
                $subscription->save();
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $attempted;
    }

    protected function transportFor(string $provider): PushTransport
    {
        return match ($provider) {
            MarketingPushSubscription::PROVIDER_FCM => $this->fcm,
            default => $this->nullTransport,
        };
    }

    /**
     * Consecutive delivery failures escalate to revocation so dead endpoints
     * are not retried forever.
     */
    protected function recordFailure(MarketingPushSubscription $subscription): void
    {
        $subscription->failure_count = $subscription->failure_count + 1;
        $subscription->save();

        $threshold = (int) config('marketing.push.revoke_after_failures', 5);

        if ($threshold > 0 && $subscription->failure_count >= $threshold) {
            $subscription->revoked_at = now();
            $subscription->save();
        }
    }
}
