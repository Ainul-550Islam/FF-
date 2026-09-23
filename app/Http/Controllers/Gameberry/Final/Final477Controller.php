<?php

namespace App\Http\Controllers\Gameberry\Final;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\Final\Final477Service;
use Illuminate\Http\Request;

class Final477Controller extends Controller
{
    protected Final477Service $service;

    public function __construct(Final477Service $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $stats = $this->service->getComprehensiveStats($userId);

        return view('gameberry.final.feature_477', compact('stats'));
    }

    public function play(Request $request)
    {
        $request->validate(['game_mode' => 'in:classic,master,quick,team_up', 'bet_amount' => 'integer|min:100|max:100000']);
        $userId = $request->user()->id;
        try {
            $result = $this->service->processFullGameFlow($userId, $request->get('game_mode', 'classic'), $request->get('bet_amount', 100));

            return redirect()->back()->with('success', "Game {477} result: {$result['result']} - Gold at stake, magic chest, Level 4 Bronze unlock, reconciliation must hold");
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}
