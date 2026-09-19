<?php

namespace App\Http\Controllers\Api\V1\Gameberry;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\MagicChestService;
use Illuminate\Http\Request;

class MagicChestApiController extends Controller
{
    protected MagicChestService $chestService;

    public function __construct(MagicChestService $chestService)
    {
        $this->chestService = $chestService;
    }

    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $chests = $this->chestService->getUserChests($userId);
        $available = $this->chestService->getAvailableChests($userId);

        return response()->json([
            'success' => true,
            'data' => [
                'all' => $chests,
                'available' => $available,
                'can_get' => $this->chestService->canGetChest($userId),
                'next_time' => $this->chestService->getNextChestTime($userId),
            ]
        ]);
    }

    public function available(Request $request)
    {
        $userId = $request->user()->id;
        $available = $this->chestService->getAvailableChests($userId);
        return response()->json(['success' => true, 'data' => $available]);
    }

    public function create(Request $request)
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

    public function open(Request $request, int $chestId)
    {
        $userId = $request->user()->id;
        try {
            $rewards = $this->chestService->openChest($userId, $chestId);
            return response()->json(['success' => true, 'data' => $rewards]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }
}
