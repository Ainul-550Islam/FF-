<?php

namespace Tests\Unit\Push;

use App\Models\MobileDevice;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Push\PushDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PushDispatcherTest extends TestCase
{
    use RefreshDatabase;

    private function userWithDevice(string $provider = 'fcm'): array
    {
        $user = User::factory()->create();
        $user->role = 'player';
        $user->account_status = 'active';
        $user->save();

        $device = new MobileDevice();
        $device->user_id = $user->id;
        $device->platform = $provider === 'apns' ? 'ios' : 'android';
        $device->provider = $provider;
        $device->token_hash = MobileDevice::hashToken('token-'.$provider);
        $device->encrypted_token = 'token-'.$provider;
        $device->is_active = true;
        $device->save();

        return [$user, $device];
    }

    private function notification(int $userId, string $type = 'match.completed'): Notification
    {
        $n = new Notification();
        $n->user_id = $userId;
        $n->type = $type;
        $n->title = 'Title';
        $n->body = 'Body';
        $n->save();

        return $n;
    }

    /**
     * Configure FCM with a real (generated) RSA service-account key so the
     * OAuth JWT can actually be signed, then fake the HTTP endpoints.
     */
    private function configureFcm(int $status = 200, array $body = []): void
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        $pem = '';
        openssl_pkey_export($key, $pem);

        config()->set('mobile.push.fcm_enabled', true);
        config()->set('mobile.push.fcm_project_id', 'test-project');
        config()->set('mobile.push.fcm_client_email', 'svc@test.iam.gserviceaccount.com');
        config()->set('mobile.push.fcm_private_key', $pem);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 3600], 200),
            'fcm.googleapis.com/*' => Http::response($body, $status),
        ]);
    }

    #[Test]
    public function does_not_deliver_when_transport_is_unconfigured(): void
    {
        // Default config: push disabled — the dispatcher must make no HTTP
        // calls and must not crash.
        Http::fake();
        [$user] = $this->userWithDevice();
        $notification = $this->notification($user->id);

        $this->app->make(PushDispatcher::class)->sendToUser($user, $notification);

        Http::assertNothingSent();

        // Device stays active — nothing was attempted.
        $this->assertSame(1, MobileDevice::where('is_active', true)->count());
    }

    #[Test]
    public function delivers_via_fcm_when_configured(): void
    {
        $this->configureFcm(200);
        [$user] = $this->userWithDevice('fcm');
        $notification = $this->notification($user->id);

        $this->app->make(PushDispatcher::class)->sendToUser($user, $notification);

        Http::assertSentCount(2); // OAuth token + message send
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'fcm.googleapis.com/v1/projects/test-project/messages:send');
        });

        $this->assertSame(1, MobileDevice::where('is_active', true)->count());
    }

    #[Test]
    public function deactivates_device_when_provider_reports_unregistered(): void
    {
        $this->configureFcm(404, [
            'error' => [
                'code' => 404,
                'status' => 'NOT_FOUND',
                'message' => 'Requested entity was not found.',
                'details' => [
                    ['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED'],
                ],
            ],
        ]);
        [$user, $device] = $this->userWithDevice('fcm');
        $notification = $this->notification($user->id);

        $this->app->make(PushDispatcher::class)->sendToUser($user, $notification);

        $this->assertSame(false, (bool) $device->fresh()->is_active);
    }

    #[Test]
    public function respects_disabled_category_preference(): void
    {
        $this->configureFcm(200);
        [$user] = $this->userWithDevice('fcm');

        $pref = new NotificationPreference();
        $pref->user_id = $user->id;
        $pref->push_match = false;
        $pref->save();

        $this->app->make(PushDispatcher::class)->sendToUser($user, $this->notification($user->id, 'match.completed'));

        Http::assertNothingSent();
    }

    #[Test]
    public function security_notifications_are_always_delivered(): void
    {
        $this->configureFcm(200);
        [$user] = $this->userWithDevice('fcm');

        // Even with every toggleable category off, security still delivers.
        $pref = new NotificationPreference();
        $pref->user_id = $user->id;
        $pref->push_tournament = false;
        $pref->push_match = false;
        $pref->push_team = false;
        $pref->push_payment = false;
        $pref->push_payout = false;
        $pref->push_dispute = false;
        $pref->push_support = false;
        $pref->save();

        $this->app->make(PushDispatcher::class)->sendToUser($user, $this->notification($user->id, 'auth.suspicious_login'));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'messages:send');
        });
    }

    #[Test]
    public function push_body_never_contains_sensitive_values(): void
    {
        $this->configureFcm(200);
        [$user] = $this->userWithDevice('fcm');

        $notification = new Notification();
        $notification->user_id = $user->id;
        $notification->type = 'payment.verified';
        $notification->title = 'Payment verified';
        $notification->body = 'Your wallet balance is 50,000 BDT';
        $notification->save();

        $this->app->make(PushDispatcher::class)->sendToUser($user, $notification);

        $messageRequests = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn ($request) => str_contains($request->url(), 'messages:send'));

        $this->assertNotEmpty($messageRequests);
        $payload = (string) json_encode($messageRequests->first()->data());
        $this->assertStringNotContainsString('50,000', $payload);
        $this->assertStringNotContainsString('50000', $payload);
    }
}
