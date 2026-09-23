<?php

namespace App\Http\Controllers\Api\V1\Gameberry\Stats;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\Stats\Stat22Service;
use Illuminate\Http\Request;

class Stat22ApiController extends Controller
{
    protected Stat22Service $service;

    public function __construct(Stat22Service $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $userId = $request->user() ? $request->user()->id : (int) $request->input('user_id', 1);

        return response()->json(['success' => true, 'data' => $this->service->getStats($userId), 'stat' => 22]);
    }

    public function show(Request $request, int $userId)
    {
        return response()->json(['success' => true, 'data' => $this->service->getStats($userId), 'stat' => 22]);
    }

    public function stats(Request $request)
    {
        $userId = $request->user() ? $request->user()->id : (int) $request->input('user_id', 1);

        return response()->json(['success' => true, 'data' => $this->service->getAllStats($userId), 'stat' => 22]);
    }
}
