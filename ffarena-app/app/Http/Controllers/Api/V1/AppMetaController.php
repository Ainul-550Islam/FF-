<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Phase 18 — server-driven app metadata.
 *
 * Anonymous endpoint the mobile client reads at startup for a compatibility
 * check, deep-link scheme and platform facts. Nothing here is secret.
 */
class AppMetaController extends Controller
{
    /**
     * GET /api/v1/app/meta
     */
    public function meta(): JsonResponse
    {
        return ApiResponse::data([
            'app' => [
                'name' => config('app.name', 'FF Arena'),
                'api_version' => '1',
                'min_supported_app_version' => (string) config('mobile.min_supported_app_version', '1.0.0'),
                'latest_app_version' => (string) config('mobile.latest_app_version', '1.0.0'),
                'update_required' => (bool) config('mobile.update_required', false),
                'deep_link_scheme' => (string) config('mobile.deep_link_scheme', 'ffarena'),
            ],
            'maintenance' => [
                'active' => (bool) config('mobile.maintenance', false),
                'message' => (string) config('mobile.maintenance_message', ''),
            ],
            'push' => [
                'fcm_enabled' => (bool) config('mobile.push.fcm_enabled', false),
                'apns_enabled' => (bool) config('mobile.push.apns_enabled', false),
            ],
            'urls' => [
                'support' => (string) config('mobile.support_url', ''),
                'privacy' => (string) config('mobile.privacy_url', ''),
                'terms' => (string) config('mobile.terms_url', ''),
                'release_notes' => (string) config('mobile.release_notes_url', ''),
                'web_base' => (string) config('mobile.web_base_url', ''),
                'store' => (string) config('mobile.store_url', ''),
            ],
            'platform' => [
                'currency' => 'BDT',
                'timezone' => config('app.timezone', 'UTC'),
                'locale' => config('app.locale', 'en'),
            ],
        ]);
    }
}
