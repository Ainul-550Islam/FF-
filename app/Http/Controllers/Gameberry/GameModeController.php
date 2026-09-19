<?php
namespace App\Http\Controllers\Gameberry;
use App\Http\Controllers\Controller;
use App\Services\Gameberry\GameModeService;
use Illuminate\Http\Request;
class GameModeController extends Controller
{
    protected GameModeService $modeService;
    public function __construct(GameModeService $modeService) { $this->modeService = $modeService; }
    public function index(Request $request)
    {
        $modes = $this->modeService->getModes();
        return view('gameberry.game_modes.index', compact('modes'));
    }
    public function show(Request $request, string $mode)
    {
        $modeData = $this->modeService->getMode($mode);
        if (!$modeData) abort(404);
        return view('gameberry.game_modes.show', compact('mode','modeData'));
    }
}
