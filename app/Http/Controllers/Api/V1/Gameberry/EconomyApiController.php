<?php

namespace App\Http\Controllers\Api\V1\Gameberry;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\GoldEconomyService;
use App\Services\Gameberry\GemEconomyService;
use App\Services\Gameberry\VideoAdService;
use App\Services\Gameberry\MagicChestService;
use App\Services\Gameberry\SpinService;
use Illuminate\Http\Request;

class EconomyApiController extends Controller
{
    protected GoldEconomyService $goldService;
    protected GemEconomyService $gemService;
    protected VideoAdService $videoAdService;
    protected MagicChestService $chestService;
    protected SpinService $spinService;

    public function __construct(GoldEconomyService $goldService, GemEconomyService $gemService, VideoAdService $videoAdService, MagicChestService $chestService, SpinService $spinService)
    {
        $this->goldService = $goldService;
        $this->gemService = $gemService;
        $this->videoAdService = $videoAdService;
        $this->chestService = $chestService;
        $this->spinService = $spinService;
    }

    public function index(Request $request)
    {
        $userId = $request->user()->id;
        return response()->json([
            'success' => true,
            'data' => [
                'gold' => $this->goldService->getStats($userId),
                'gems' => $this->gemService->getStats($userId),
                'video_ads' => $this->videoAdService->getStats($userId),
                'spin' => $this->spinService->getSpinStats($userId),
                'gold_reconcile' => $this->goldService->reconcile($userId),
                'gem_reconcile' => $this->gemService->reconcile($userId),
            ]
        ]);
    }

    public function goldBalance(Request $request)
    {
        $userId = $request->user()->id;
        return response()->json(['success' => true, 'balance' => $this->goldService->getBalance($userId), 'stats' => $this->goldService->getStats($userId)]);
    }

    public function gemBalance(Request $request)
    {
        $userId = $request->user()->id;
        return response()->json(['success' => true, 'balance' => $this->gemService->getBalance($userId), 'stats' => $this->gemService->getStats($userId)]);
    }

    public function goldHistory(Request $request)
    {
        $userId = $request->user()->id;
        $transactions = $this->goldService->getTransactionHistory($userId, 100);
        return response()->json(['success' => true, 'data' => $transactions]);
    }

    public function gemHistory(Request $request)
    {
        $userId = $request->user()->id;
        $transactions = $this->gemService->getTransactionHistory($userId, 100);
        return response()->json(['success' => true, 'data' => $transactions]);
    }

    public function watchAd(Request $request)
    {
        $request->validate(['provider' => 'in:admob,unity,facebook']);
        $userId = $request->user()->id;
        try {
            $reward = $this->videoAdService->watchAd($userId, $request->get('provider', 'admob'));
            return response()->json(['success' => true, 'data' => $reward, 'message' => "+{$reward->gold_reward} gold, +{$reward->gem_reward} gems"]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function videoStats(Request $request)
    {
        $userId = $request->user()->id;
        return response()->json(['success' => true, 'data' => $this->videoAdService->getStats($userId)]);
    }

    public function chests(Request $request)
    {
        $userId = $request->user()->id;
        $chests = $this->chestService->getUserChests($userId);
        $available = $this->chestService->getAvailableChests($userId);
        return response()->json(['success' => true, 'data' => ['all' => $chests, 'available' => $available, 'can_get' => $this->chestService->canGetChest($userId)]]);
    }

    public function openChest(Request $request, int $chestId)
    {
        $userId = $request->user()->id;
        try {
            $rewards = $this->chestService->openChest($userId, $chestId);
            return response()->json(['success' => true, 'data' => $rewards]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function createChest(Request $request)
    {
        $request->validate(['type' => 'required|in:bronze,silver,gold,magic']);
        $userId = $request->user()->id;
        try {
            $chest = $this->chestService->createChest($userId, $request->type);
            return response()->json(['success' => true, 'data' => $chest], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function spin(Request $request)
    {
        $request->validate(['use_free' => 'boolean']);
        $userId = $request->user()->id;
        try {
            $spin = $this->spinService->spin($userId, $request->boolean('use_free', false));
            return response()->json(['success' => true, 'data' => $spin]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function spinStats(Request $request)
    {
        $userId = $request->user()->id;
        return response()->json(['success' => true, 'data' => $this->spinService->getSpinStats($userId)]);
    }

    public function spinHistory(Request $request)
    {
        $userId = $request->user()->id;
        $history = $this->spinService->getSpinHistory($userId, 50);
        return response()->json(['success' => true, 'data' => $history]);
    }
}
