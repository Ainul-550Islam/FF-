<?php

namespace App\Http\Controllers\Api\V1\Gameberry;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\AutoModeService;
use Illuminate\Http\Request;

class AutoModeApiController extends Controller
{
    protected AutoModeService $autoModeService;

    public function __construct(AutoModeService $autoModeService)
    {
        $this->autoModeService = $autoModeService;
    }

    public function index(Request $request)
    {
        $userId = $request->user()->id;

        return response()->json(['success' => true, 'data' => ['is_auto' => $this->autoModeService->isInAutoMode($userId), 'logs' => $this->autoModeService->getAutoModeLogs($userId, 20)]]);
    }

    public function enable(Request $request)
    {
        $request->validate(['reason' => 'in:disconnect,afk,manual', 'table_id' => 'nullable|exists:private_tables,id']);
        $userId = $request->user()->id;
        $log = $this->autoModeService->enableAutoMode($userId, $request->get('table_id'), $request->get('reason', 'manual'));

        return response()->json(['success' => true, 'data' => $log, 'message' => 'Auto mode ON 🤖']);
    }

    public function disable(Request $request)
    {
        $request->validate(['table_id' => 'nullable|exists:private_tables,id']);
        $userId = $request->user()->id;
        $log = $this->autoModeService->disableAutoMode($userId, $request->get('table_id'));

        return response()->json(['success' => true, 'data' => $log, 'message' => 'Auto mode OFF']);
    }
}
