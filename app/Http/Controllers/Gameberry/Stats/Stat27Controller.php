<?php

namespace App\Http\Controllers\Gameberry\Stats;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\Stats\Stat27Service;
use Illuminate\Http\Request;

class Stat27Controller extends Controller
{
    protected Stat27Service $service;

    public function __construct(Stat27Service $service)
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

        return view('gameberry.dashboard.stat_27', compact('stats'));
    }

    public function show(Request $request, int $userId)
    {
        $stats = $this->service->getStats($userId);

        return view('gameberry.dashboard.stat_27', compact('stats', 'userId'));
    }

    public function calculate(Request $request)
    {
        $userId = auth()->id();
        if (! $userId) {
            abort(401);
        }
        $value = (int) $request->input('value', 100);

        return response()->json(['success' => true, 'stat' => 27, 'result' => $this->service->calculate($userId, $value)]);
    }
}
