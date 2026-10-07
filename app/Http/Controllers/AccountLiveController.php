<?php

namespace App\Http\Controllers;

use App\Services\LiveEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountLiveController extends Controller
{
    /**
     * Account live event poll endpoint.
     */
    public function index(Request $request, LiveEventService $service): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['events' => [], 'cursor' => 0], 401);
        }

        $since = (int) $request->input('since', 0);
        $events = $service->sinceForUser($since, $user);
        $cursor = $events->isNotEmpty() ? $events->max('id') : $since;

        // `revision` is the cursor name used by every other live endpoint
        // (LiveController::poll, LiveEventService::snapshot) and by the API
        // clients; this endpoint only returned `cursor`, so the account feed
        // client never saw a revision. Both keys are returned: `revision` is
        // canonical, `cursor` is kept for existing callers.
        return response()->json([
            'events' => $events->values(),
            'revision' => $cursor,
            'cursor' => $cursor,
            'since' => $since,
            'count' => $events->count(),
        ]);
    }
}
