<?php

namespace App\Http\Controllers\Api\V1\Gameberry;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\ReconciliationService;
use Illuminate\Http\Request;

class WalletApiController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $reconcileService = app(ReconciliationService::class);
        $result = $reconcileService->reconcileAll($userId);

        return response()->json(['success' => true, 'data' => $result]);
    }
}
