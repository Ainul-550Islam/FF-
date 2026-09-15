<?php

namespace App\Services;

use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

/**
 * Security / login history recorder (Phase 14).
 *
 * Records the closed vocabulary of authentication events with only
 * pseudonymous context: an HMAC'd IP hash, an HMAC'd device hash and a
 * derived "browser on OS" device label. Raw IPs, raw fingerprints and
 * credentials are never stored, and events are append-only.
 */
class LoginEventService
{
    public function __construct(
        protected DeviceFingerprintService $devices,
        protected IpIntelligenceService $ipIntel,
    ) {
    }

    /**
     * Record a login/security event.
     */
    public function record(?User $user, string $event, string $status = LoginEvent::STATUS_SUCCESS, ?Request $request = null, array $metadata = []): LoginEvent
    {
        if (! in_array($event, LoginEvent::EVENTS, true)) {
            throw new \InvalidArgumentException("Unknown login event [{$event}].");
        }

        if (! in_array($status, [LoginEvent::STATUS_SUCCESS, LoginEvent::STATUS_FAILURE], true)) {
            throw new \InvalidArgumentException("Unknown login event status [{$status}].");
        }

        $entry = new LoginEvent();
        $entry->user_id = $user?->id;
        $entry->event = $event;
        $entry->status = $status;

        if ($request !== null) {
            $ip = (string) $request->ip();
            $entry->ip_hash = $ip !== '' ? $this->ipIntel->ipHash($ip) : null;
            $entry->device_hash = $this->devices->hashFrom($request);
            $entry->device_label = $this->deviceLabel($request);
        }

        $entry->metadata = $metadata === [] ? null : $metadata;
        $entry->save();

        return $entry;
    }

    /**
     * The user's login history, newest first.
     */
    public function historyFor(User $user, int $perPage = 30): LengthAwarePaginator
    {
        return LoginEvent::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Whether the request's device is new to this account (never seen before
     * in its device associations).
     */
    public function isNewDevice(Request $request, User $user): bool
    {
        $hash = $this->devices->hashFrom($request);

        return $user->deviceLinks()
            ->whereHas('device', fn ($q) => $q->where('device_hash', $hash))
            ->doesntExist();
    }

    /**
     * Derive a short, non-sensitive device label from the user agent.
     */
    public function deviceLabel(Request $request): string
    {
        return $this->devices->deviceLabelFromUserAgent((string) $request->userAgent());
    }
}
