<?php
namespace App\Http\Controllers\Gameberry\Stats;
use App\Http\Controllers\Controller;
use App\Services\Gameberry\Stats\Stat15Service;
use Illuminate\Http\Request;
class Stat15Controller extends Controller
{
    protected Stat15Service $service;
    public function __construct(Stat15Service $service) { $this->service = $service; }
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $stats = $this->service->getStats($userId);
        return view('gameberry.dashboard.stat_15', compact('stats'));
    }
    public function show(Request $request, int $userId)
    {
        $stats = $this->service->getStats($userId);
        return view('gameberry.dashboard.stat_15', compact('stats'));
    }
}
