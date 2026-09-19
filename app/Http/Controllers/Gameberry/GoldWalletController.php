<?php
namespace App\Http\Controllers\Gameberry;
use App\Http\Controllers\Controller;
use App\Services\Gameberry\GoldEconomyService;
use App\Services\Gameberry\ReconciliationService;
use Illuminate\Http\Request;
class GoldWalletController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $goldService = app(GoldEconomyService::class);
        $reconcileService = app(ReconciliationService::class);
        $goldWallet = $goldService->getOrCreateWallet($userId);
        $stats = $goldService->getStats($userId);
        $reconcile = $reconcileService->reconcileGold($userId);
        $history = $goldService->getTransactionHistory($userId, 50);
        return view('gameberry.economy.gold_history', ['transactions' => $history, 'goldWallet' => $goldWallet, 'stats' => $stats, 'reconcile' => $reconcile]);
    }
    public function stats(Request $request)
    {
        $userId = $request->user()->id;
        $goldService = app(GoldEconomyService::class);
        $reconcileService = app(ReconciliationService::class);
        return response()->json(['success' => true, 'data' => ['stats' => $goldService->getStats($userId), 'reconcile' => $reconcileService->reconcileGold($userId), 'balance' => $goldService->getBalance($userId)]]);
    }
}
