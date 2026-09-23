<?php

namespace App\Http\Controllers\Gameberry;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\GemEconomyService;
use App\Services\Gameberry\GoldEconomyService;
use App\Services\Gameberry\ReconciliationService;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $goldService = app(GoldEconomyService::class);
        $gemService = app(GemEconomyService::class);
        $reconcileService = app(ReconciliationService::class);
        $goldWallet = $goldService->getOrCreateWallet($userId);
        $gemWallet = $gemService->getOrCreateWallet($userId);
        $goldReconcile = $reconcileService->reconcileGold($userId);
        $gemReconcile = $reconcileService->reconcileGems($userId);
        $allReconcile = $reconcileService->reconcileAll($userId);
        $goldHistory = $goldService->getTransactionHistory($userId, 20);
        $gemHistory = $gemService->getTransactionHistory($userId, 20);

        return view('gameberry.economy.wallets', compact('goldWallet', 'gemWallet', 'goldReconcile', 'gemReconcile', 'allReconcile', 'goldHistory', 'gemHistory'));
    }
}
