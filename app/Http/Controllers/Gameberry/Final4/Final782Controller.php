<?php
namespace App\Http\Controllers\Gameberry\Final4;
use App\Http\Controllers\Controller;
use App\Services\Gameberry\Final4\Final782Service;
use Illuminate\Http\Request;
class Final782Controller extends Controller
{
    protected Final782Service $service;
    public function __construct(Final782Service $service) { $this->service = $service; }
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $stats = $this->service->getAllStats($userId);
        return view('gameberry.final4.feature_782', compact('stats'));
    }
    public function play(Request $request)
    {
        $request->validate(['game_mode' => 'in:classic,master,quick,team_up', 'bet_amount' => 'integer|min:100|max:100000']);
        $userId = $request->user()->id;
        try {
            $result = $this->service->execute($userId, $request->get('game_mode','classic'), $request->get('bet_amount',100));
            return redirect()->back()->with('success', "Final4 782 result: ".($result['is_win'] ? 'win' : 'loss')." - Gold at stake, magic chest, Level 4 Bronze unlock, reconciliation must hold STOP if mismatch G1");
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}
