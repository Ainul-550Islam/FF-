<?php
namespace App\Http\Controllers\Gameberry\Stats;
use App\Http\Controllers\Controller;
use App\Services\Gameberry\Stats\Stat19Service;
use Illuminate\Http\Request;
class Stat19Controller extends Controller
{
    protected Stat19Service $service;
    public function __construct(Stat19Service $service) { $this->service = $service; }
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $stats = $this->service->getStats($userId);
        return view('gameberry.dashboard.stat_19', compact('stats'));
    }
    public function show(Request $request, int $userId)
    {
        $stats = $this->service->getStats($userId);
        return view('gameberry.dashboard.stat_19', compact('stats'));
    }
}
