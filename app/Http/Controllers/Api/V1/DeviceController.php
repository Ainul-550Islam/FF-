<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MobileDevice;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mobile push-device registry (Phase 18/19).
 *
 * The raw push token is never stored or echoed: each device is identified by
 * the SHA-256 hash of its token and the token itself is kept encrypted at
 * rest. Rows belong to exactly one user — another user can neither list nor
 * delete them. Registration is an upsert: re-registering the same token
 * refreshes the release metadata instead of creating a duplicate row.
 */
class DeviceController extends Controller
{
    /**
     * GET /api/v1/me/devices — the caller's own devices.
     */
    public function index(Request $request): JsonResponse
    {
        $devices = MobileDevice::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (MobileDevice $device) => $this->present($device))
            ->values();

        return ApiResponse::data($devices);
    }

    /**
     * POST /api/v1/me/devices — register (or refresh) a push token.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'platform' => ['required', 'string', Rule::in(MobileDevice::PLATFORMS)],
                'provider' => ['required', 'string', Rule::in(MobileDevice::PROVIDERS)],
                'token' => ['required', 'string', 'min:1', 'max:'.(int) config('mobile.push.device_token_max_length', 4096)],
                'device_label' => ['nullable', 'string', 'max:120'],
                'app_version' => ['nullable', 'string', 'max:40'],
                'environment' => ['nullable', 'string', Rule::in(MobileDevice::ENVIRONMENTS)],
            ]);
        } catch (ValidationException $e) {
            return ApiResponse::error('validation_error', 'The given data was invalid.', $e->errors(), 422);
        }

        $user = $request->user();
        $hash = MobileDevice::hashToken($data['token']);

        $existing = MobileDevice::query()
            ->where('user_id', $user->id)
            ->where('token_hash', $hash)
            ->first();

        $attributes = [
            'platform' => $data['platform'],
            'provider' => $data['provider'],
            'token_hash' => $hash,
            'encrypted_token' => $data['token'],
            'device_label' => $data['device_label'] ?? $existing?->device_label,
            'app_version' => $data['app_version'] ?? $existing?->app_version,
            'environment' => $data['environment'] ?? $existing?->environment,
            'is_active' => true,
            'last_seen_at' => now(),
        ];

        if ($existing !== null) {
            // Same token → refresh in place (release metadata included) and
            // answer 200; a new token answers 201.
            $existing->fill($attributes)->save();

            return ApiResponse::data($this->present($existing), [], Response::HTTP_OK);
        }

        $device = new MobileDevice();
        $device->user_id = $user->id;
        $device->fill($attributes);
        $device->save();

        $this->enforceCap($user->id);

        return ApiResponse::created($this->present($device));
    }

    /**
     * DELETE /api/v1/me/devices/{device} — owner-only removal.
     */
    public function destroy(Request $request, $device): JsonResponse|Response
    {
        $model = MobileDevice::query()->find($device);

        if ($model === null) {
            return ApiResponse::error('not_found', 'Device not found.', [], 404);
        }

        if ((int) $model->user_id !== (int) $request->user()->id) {
            // Never leak the existence of another user's device beyond a 403.
            return ApiResponse::error('forbidden', 'You cannot manage this device.', [], 403);
        }

        $model->delete();

        return ApiResponse::noContent();
    }

    /**
     * Keep the per-user device registry capped: the oldest extras are
     * deactivated (never deleted, so a resurrected token still refreshes the
     * same row).
     */
    protected function enforceCap(int $userId): void
    {
        $cap = (int) config('mobile.devices.max_devices_per_user', 25);

        $ids = MobileDevice::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->pluck('id')
            ->all();

        $excess = array_slice($ids, $cap);

        if ($excess === []) {
            return;
        }

        MobileDevice::query()->whereIn('id', $excess)->each(function (MobileDevice $device) {
            $device->is_active = false;
            $device->save();
        });
    }

    /**
     * The wire shape of a device: identity and metadata only — never the
     * token, its hash or the encrypted ciphertext.
     *
     * @return array<string, mixed>
     */
    protected function present(MobileDevice $device): array
    {
        return [
            'id' => $device->id,
            'platform' => $device->platform,
            'provider' => $device->provider,
            'device_label' => $device->device_label,
            'app_version' => $device->app_version,
            'environment' => $device->environment,
            'is_active' => (bool) $device->is_active,
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
        ];
    }
}
