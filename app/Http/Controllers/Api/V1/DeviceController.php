<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MobileDevice;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 18 — mobile push-token registration (owner-only).
 *
 * The mobile client registers its platform push token here after login and
 * removes it on logout/forced-logout. Raw tokens are hashed immediately and
 * never written to logs or responses.
 */
class DeviceController extends Controller
{
    /**
     * GET /api/v1/me/devices — the caller's registered devices. The token
     * reference itself is never serialized (not even the hash).
     */
    public function index(Request $request): JsonResponse
    {
        $devices = $request->user()->mobileDevices()
            ->orderByDesc('last_seen_at')
            ->get();

        $rows = $devices->map(fn (MobileDevice $d) => $this->serialize($d));

        return ApiResponse::data($rows);
    }

    /**
     * POST /api/v1/me/devices — register (or refresh) a push token.
     *
     * The raw token is written to the `encrypted` cast column (encrypted at
     * rest) and its sha256 hash is used for dedup. Neither is ever returned.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', Rule::in(MobileDevice::PLATFORMS)],
            'provider' => ['required', Rule::in(MobileDevice::PROVIDERS)],
            'token' => ['required', 'string', 'max:'.(int) config('mobile.push.device_token_max_length', 4096)],
            'device_label' => ['nullable', 'string', 'max:120'],
            'app_version' => ['nullable', 'string', 'max:30'],
            'environment' => ['nullable', Rule::in(MobileDevice::ENVIRONMENTS)],
        ]);

        $user = $request->user();
        $hash = MobileDevice::hashToken((string) $data['token']);

        $device = MobileDevice::where('user_id', $user->id)
            ->where('token_hash', $hash)
            ->first();

        if ($device === null) {
            $device = new MobileDevice;
            $device->user_id = $user->id;
            $device->token_hash = $hash;
            $device->is_active = true;
        }

        $device->platform = $data['platform'];
        $device->provider = $data['provider'];
        $device->encrypted_token = (string) $data['token'];
        $device->is_active = true;

        if (array_key_exists('device_label', $data) && $data['device_label'] !== null) {
            $device->device_label = $data['device_label'];
        }

        if (array_key_exists('app_version', $data) && $data['app_version'] !== null) {
            $device->app_version = $data['app_version'];
        }

        if (array_key_exists('environment', $data) && $data['environment'] !== null) {
            $device->environment = $data['environment'];
        }

        $device->last_seen_at = now();
        $device->save();

        $this->enforceCap($user);

        return ApiResponse::data($this->serialize($device->fresh()), [], $device->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * DELETE /api/v1/me/devices/{device} — remove a push token (e.g. logout).
     */
    public function destroy(Request $request, MobileDevice $device): JsonResponse
    {
        $this->authorize('delete', $device);

        $device->delete();

        return ApiResponse::noContent();
    }

    /**
     * Cap the number of active device registrations per user. When the cap is
     * exceeded the least-recently-seen extras are deactivated (never deleted,
     * so delivery state history is preserved).
     */
    protected function enforceCap($user): void
    {
        $max = (int) config('mobile.devices.max_devices_per_user', 25);

        $extras = $user->mobileDevices()
            ->where('is_active', true)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->skip($max)
            ->take(100)
            ->get();

        foreach ($extras as $extra) {
            $extra->is_active = false;
            $extra->save();
        }
    }

    protected function serialize(MobileDevice $device): array
    {
        return [
            'id' => $device->id,
            'platform' => $device->platform,
            'provider' => $device->provider,
            'device_label' => $device->device_label,
            'app_version' => $device->app_version,
            'environment' => $device->environment,
            'is_active' => (bool) $device->is_active,
            'last_seen_at' => $device->last_seen_at?->toISOString(),
            'created_at' => $device->created_at?->toISOString(),
        ];
    }
}
