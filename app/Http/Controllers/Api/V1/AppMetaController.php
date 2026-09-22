<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Server-driven app metadata (Phase 18).
 *
 * One public, cache-friendly document that the native client reads at boot:
 * release policy (min/latest version), honest push capability flags, deep-link
 * scheme, public URLs and platform defaults. No secret, credential or
 * internal host is ever exposed here.
 *
 * The legacy flat keys (`version`, `service`, `features`, `min_app_version`,
 * `maintenance`) are kept alongside the canonical `data.*` envelope so old
 * clients keep working.
 */
class AppMetaController extends Controller
{
    public function meta(Request $request): JsonResponse
    {
        $push = (array) config('mobile.push', []);
        $maintenanceActive = (bool) config('mobile.maintenance', false);

        $app = [
            'name' => config('app.name', 'FF Arena'),
            'api_version' => '1',
            'min_supported_app_version' => (string) config('mobile.min_supported_app_version', '1.0.0'),
            'latest_app_version' => (string) config('mobile.latest_app_version', '1.0.0'),
            'update_required' => (bool) config('mobile.update_required', false),
            'deep_link_scheme' => (string) config('mobile.deep_link_scheme', 'ffarena'),
        ];

        $urls = [
            'support' => $this->publicUrl('mobile.support_url', '/support'),
            'privacy' => $this->publicUrl('mobile.privacy_url', '/privacy'),
            'terms' => $this->publicUrl('mobile.terms_url', '/terms'),
            'release_notes' => $this->publicUrl('mobile.release_notes_url', '/release-notes'),
            'web_base' => $this->publicUrl('mobile.web_base_url', '/'),
            'store' => $this->publicUrl('mobile.store_url', '/download'),
        ];

        return ApiResponse::data([
            'app' => $app,
            'push' => [
                'fcm_enabled' => (bool) ($push['fcm_enabled'] ?? false),
                'apns_enabled' => (bool) ($push['apns_enabled'] ?? false),
            ],
            'urls' => $urls,
            'platform' => [
                'currency' => (string) config('account.default_currency', 'BDT'),
                'timezone' => (string) config('app.timezone', 'Asia/Dhaka'),
                'locale' => (string) config('app.locale', 'en'),
            ],
            'maintenance' => [
                'active' => $maintenanceActive,
                'message' => $maintenanceActive
                    ? (string) config('mobile.maintenance_message', 'FF Arena is under maintenance. Please try again shortly.')
                    : null,
            ],
        ], [
            // Legacy flat keys (pre-Phase-18 clients).
            'version' => '1.0.0',
            'service' => 'ffarena',
            'name' => $app['name'],
            'env' => config('app.env'),
            'urls' => $urls,
            'features' => [
                'payments' => array_values((array) config('payments.providers', ['bkash', 'nagad', 'rocket', 'manual'])),
                'auth' => ['email', 'google', 'phone_otp'],
                'tournaments' => true,
                'wallet' => true,
                'avatar' => true,
                'internet_check' => true,
                'push' => [
                    'fcm' => (bool) ($push['fcm_enabled'] ?? false),
                    'apns' => (bool) ($push['apns_enabled'] ?? false),
                ],
            ],
            'min_app_version' => $app['min_supported_app_version'],
            'maintenance' => $maintenanceActive,
        ]);
    }

    /**
     * Absolute, public URL for a configured value, falling back to the app's
     * own origin (never an internal host).
     */
    protected function publicUrl(string $configKey, string $fallbackPath): string
    {
        $configured = (string) config($configKey, '');

        if ($configured !== '') {
            return $configured;
        }

        return url($fallbackPath);
    }
}
