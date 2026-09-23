<?php

namespace App\Http\Controllers\Gameberry\Final2;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\Final2\Final576Service;
use Illuminate\Http\Request;

class Final576Controller extends Controller
{
    protected Final576Service $service;

    public function __construct(Final576Service $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $stats = $this->service->getStats($userId);

        return view('gameberry.final2.feature_576', compact('stats'));
    }

    public function play(Request $request)
    {
        $request->validate(['game_mode' => 'in:classic,master,quick,team_up', 'bet_amount' => 'integer|min:100|max:100000']);
        $userId = $request->user()->id;
        try {
            $result = $this->service->process($userId, $request->get('game_mode', 'classic'), $request->get('bet_amount', 100));

            return redirect()->back()->with('success', 'Feature 576 result: '.($result['is_win'] ? 'win' : 'loss').' - Gold at stake, Level 4 Bronze unlock, reconciliation must hold');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}
