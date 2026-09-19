<?php
namespace App\Http\Controllers\Gameberry\Final4;
use App\Http\Controllers\Controller;
use App\Services\Gameberry\Final4\Final781Service;
use Illuminate\Http\Request;
class Final781Controller extends Controller
{
    protected Final781Service $service;
    public function __construct(Final781Service $service) { $this->service = $service; }
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $stats = $this->service->getAllStats($userId);
        return view('gameberry.final4.feature_781', compact('stats'));
    }
    public function play(Request $request)
    {
        $request->validate(['game_mode' => 'in:classic,master,quick,team_up', 'bet_amount' => 'integer|min:100|max:100000']);
        $userId = $request->user()->id;
        try {
            $result = $this->service->execute($userId, $request->get('game_mode','classic'), $request->get('bet_amount',100));
            return redirect()->back()->with('success', "Final4 781 result: ".($result['is_win'] ? 'win' : 'loss')." - Gold at stake, magic chest, Level 4 Bronze unlock, reconciliation must hold STOP if mismatch G1");
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}
