<?php
namespace App\Http\Controllers\Api\V1\Gameberry;
use App\Http\Controllers\Controller;
use App\Services\Gameberry\ChallengeService;
use Illuminate\Http\Request;
class ChallengeApiController extends Controller
{
    protected ChallengeService $challengeService;
    public function __construct(ChallengeService $challengeService) { $this->challengeService = $challengeService; }
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $pending = $this->challengeService->getPendingForUser($userId);
        $sent = \App\Models\Challenge::with(['challenged'])->where('challenger_id', $userId)->orderByDesc('created_at')->get();
        $received = \App\Models\Challenge::with(['challenger'])->where('challenged_id', $userId)->orderByDesc('created_at')->get();
        return response()->json(['success' => true, 'data' => ['pending' => $pending, 'sent' => $sent, 'received' => $received]]);
    }
    public function create(Request $request)
    {
        $request->validate(['challenged_id' => 'required|exists:users,id', 'bet_amount' => 'integer|min:100|max:100000', 'type' => 'in:buddy_challenge,private_table']);
        $userId = $request->user()->id;
        try {
            $challenge = $this->challengeService->createChallenge($userId, $request->challenged_id, $request->get('type','buddy_challenge'), $request->get('bet_amount',100));
            return response()->json(['success' => true, 'data' => $challenge], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }
    public function accept(Request $request, int $challengeId)
    {
        $userId = $request->user()->id;
        try {
            $challenge = $this->challengeService->acceptChallenge($challengeId, $userId);
            return response()->json(['success' => true, 'data' => $challenge]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }
    public function deny(Request $request, int $challengeId)
    {
        $userId = $request->user()->id;
        try {
            $challenge = $this->challengeService->denyChallenge($challengeId, $userId);
            return response()->json(['success' => true, 'data' => $challenge]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }
}
