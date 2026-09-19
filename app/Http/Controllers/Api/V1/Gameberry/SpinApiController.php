<?php

namespace App\Http\Controllers\Api\V1\Gameberry;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\SpinService;
use Illuminate\Http\Request;

class SpinApiController extends Controller
{
    protected SpinService $spinService;

    public function __construct(SpinService $spinService)
    {
        $this->spinService = $spinService;
    }

    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $stats = $this->spinService->getSpinStats($userId);
        $history = $this->spinService->getSpinHistory($userId, 20);

        return response()->json(['success' => true, 'data' => ['stats' => $stats, 'history' => $history]]);
    }

    public function spin(Request $request)
    {
        $request->validate(['use_free' => 'boolean']);
        $userId = $request->user()->id;
        try {
            $spin = $this->spinService->spin($userId, $request->boolean('use_free', false));
            return response()->json(['success' => true, 'data' => $spin, 'message' => "Result: {$spin->result} +{$spin->gold_amount} gold +{$spin->gem_amount} gems"]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function stats(Request $request)
    {
        $userId = $request->user()->id;
        return response()->json(['success' => true, 'data' => $this->spinService->getSpinStats($userId)]);
    }

    public function history(Request $request)
    {
        $userId = $request->user()->id;
        $history = $this->spinService->getSpinHistory($userId, 50);
        return response()->json(['success' => true, 'data' => $history]);
    }
}
