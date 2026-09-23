<?php

namespace App\Http\Controllers\Api\V1\Gameberry;

use App\Http\Controllers\Controller;
use App\Models\TitanBadge;
use App\Services\Gameberry\BadgeService;
use Illuminate\Http\Request;

class BadgeApiController extends Controller
{
    protected BadgeService $badgeService;

    public function __construct(BadgeService $badgeService)
    {
        $this->badgeService = $badgeService;
    }

    public function index(Request $request)
    {
        $userId = $request->user()->id;

        return response()->json(['success' => true, 'data' => ['badges' => $this->badgeService->getUserBadges($userId), 'stats' => $this->badgeService->getBadgeStats($userId)]]);
    }

    public function show(Request $request, int $badgeId)
    {
        $badge = TitanBadge::with('league')->find($badgeId);
        if (! $badge) {
            return response()->json(['success' => false, 'error' => 'Badge not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $badge]);
    }
}
