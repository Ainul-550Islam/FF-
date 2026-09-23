<?php

namespace App\Http\Controllers\Api\V1\Gameberry;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\GoldEconomyService;
use App\Services\Gameberry\ReconciliationService;
use Illuminate\Http\Request;

class GoldWalletApiController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $goldService = app(GoldEconomyService::class);
        $reconcileService = app(ReconciliationService::class);

        return response()->json(['success' => true, 'data' => [
            'wallet' => $goldService->getOrCreateWallet($userId),
            'stats' => $goldService->getStats($userId),
            'reconcile' => $reconcileService->reconcileGold($userId),
            'history' => $goldService->getTransactionHistory($userId, 50),
        ]]);
    }

    public function balance(Request $request)
    {
        $userId = $request->user()->id;
        $goldService = app(GoldEconomyService::class);

        return response()->json(['success' => true, 'balance' => $goldService->getBalance($userId)]);
    }
}
