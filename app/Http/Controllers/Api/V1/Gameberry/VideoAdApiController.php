<?php

namespace App\Http\Controllers\Api\V1\Gameberry;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\VideoAdService;
use Illuminate\Http\Request;

class VideoAdApiController extends Controller
{
    protected VideoAdService $videoAdService;

    public function __construct(VideoAdService $videoAdService)
    {
        $this->videoAdService = $videoAdService;
    }

    public function stats(Request $request)
    {
        $userId = $request->user()->id;
        return response()->json(['success' => true, 'data' => $this->videoAdService->getStats($userId)]);
    }

    public function watch(Request $request)
    {
        $request->validate(['provider' => 'in:admob,unity,facebook']);
        $userId = $request->user()->id;
        try {
            $reward = $this->videoAdService->watchAd($userId, $request->get('provider', 'admob'));
            return response()->json(['success' => true, 'data' => $reward, 'message' => "+{$reward->gold_reward} gold, +{$reward->gem_reward} gems - Free gold from video ad"]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function history(Request $request)
    {
        $userId = $request->user()->id;
        $history = $this->videoAdService->getHistory($userId, 50);
        return response()->json(['success' => true, 'data' => $history]);
    }
}
