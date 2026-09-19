<?php
namespace App\Http\Controllers\Gameberry\Stats;
use App\Http\Controllers\Controller;
use App\Services\Gameberry\Stats\Stat5Service;
use Illuminate\Http\Request;
class Stat5Controller extends Controller
{
    protected Stat5Service $service;
    public function __construct(Stat5Service $service) { $this->service = $service; }
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $stats = $this->service->getStats($userId);
        return view('gameberry.dashboard.stat_5', compact('stats'));
    }
    public function show(Request $request, int $userId)
    {
        $stats = $this->service->getStats($userId);
        return view('gameberry.dashboard.stat_5', compact('stats'));
    }
}
