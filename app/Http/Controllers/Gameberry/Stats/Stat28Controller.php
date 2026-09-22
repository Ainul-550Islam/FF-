<?php
namespace App\Http\Controllers\Gameberry\Stats;
use App\Http\Controllers\Controller;
use App\Services\Gameberry\Stats\Stat28Service;
use Illuminate\Http\Request;
class Stat28Controller extends Controller
{
    protected Stat28Service $service;
    public function __construct(Stat28Service $service) { $this->service = $service; }
    public function index(Request $request)
    {
        $userId = $request->user() ? $request->user()->id : (int) $request->input('user_id', 1);
        $stats = $this->service->getStats($userId);
        return view('gameberry.dashboard.stat_28', compact('stats'));
    }
    public function show(Request $request, int $userId)
    {
        $stats = $this->service->getStats($userId);
        return view('gameberry.dashboard.stat_28', compact('stats', 'userId'));
    }
    public function calculate(Request $request)
    {
        $userId = $request->user() ? $request->user()->id : (int) $request->input('user_id', 1);
        $value = (int) $request->input('value', 100);
        return response()->json(['success' => true, 'stat' => 28, 'result' => $this->service->calculate($userId, $value)]);
    }
}
