<?php
namespace App\Http\Controllers\Gameberry;
use App\Http\Controllers\Controller;
use App\Services\Gameberry\ChallengeService;
use Illuminate\Http\Request;
class ChallengeController extends Controller
{
    protected ChallengeService $challengeService;
    public function __construct(ChallengeService $challengeService) { $this->challengeService = $challengeService; }
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $sent = \App\Models\Challenge::with(['challenged'])->where('challenger_id', $userId)->orderByDesc('created_at')->get();
        $received = \App\Models\Challenge::with(['challenger'])->where('challenged_id', $userId)->orderByDesc('created_at')->get();
        $pending = $this->challengeService->getPendingForUser($userId);
        return view('gameberry.social.challenges', compact('sent','received','pending'));
    }
    public function create(Request $request)
    {
        $request->validate(['challenged_id' => 'required|exists:users,id', 'bet_amount' => 'integer|min:100|max:100000', 'type' => 'in:buddy_challenge,private_table']);
        $userId = $request->user()->id;
        try {
            $challenge = $this->challengeService->createChallenge($userId, $request->challenged_id, $request->get('type','buddy_challenge'), $request->get('bet_amount',100));
            return redirect()->back()->with('success','Challenge sent! Challenge button 🎯');
        } catch (\Exception $e) {
            return redirect()->back()->with('error',$e->getMessage());
        }
    }
    public function accept(Request $request, int $challengeId)
    {
        $userId = $request->user()->id;
        try {
            $challenge = $this->challengeService->acceptChallenge($challengeId, $userId);
            return redirect()->back()->with('success','Challenge accepted!');
        } catch (\Exception $e) {
            return redirect()->back()->with('error',$e->getMessage());
        }
    }
    public function deny(Request $request, int $challengeId)
    {
        $userId = $request->user()->id;
        try {
            $challenge = $this->challengeService->denyChallenge($challengeId, $userId);
            return redirect()->back()->with('success','Challenge denied');
        } catch (\Exception $e) {
            return redirect()->back()->with('error',$e->getMessage());
        }
    }
}
