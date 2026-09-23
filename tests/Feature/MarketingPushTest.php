<?php

namespace Tests\Feature;

use App\Models\MarketingPushSubscription;
use App\Models\User;
use App\Services\MarketingPushService;
use App\Services\MarketingUtmGovernanceService;
use App\Services\Push\PushMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 21 — push re-engagement subscriptions.
 *
 * Endpoints are deduped by their SHA-256 hash, secret keys are stored
 * encrypted and never echoed, unsubscribe is idempotent, and dead endpoints
 * are revoked after repeated delivery failures. Identity is decided
 * server-side (authenticated user, else the anonymous visitor cookie).
 */
class MarketingPushTest extends TestCase
{
    use RefreshDatabase;

    protected function endpoint(): string
    {
        return 'https://fcm.googleapis.com/fcm/send/'.Str::random(40);
    }

    protected function subscribePayload(?string $endpoint = null, array $extra = []): array
    {
        return array_merge([
            'provider' => MarketingPushSubscription::PROVIDER_WEB,
            'endpoint' => $endpoint ?? $this->endpoint(),
            'keys' => [
                'p256dh' => 'BCvQ0kp0u12MnLcXqAe3iAjE1fMUqXV8PZ3Wq6nTWBO0',
                'auth' => 'aT1jc2T5kQ9Pz0iXY5JmWA',
            ],
            'topics' => ['tournaments'],
        ], $extra);
    }

    public function test_guest_subscribes_and_is_never_shown_the_secret_keys(): void
    {
        $response = $this->postJson('/marketing/push/subscribe', $this->subscribePayload())
            ->assertOk()
            ->assertJsonStructure(['ok', 'id', 'provider', 'topics', 'active'])
            ->assertJson(['ok' => true, 'provider' => 'web', 'topics' => ['tournaments'], 'active' => true]);

        $body = $response->json();
        $this->assertArrayNotHasKey('keys', $body, 'Secret keys are never echoed back.');
        $this->assertArrayNotHasKey('encrypted_keys', $body);
        $this->assertArrayNotHasKey('endpoint_hash', $body);

        $row = MarketingPushSubscription::query()->first();
        $this->assertNotNull($row->anonymous_id, 'Guest subscriptions carry the anonymous identity.');
        $this->assertNull($row->user_id);
    }

    public function test_keys_are_encrypted_at_rest_and_hidden_from_serialization(): void
    {
        $this->postJson('/marketing/push/subscribe', $this->subscribePayload())->assertOk();

        $raw = (string) DB::table('marketing_push_subscriptions')->value('encrypted_keys');
        $this->assertStringNotContainsString('BCvQ0kp0u12MnLcXqAe3iAjE1fMUqXV8PZ3Wq6nTWBO0', $raw, 'Keys must not sit plaintext in the database.');

        $serialized = MarketingPushSubscription::query()->first()->toArray();
        $this->assertArrayNotHasKey('encrypted_keys', $serialized);
        $this->assertArrayNotHasKey('endpoint_hash', $serialized);
    }

    public function test_resubscribing_the_same_endpoint_does_not_duplicate(): void
    {
        $endpoint = $this->endpoint();

        $first = $this->postJson('/marketing/push/subscribe', $this->subscribePayload($endpoint))->assertOk()->json('id');
        $second = $this->postJson('/marketing/push/subscribe', $this->subscribePayload($endpoint, [
            'topics' => ['tournaments', 'results'],
        ]))->assertOk()->json('id');

        $this->assertSame($first, $second, 'One endpoint is exactly one row.');
        $this->assertSame(1, MarketingPushSubscription::query()->count());
        $this->assertSame(['tournaments', 'results'], MarketingPushSubscription::query()->value('topics'));
    }

    public function test_authenticated_subscribe_binds_the_user_and_records_the_agent(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->postJson('/marketing/push/subscribe', $this->subscribePayload(), [
            'User-Agent' => 'Mozilla/5.0 (test agent)',
        ])->assertOk()->assertJson(['active' => true]);

        $row = MarketingPushSubscription::query()->first();
        $this->assertSame($user->id, $row->user_id);
        // The anonymous cookie identity is kept as a fallback alongside the
        // user binding (same browser, later logged out — still targetable).
        $this->assertNotNull($row->anonymous_id);
        $this->assertSame('Mozilla/5.0 (test agent)', $row->user_agent);
    }

    public function test_client_supplied_identity_fields_are_ignored(): void
    {
        $this->postJson('/marketing/push/subscribe', $this->subscribePayload(null, [
            'user_id' => 999999,
            'anonymous_id' => 'spoofed-visitor',
        ]))->assertOk();

        $row = MarketingPushSubscription::query()->first();
        $this->assertNull($row->user_id, 'Identity is decided server-side.');
        $this->assertNotNull($row->anonymous_id);
        $this->assertNotSame('spoofed-visitor', $row->anonymous_id);
    }

    public function test_unsubscribe_revokes_without_deleting(): void
    {
        $endpoint = $this->endpoint();
        $this->postJson('/marketing/push/subscribe', $this->subscribePayload($endpoint))->assertOk();

        $this->postJson('/marketing/push/unsubscribe', ['endpoint' => $endpoint])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $row = MarketingPushSubscription::query()->first();
        $this->assertNotNull($row);
        $this->assertNotNull($row->revoked_at);
        $this->assertFalse($row->isActive());
    }

    public function test_unsubscribe_is_idempotent_for_unknown_endpoints(): void
    {
        $this->postJson('/marketing/push/unsubscribe', ['endpoint' => $this->endpoint()])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame(0, MarketingPushSubscription::query()->count());
    }

    public function test_resubscribe_after_unsubscribe_reactivates(): void
    {
        $endpoint = $this->endpoint();
        $this->postJson('/marketing/push/subscribe', $this->subscribePayload($endpoint))->assertOk();
        $this->postJson('/marketing/push/unsubscribe', ['endpoint' => $endpoint])->assertOk();
        $this->postJson('/marketing/push/subscribe', $this->subscribePayload($endpoint))->assertOk();

        $row = MarketingPushSubscription::query()->first();
        $this->assertNull($row->revoked_at, 'Resubscribing re-activates.');
        $this->assertSame(0, (int) $row->failure_count);
        $this->assertTrue($row->isActive());
    }

    public function test_invalid_subscriptions_are_rejected(): void
    {
        // Unknown provider.
        $this->postJson('/marketing/push/subscribe', $this->subscribePayload(null, ['provider' => 'apns']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['provider']);

        // Endpoint looks malformed.
        $this->postJson('/marketing/push/subscribe', $this->subscribePayload(null, ['endpoint' => 'short']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['endpoint']);

        // Web-push endpoints must be HTTPS.
        $this->postJson('/marketing/push/subscribe', $this->subscribePayload('http://insecure.example.com/push'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['endpoint']);

        // Topic list is capped.
        $this->postJson('/marketing/push/subscribe', $this->subscribePayload(null, [
            'topics' => array_map(fn ($i) => "topic-{$i}", range(1, 11)),
        ]))->assertStatus(422)->assertJsonValidationErrors(['topics']);

        // Key bag is capped at two entries.
        $this->postJson('/marketing/push/subscribe', $this->subscribePayload(null, [
            'keys' => ['p256dh' => 'k1', 'auth' => 'k2', 'extra' => 'k3'],
        ]))->assertStatus(422)->assertJsonValidationErrors(['keys']);

        $this->assertSame(0, MarketingPushSubscription::query()->count(), 'No invalid row was persisted.');
    }

    public function test_subscribe_is_throttled_for_public_visitors(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/marketing/push/subscribe', $this->subscribePayload())->assertOk();
        }

        $this->postJson('/marketing/push/subscribe', $this->subscribePayload())
            ->assertStatus(429);
    }

    public function test_dispatch_skips_unconfigured_transports_quietly(): void
    {
        $this->postJson('/marketing/push/subscribe', $this->subscribePayload())->assertOk();

        $subscriptions = MarketingPushSubscription::query()->get();
        $message = new PushMessage('Match starting', 'Your match starts in 5 minutes.', 'marketing.automation', 'normal', []);

        $attempted = app(MarketingPushService::class)->dispatch($subscriptions, $message);

        $this->assertSame(0, $attempted, 'Without credentials nothing is attempted or faked.');
        $this->assertNull(MarketingPushSubscription::query()->value('last_sent_at'));
    }

    public function test_repeated_invalid_tokens_escalate_to_revocation(): void
    {
        $this->postJson('/marketing/push/subscribe', $this->subscribePayload())->assertOk();
        $subscription = MarketingPushSubscription::query()->first();

        $service = app(MarketingPushService::class);
        $recordFailure = new \ReflectionMethod($service, 'recordFailure');
        $recordFailure->invoke($service, $subscription);
        $recordFailure->invoke($service, $subscription);
        $recordFailure->invoke($service, $subscription);
        $recordFailure->invoke($service, $subscription);
        $recordFailure->invoke($service, $subscription);

        $subscription->refresh();
        $this->assertSame(5, (int) $subscription->failure_count);
        $this->assertNotNull($subscription->revoked_at, 'A dead endpoint is revoked after repeated failures.');
        $this->assertFalse($subscription->isActive(), 'Revoked endpoints are never targeted again.');
    }

    public function test_utm_governance_normalizes_for_reporting_only(): void
    {
        // Guard rail for the UTM governance contract: normalize() is a
        // reporting helper (trim + lowercase + collapse whitespace) and is
        // the ONLY sanctioned entry point — the stored attribution
        // originals are never rewritten by governance.
        $service = app(MarketingUtmGovernanceService::class);

        $this->assertSame('facebook', $service->normalize(' FACEBOOK '));
        $this->assertSame('spring launch', $service->normalize("Spring\tLaunch"));
        $this->assertSame('', $service->normalize(null));
        $this->assertSame('', $service->normalize('   '));

        $issues = $service->issues();
        $this->assertSame([], $issues['unregistered_campaigns'], 'A healthy ledger has no unregistered campaigns.');
        $this->assertSame(0, $issues['missing_medium']);
        $this->assertSame([], $issues['inconsistent_source_casing']);
    }
}
