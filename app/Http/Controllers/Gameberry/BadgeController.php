<?php

namespace App\Http\Controllers\Gameberry;

use App\Http\Controllers\Controller;
use App\Models\TitanBadge;
use App\Services\Gameberry\BadgeService;
use Illuminate\Http\Request;

class BadgeController extends Controller
{
    protected BadgeService $badgeService;

    public function __construct(BadgeService $badgeService)
    {
        $this->badgeService = $badgeService;
    }

    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $badges = $this->badgeService->getUserBadges($userId);
        $stats = $this->badgeService->getBadgeStats($userId);

        return view('gameberry.league.badges', compact('badges', 'stats'));
    }

    public function show(Request $request, int $badgeId)
    {
        $badge = TitanBadge::with('league')->findOrFail($badgeId);

        return view('gameberry.league.badge_show', compact('badge'));
    }
}
