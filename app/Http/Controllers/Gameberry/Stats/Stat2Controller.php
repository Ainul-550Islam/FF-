<?php

namespace App\Http\Controllers\Gameberry\Stats;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\Stats\Stat2Service;
use Illuminate\Http\Request;

class Stat2Controller extends Controller
{
    protected Stat2Service $service;

    public function __construct(Stat2Service $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $stats = $this->service->getStats($userId);

        return view('gameberry.dashboard.stat_2', compact('stats'));
    }

    public function show(Request $request, int $userId)
    {
        $stats = $this->service->getStats($userId);

        return view('gameberry.dashboard.stat_2', compact('stats'));
    }
}
