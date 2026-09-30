<?php

namespace App\Http\Controllers\Gameberry\Stats;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\Stats\Stat30Service;
use Illuminate\Http\Request;

class Stat30Controller extends Controller
{
    protected Stat30Service $service;

    public function __construct(Stat30Service $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $userId = auth()->id();
        if (! $userId) {
            abort(401);
        }
        $stats = $this->service->getStats($userId);

        return view('gameberry.dashboard.stat_30', compact('stats'));
    }

    public function show(Request $request, int $userId)
    {
        $stats = $this->service->getStats($userId);

        return view('gameberry.dashboard.stat_30', compact('stats', 'userId'));
    }

    public function calculate(Request $request)
    {
        $userId = auth()->id();
        if (! $userId) {
            abort(401);
        }
        $value = (int) $request->input('value', 100);

        return response()->json(['success' => true, 'stat' => 30, 'result' => $this->service->calculate($userId, $value)]);
    }
}
