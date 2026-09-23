<?php

namespace App\Http\Controllers\Api\V1\Gameberry;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\DiceCollectionService;
use App\Services\Gameberry\GemEconomyService;
use App\Services\Gameberry\GoldEconomyService;
use App\Services\Gameberry\LeagueService;
use App\Services\Gameberry\LevelService;
use App\Services\Gameberry\ReferralService;
use App\Services\Gameberry\SocialService;
use App\Services\Gameberry\WeeklyEventService;
use Illuminate\Http\Request;

class DashboardApiController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $goldService = app(GoldEconomyService::class);
        $gemService = app(GemEconomyService::class);
        $diceService = app(DiceCollectionService::class);
        $leagueService = app(LeagueService::class);
        $socialService = app(SocialService::class);
        $eventService = app(WeeklyEventService::class);
        $referralService = app(ReferralService::class);
        $levelService = app(LevelService::class);

        return response()->json(['success' => true, 'data' => [
            'gold' => $goldService->getStats($userId),
            'gems' => $gemService->getStats($userId),
            'gold_reconcile' => $goldService->reconcile($userId),
            'gem_reconcile' => $gemService->reconcile($userId),
            'dice' => $diceService->getUserCollection($userId),
            'league' => $leagueService->getUserLeague($userId)?->load('league'),
            'level' => $levelService->getLevelStats($userId),
            'social' => $socialService->getSocialStats($userId),
            'events' => ['active' => $eventService->getActiveEvents()->count(), 'upcoming' => $eventService->getUpcomingEvents()->count()],
            'referral' => $referralService->getReferralStats($userId),
        ]]);
    }
}
