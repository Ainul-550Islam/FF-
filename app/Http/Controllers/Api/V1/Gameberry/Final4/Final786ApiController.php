<?php

namespace App\Http\Controllers\Api\V1\Gameberry\Final4;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\Final4\Final786Service;
use Illuminate\Http\Request;

class Final786ApiController extends Controller
{
    protected Final786Service $service;

    public function __construct(Final786Service $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $userId = $request->user()->id;

        return response()->json(['success' => true, 'data' => $this->service->getAllStats($userId)]);
    }

    public function play(Request $request)
    {
        $request->validate(['game_mode' => 'in:classic,master,quick,team_up', 'bet_amount' => 'integer|min:100|max:100000']);
        $userId = $request->user()->id;
        try {
            $result = $this->service->execute($userId, $request->get('game_mode', 'classic'), $request->get('bet_amount', 100));

            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }
}
