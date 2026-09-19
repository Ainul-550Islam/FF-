<?php

namespace App\Http\Controllers\Gameberry;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\GoldEconomyService;
use App\Services\Gameberry\GemEconomyService;
use App\Services\Gameberry\VideoAdService;
use App\Services\Gameberry\MagicChestService;
use Illuminate\Http\Request;

class EconomyController extends Controller
{
    protected GoldEconomyService $goldService;
    protected GemEconomyService $gemService;
    protected VideoAdService $videoAdService;
    protected MagicChestService $chestService;

    public function __construct(GoldEconomyService $goldService, GemEconomyService $gemService, VideoAdService $videoAdService, MagicChestService $chestService)
    {
        $this->goldService = $goldService;
        $this->gemService = $gemService;
        $this->videoAdService = $videoAdService;
        $this->chestService = $chestService;
    }

    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $goldWallet = $this->goldService->getOrCreateWallet($userId);
        $gemWallet = $this->gemService->getOrCreateWallet($userId);
        $goldStats = $this->goldService->getStats($userId);
        $gemStats = $this->gemService->getStats($userId);
        $videoStats = $this->videoAdService->getStats($userId);
        $availableChests = $this->chestService->getAvailableChests($userId);
        $goldReconcile = $this->goldService->reconcile($userId);
        $gemReconcile = $this->gemService->reconcile($userId);

        return view('gameberry.economy.index', compact('goldWallet', 'gemWallet', 'goldStats', 'gemStats', 'videoStats', 'availableChests', 'goldReconcile', 'gemReconcile'));
    }

    public function goldHistory(Request $request)
    {
        $userId = $request->user()->id;
        $transactions = $this->goldService->getTransactionHistory($userId, 100);
        return view('gameberry.economy.gold_history', compact('transactions'));
    }

    public function gemHistory(Request $request)
    {
        $userId = $request->user()->id;
        $transactions = $this->gemService->getTransactionHistory($userId, 100);
        return view('gameberry.economy.gem_history', compact('transactions'));
    }

    public function videoAds(Request $request)
    {
        $userId = $request->user()->id;
        $stats = $this->videoAdService->getStats($userId);
        $history = $this->videoAdService->getHistory($userId, 20);
        return view('gameberry.economy.video_ads', compact('stats', 'history'));
    }

    public function watchVideoAd(Request $request)
    {
        $request->validate([
            'provider' => 'in:admob,unity,facebook',
        ]);

        $userId = $request->user()->id;
        try {
            $reward = $this->videoAdService->watchAd($userId, $request->get('provider', 'admob'));
            return redirect()->back()->with('success', "Watched ad! +{$reward->gold_reward} gold, +{$reward->gem_reward} gems");
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function magicChests(Request $request)
    {
        $userId = $request->user()->id;
        $chests = $this->chestService->getUserChests($userId);
        $available = $this->chestService->getAvailableChests($userId);
        $canGet = $this->chestService->canGetChest($userId);
        $nextTime = $this->chestService->getNextChestTime($userId);

        return view('gameberry.economy.magic_chests', compact('chests', 'available', 'canGet', 'nextTime'));
    }

    public function openChest(Request $request, int $chestId)
    {
        $userId = $request->user()->id;
        try {
            $rewards = $this->chestService->openChest($userId, $chestId);
            return redirect()->back()->with('success', "Chest opened! +{$rewards['gold']} gold, +{$rewards['gems']} gems");
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function getChest(Request $request)
    {
        $request->validate([
            'type' => 'in:bronze,silver,gold,magic',
        ]);

        $userId = $request->user()->id;
        try {
            $chest = $this->chestService->createChest($userId, $request->get('type', 'bronze'));
            return redirect()->back()->with('success', "Got {$chest->type} chest!");
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}
