<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-category push notification preferences (Phase 19).
 *
 * Preferences are per user and per category, defaulting to "on". The
 * `security` category is always delivered — a user (or a client bug) can
 * never switch security notifications off. Unknown categories and
 * non-boolean values are rejected with the standard validation error.
 */
class NotificationPreferenceController extends Controller
{
    /**
     * GET /api/v1/me/notification-preferences
     */
    public function index(Request $request): JsonResponse
    {
        $preferences = $this->preferencesFor($request);

        return ApiResponse::data($preferences->toArrayForApi());
    }

    /**
     * PATCH /api/v1/me/notification-preferences
     */
    public function update(Request $request): JsonResponse
    {
        $input = $request->all();

        $errors = [];

        foreach ($input as $category => $value) {
            if (! in_array($category, NotificationPreference::ALL_CATEGORIES, true)) {
                $errors[$category][] = 'Unknown notification category.';

                continue;
            }

            if (! is_bool($value)) {
                $errors[$category][] = 'The value must be true or false.';
            }
        }

        if ($errors !== []) {
            return ApiResponse::error('validation_error', 'The given data was invalid.', $errors, 422);
        }

        $preferences = $this->preferencesFor($request);

        foreach ($input as $category => $value) {
            // Security is always on, whatever the client sends.
            if ($category === NotificationPreference::CATEGORY_SECURITY) {
                continue;
            }

            $preferences->{NotificationPreference::columnFor($category)} = (bool) $value;
        }

        $preferences->save();

        return ApiResponse::data($preferences->toArrayForApi());
    }

    /**
     * The caller's preference row, created with the all-on defaults on first
     * read so a brand-new account has an explicit, persisted document.
     */
    protected function preferencesFor(Request $request): NotificationPreference
    {
        $userId = $request->user()->id;

        $preferences = NotificationPreference::query()->where('user_id', $userId)->first();

        if ($preferences !== null) {
            return $preferences;
        }

        $preferences = new NotificationPreference();
        $preferences->user_id = $userId;

        foreach (NotificationPreference::ALL_CATEGORIES as $category) {
            $preferences->{NotificationPreference::columnFor($category)} = true;
        }

        $preferences->save();

        return $preferences;
    }
}
