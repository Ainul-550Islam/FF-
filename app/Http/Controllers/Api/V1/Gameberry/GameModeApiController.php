<?php

namespace App\Http\Controllers\Api\V1\Gameberry;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\GameModeService;

class GameModeApiController extends Controller
{
    protected GameModeService $modeService;

    public function __construct(GameModeService $modeService)
    {
        $this->modeService = $modeService;
    }

    public function index()
    {
        return response()->json(['success' => true, 'data' => $this->modeService->getModes()]);
    }

    public function show(string $mode)
    {
        $data = $this->modeService->getMode($mode);
        if (! $data) {
            return response()->json(['success' => false, 'error' => 'Mode not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $data]);
    }
}
