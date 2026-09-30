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

        return response()->json([
            'events' => $events->values(),
            'cursor' => $cursor,
        ]);
    }
}
