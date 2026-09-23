<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceLink;
use App\Models\RiskEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Privacy-conscious device identity + sharing detection (Phase 10).
 *
 * A device is a server-derived pseudonymous hash of the request's observable
 * client headers (HMAC-SHA256 keyed with APP_KEY) — the client can never
 * self-declare a "trusted device", and no raw fingerprint is stored.
 *
 * A device shared by many accounts is a *signal*: legitimate shared devices
 * (family computers, cyber cafés) are tolerated up to the configured
 * thresholds; only above them is a risk signal raised. New accounts on a
 * device that already belongs to a restricted account receive a
 * ban-evasion signal via AccountLinkService.
 */
class DeviceFingerprintService
{
    public function __construct(
        protected FraudRiskService $risk,
        protected AccountLinkService $links,
    ) {}

    /**
     * Derive the pseudonymous device hash from the request (server-side only).
     */
    public function hashFrom(Request $request): string
    {
        $ua = trim((string) $request->userAgent());
        $language = trim((string) $request->header('Accept-Language', ''));

        return hash_hmac('sha256', ($ua ?: 'unknown').'|'.$language, (string) config('app.key'));
    }

    /**
     * Register (or refresh) a user's device association and evaluate
     * device-sharing signals. Never throws — observation only.
     */
    public function register(Request $request, User $user): Device
    {
        $hash = $this->hashFrom($request);

        return DB::transaction(function () use ($hash, $user) {
            $device = Device::where('device_hash', $hash)->lockForUpdate()->first();

            if ($device === null) {
                $device = new Device();
                $device->device_hash = $hash;
                $device->status = Device::STATUS_ACTIVE;
                $device->first_seen_at = now();
                $device->last_seen_at = now();
                $device->save();
            } else {
                $device->last_seen_at = now();
                $device->save();
            }

            $link = DeviceLink::where('device_id', $device->id)
                ->where('user_id', $user->id)
                ->first();

            if ($link === null) {
                $link = new DeviceLink();
                $link->device_id = $device->id;
                $link->user_id = $user->id;
                $link->first_seen_at = now();
                $link->last_seen_at = now();
                $link->save();
            } else {
                $link->last_seen_at = now();
                $link->save();
            }

            // Account-similarity: link this account to the device's other
            // accounts (moderate confidence).
            $others = DeviceLink::where('device_id', $device->id)
                ->where('user_id', '!=', $user->id)
                ->pluck('user_id');

            foreach ($others as $otherId) {
                $other = User::find($otherId);
                if ($other !== null) {
                    $this->links->link($user, $other, 'moderate', ['shared_device'], 'device');
                }
            }

            // Shared-device signals (only above the configured tolerance).
            $distinct = DeviceLink::where('device_id', $device->id)->count();

            $maxShared = (int) config('antifraud.device.max_accounts_shared', 4);
            $strongShared = (int) config('antifraud.device.strong_accounts_shared', 8);

            if ($distinct > $strongShared) {
                $this->risk->recordSignal($user, RiskEvent::TYPE_AUTH_DEVICE_SHARED, RiskEvent::SEVERITY_HIGH, 'device', [
                    'device_id' => $device->id,
                    'account_count' => $distinct,
                ]);
            } elseif ($distinct > $maxShared) {
                $this->risk->recordSignal($user, RiskEvent::TYPE_AUTH_DEVICE_SHARED, RiskEvent::SEVERITY_MEDIUM, 'device', [
                    'device_id' => $device->id,
                    'account_count' => $distinct,
                ]);
            }

            return $device;
        });
    }

    /**
     * Devices associated with a user.
     */
    public function devicesFor(User $user)
    {
        return Device::query()
            ->whereHas('links', fn ($q) => $q->where('user_id', $user->id))
            ->withCount('links')
            ->get();
    }

    /**
     * Distinct users associated with a device.
     */
    public function usersFor(Device $device)
    {
        return $device->users()->get();
    }

    /**
     * Mark a device blocked (e.g. after a confirmed anti-cheat incident).
     */
    public function block(Device $device): Device
    {
        $device->status = Device::STATUS_BLOCKED;
        $device->save();

        return $device;
    }

    /**
     * Derive a short, non-sensitive "browser on OS" label from a raw user
     * agent. No raw fingerprint data is ever returned.
     */
    public function deviceLabelFromUserAgent(string $ua): string
    {
        $os = 'Unknown OS';

        if (preg_match('/Windows/i', $ua)) {
            $os = 'Windows';
        } elseif (preg_match('/Mac OS X|Macintosh/i', $ua)) {
            $os = 'macOS';
        } elseif (preg_match('/Android/i', $ua)) {
            $os = 'Android';
        } elseif (preg_match('/iPhone|iPad|iPod/i', $ua)) {
            $os = 'iOS';
        } elseif (preg_match('/Linux/i', $ua)) {
            $os = 'Linux';
        }

        $browser = 'Unknown browser';

        if (preg_match('/Edg\//i', $ua)) {
            $browser = 'Edge';
        } elseif (preg_match('/OPR\//i', $ua)) {
            $browser = 'Opera';
        } elseif (preg_match('/Firefox\//i', $ua)) {
            $browser = 'Firefox';
        } elseif (preg_match('/Chrome\//i', $ua)) {
            $browser = 'Chrome';
        } elseif (preg_match('/Safari\//i', $ua)) {
            $browser = 'Safari';
        }

        return mb_substr($browser.' on '.$os, 0, 120);
    }
}
