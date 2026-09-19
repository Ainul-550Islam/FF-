<?php
namespace App\Http\Controllers\Gameberry;
use App\Http\Controllers\Controller;
use App\Services\Gameberry\GoldEconomyService;
use App\Services\Gameberry\GemEconomyService;
use App\Services\Gameberry\DiceCollectionService;
use App\Services\Gameberry\LeagueService;
use App\Services\Gameberry\SocialService;
use App\Services\Gameberry\WeeklyEventService;
use App\Services\Gameberry\ReferralService;
use App\Services\Gameberry\LevelService;
use Illuminate\Http\Request;
class DashboardController extends Controller
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
        $goldWallet = $goldService->getOrCreateWallet($userId);
        $gemWallet = $gemService->getOrCreateWallet($userId);
        $goldBalance = $goldWallet->gold_balance;
        $gemBalance = $gemWallet->gem_balance;
        $goldReconcile = $goldService->reconcile($userId);
        $gemReconcile = $gemService->reconcile($userId);
        $diceCollection = $diceService->getUserCollection($userId);
        $diceStats = ['owned' => $diceCollection['owned_types'], 'total' => $diceCollection['total_dice_types'], 'percent' => $diceCollection['completion_percent']];
        $userLeague = $leagueService->getUserLeague($userId);
        $userLevel = $levelService->getOrCreateLevel($userId);
        $privateTablesCount = \App\Models\PrivateTable::where('host_id', $userId)->where('status', '!=', 'expired')->count();
        $buddiesCount = \App\Models\GameBuddy::where('user_id', $userId)->where('status', 'accepted')->count();
        $activeEventsCount = $eventService->getActiveEvents()->count();
        $upcomingEventsCount = $eventService->getUpcomingEvents()->count();
        $referralCode = $referralService->getReferralCode($userId);
        return view('gameberry.dashboard.index', compact('goldBalance','gemBalance','goldReconcile','gemReconcile','diceStats','userLeague','userLevel','privateTablesCount','buddiesCount','activeEventsCount','upcomingEventsCount','referralCode','goldWallet','gemWallet'));
    }
}
