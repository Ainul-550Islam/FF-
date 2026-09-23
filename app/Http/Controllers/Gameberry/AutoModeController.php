<?php

namespace App\Http\Controllers\Gameberry;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\AutoModeService;
use Illuminate\Http\Request;

class AutoModeController extends Controller
{
    protected AutoModeService $autoModeService;

    public function __construct(AutoModeService $autoModeService)
    {
        $this->autoModeService = $autoModeService;
    }

    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $isAuto = $this->autoModeService->isInAutoMode($userId);
        $logs = $this->autoModeService->getAutoModeLogs($userId, 20);

        return view('gameberry.auto_mode.index', compact('isAuto', 'logs'));
    }

    public function enable(Request $request)
    {
        $request->validate(['reason' => 'in:disconnect,afk,manual', 'table_id' => 'nullable|exists:private_tables,id']);
        $userId = $request->user()->id;
        $log = $this->autoModeService->enableAutoMode($userId, $request->get('table_id'), $request->get('reason', 'manual'));

        return redirect()->back()->with('success', 'Auto mode ON - will auto-play on disconnect 🤖');
    }

    public function disable(Request $request)
    {
        $request->validate(['table_id' => 'nullable|exists:private_tables,id']);
        $userId = $request->user()->id;
        $log = $this->autoModeService->disableAutoMode($userId, $request->get('table_id'));

        return redirect()->back()->with('success', 'Auto mode OFF');
    }
}
