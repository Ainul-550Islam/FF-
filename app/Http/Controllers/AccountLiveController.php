<?php

namespace App\Http\Controllers;

use App\Services\LiveEventService;
use Illuminate\Http\Request;

/**
 * Account-level realtime feed (Phase 14).
 *
 * JSON polling endpoint that returns the authenticated user's own targeted
 * live events (payment status, session revocation, verification status)
 * newer than a cursor. Only the target user may read them; payloads carry no
 * sensitive data.
 */
class AccountLiveController extends Controller
{
    public function __construct(
        protected LiveEventService $live,
    ) {
    }

    public function index(Request $request)
    {
        $since = (int) $request->query('since', 0);
        $user = $request->user();

        $events = $this->live->sinceForUser($since, $user, 50);

        return response()->json([
            'revision' => $this->live->latestCursor(),
            'events' => $events->map(fn ($event) => [
                'id' => $event->id,
                'type' => $event->type,
                'payload' => $event->payload,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }
}
