<?php

namespace App\Http\Controllers\Gameberry\Final6;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\Final6\Final983Service;
use Illuminate\Http\Request;

class Final983Controller extends Controller
{
    protected Final983Service $service;

    public function __construct(Final983Service $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $stats = $this->service->getFullStats($userId);

        return view('gameberry.final6.feature_983', compact('stats'));
    }

    public function play(Request $request)
    {
        $request->validate(['game_mode' => 'in:classic,master,quick,team_up', 'bet_amount' => 'integer|min:100|max:100000']);
        $userId = $request->user()->id;
        try {
            $result = $this->service->play($userId, $request->get('game_mode', 'classic'), $request->get('bet_amount', 100));

            return redirect()->back()->with('success', 'Final6 983 result: '.($result['is_win'] ? 'win' : 'loss').' - Gold at stake, magic chest, Level 4 Bronze unlock, reconciliation must hold STOP if mismatch G1 - Full file content no shortening');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}
