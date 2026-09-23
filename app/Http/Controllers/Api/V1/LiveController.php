<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\LiveEventResource;
use App\Services\LiveEventService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 15 — the caller's own realtime cursor feed (account-targeted events
 * only; tournament events are served under /tournaments/{id}/live).
 */
class LiveController extends Controller
{
    public function __construct(
        protected LiveEventService $live,
    ) {}

    /**
     * GET /api/v1/me/live?since=N
     */
    public function me(Request $request): JsonResponse
    {
        $since = (int) $request->query('since', 0);
        $limit = min(100, max(1, (int) $request->query('limit', 50)));

        $events = $this->live->sinceForUser($since, $request->user(), $limit);

        return ApiResponse::data([
            'events' => LiveEventResource::collection($events),
            'latest_cursor' => $this->live->latestCursor(),
        ]);
    }
}
