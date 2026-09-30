<?php

namespace App\Http\Controllers\Gameberry\Stats;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\Stats\Stat26Service;
use Illuminate\Http\Request;

class Stat26Controller extends Controller
{
    protected Stat26Service $service;

    public function __construct(Stat26Service $service)
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

        return view('gameberry.dashboard.stat_26', compact('stats'));
    }

    public function show(Request $request, int $userId)
    {
        $stats = $this->service->getStats($userId);

        return view('gameberry.dashboard.stat_26', compact('stats', 'userId'));
    }

    public function calculate(Request $request)
    {
        $userId = auth()->id();
        if (! $userId) {
            abort(401);
        }
        $value = (int) $request->input('value', 100);

        return response()->json(['success' => true, 'stat' => 26, 'result' => $this->service->calculate($userId, $value)]);
    }
}
