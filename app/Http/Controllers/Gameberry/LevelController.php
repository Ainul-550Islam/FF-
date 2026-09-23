<?php

namespace App\Http\Controllers\Gameberry;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\LevelService;
use Illuminate\Http\Request;

class LevelController extends Controller
{
    protected LevelService $levelService;

    public function __construct(LevelService $levelService)
    {
        $this->levelService = $levelService;
    }

    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $level = $this->levelService->getOrCreateLevel($userId);
        $stats = $this->levelService->getLevelStats($userId);

        return view('gameberry.level.index', compact('level', 'stats'));
    }

    public function show(Request $request, int $userId)
    {
        $level = $this->levelService->getOrCreateLevel($userId);
        $stats = $this->levelService->getLevelStats($userId);

        return view('gameberry.level.show', compact('level', 'stats'));
    }
}
