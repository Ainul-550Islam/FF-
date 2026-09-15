<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PushPreferenceService;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 19 — per-category push notification preferences (owner-only).
 *
 * Governs the PUSH channel only; in-app and email delivery are unaffected.
 * The `security` category can never be disabled. The server is authoritative
 * for which categories exist and for the security always-on rule.
 */
class NotificationPreferenceController extends Controller
{
    public function __construct(
        protected PushPreferenceService $preferences,
    ) {}

    /**
     * GET /api/v1/me/notification-preferences
     */
    public function index(Request $request): JsonResponse
    {
        $pref = $this->preferences->forUser($request->user());

        return ApiResponse::data($pref->toArrayForApi());
    }

    /**
     * PATCH /api/v1/me/notification-preferences
     *
     * Accepts a partial set of `{category: bool}` flags. `security` is
     * accepted but ignored (always delivered). Unknown categories are 422.
     */
    public function update(Request $request): JsonResponse
    {
        $flags = $request->validate([
            'tournament' => 'sometimes|boolean',
            'match' => 'sometimes|boolean',
            'team' => 'sometimes|boolean',
            'payment' => 'sometimes|boolean',
            'payout' => 'sometimes|boolean',
            'dispute' => 'sometimes|boolean',
            'security' => 'sometimes|boolean',
            'support' => 'sometimes|boolean',
        ]);

        // Reject unknown categories explicitly (validate() silently drops
        // keys that are not in its rules).
        $known = ['tournament', 'match', 'team', 'payment', 'payout', 'dispute', 'security', 'support'];
        $unknown = array_values(array_diff(array_keys($request->all()), $known));

        if ($unknown !== []) {
            return ApiResponse::error('validation_error', 'Unknown notification category: '.implode(', ', $unknown), [], 422);
        }

        try {
            $pref = $this->preferences->update($request->user(), $flags);
        } catch (DomainException $e) {
            return ApiResponse::error('validation_error', $e->getMessage(), [], 422);
        }

        return ApiResponse::data($pref->toArrayForApi());
    }
}
