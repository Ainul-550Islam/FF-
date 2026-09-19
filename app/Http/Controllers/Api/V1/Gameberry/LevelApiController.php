<?php
namespace App\Http\Controllers\Api\V1\Gameberry;
use App\Http\Controllers\Controller;
use App\Services\Gameberry\LevelService;
use Illuminate\Http\Request;
class LevelApiController extends Controller
{
    protected LevelService $levelService;
    public function __construct(LevelService $levelService) { $this->levelService = $levelService; }
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        return response()->json(['success' => true, 'data' => $this->levelService->getLevelStats($userId)]);
    }
    public function show(Request $request, int $userId)
    {
        return response()->json(['success' => true, 'data' => $this->levelService->getLevelStats($userId)]);
    }
}
