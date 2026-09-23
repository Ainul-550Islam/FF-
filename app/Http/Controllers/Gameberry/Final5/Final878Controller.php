<?php

namespace App\Http\Controllers\Gameberry\Final5;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\Final5\Final878Service;
use Illuminate\Http\Request;

class Final878Controller extends Controller
{
    protected Final878Service $service;

    public function __construct(Final878Service $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $stats = $this->service->getFullStats($userId);

        return view('gameberry.final5.feature_878', compact('stats'));
    }

    public function play(Request $request)
    {
        $request->validate(['game_mode' => 'in:classic,master,quick,team_up', 'bet_amount' => 'integer|min:100|max:100000']);
        $userId = $request->user()->id;
        try {
            $result = $this->service->play($userId, $request->get('game_mode', 'classic'), $request->get('bet_amount', 100));

            return redirect()->back()->with('success', 'Final5 878 result: '.($result['is_win'] ? 'win' : 'loss').' - Gold at stake, Level 4 Bronze unlock, reconciliation must hold STOP if mismatch G1');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}
