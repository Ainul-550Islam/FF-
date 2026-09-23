<?php

namespace App\Http\Controllers\Api\V1\Gameberry\Final6;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\Final6\Final988Service;
use Illuminate\Http\Request;

class Final988ApiController extends Controller
{
    protected Final988Service $service;

    public function __construct(Final988Service $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $userId = $request->user()->id;

        return response()->json(['success' => true, 'data' => $this->service->getFullStats($userId)]);
    }

    public function play(Request $request)
    {
        $request->validate(['game_mode' => 'in:classic,master,quick,team_up', 'bet_amount' => 'integer|min:100|max:100000']);
        $userId = $request->user()->id;
        try {
            $result = $this->service->play($userId, $request->get('game_mode', 'classic'), $request->get('bet_amount', 100));

            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }
}
