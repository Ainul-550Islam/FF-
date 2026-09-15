<?php

namespace App\Services\Push;

use App\Models\MobileDevice;
use App\Models\Notification;
use App\Models\User;
use App\Services\PushPreferenceService;

/**
 * Phase 19 — fan a persisted in-app notification out to a user's registered
 * mobile devices via the configured push transport.
 *
 * The dispatcher is strictly best-effort: it never throws into the caller
 * (NotificationService), never fakes delivery, and only attempts a send when
 * the corresponding transport reports itself configured. Preferences are
 * enforced here; security notifications can never be muted. A transport
 * reporting an invalid token deactivates that device registration so dead
 * tokens are not retried forever.
 */
final class PushDispatcher
{
    public function __construct(
        protected PushPayloadBuilder $payloads,
        protected PushPreferenceService $preferences,
        protected FcmTransport $fcm,
        protected ApnsTransport $apns,
        protected NullPushTransport $null,
    ) {}

    /**
     * Best-effort push fan-out for one notification to one user.
     */
    public function sendToUser(User $user, Notification $notification): void
    {
        try {
            $category = $this->payloads->categoryFor($notification->type);

            if (! $this->preferences->isEnabled($user, $category)) {
                return;
            }

            $message = $this->payloads->build($notification);

            $devices = $user->mobileDevices()
                ->where('is_active', true)
                ->get();

            foreach ($devices as $device) {
                $this->deliver($device, $message);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function deliver(MobileDevice $device, PushMessage $message): void
    {
        $token = $device->encrypted_token;
        if ($token === null || $token === '') {
            return;
        }

        $transport = $this->transportFor($device->provider);
        if (! $transport->isConfigured()) {
            return;
        }

        $result = $transport->send($message, $token);

        if ($result->invalidToken) {
            // The provider no longer knows this token — stop delivering to it.
            $device->is_active = false;
            $device->save();
        }
    }

    private function transportFor(string $provider): PushTransport
    {
        return match ($provider) {
            'fcm' => $this->fcm,
            'apns' => $this->apns,
            default => $this->null,
        };
    }
}
