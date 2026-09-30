<?php

namespace App\Http\Controllers\Api\V1\Gameberry\Stats;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\Stats\Stat28Service;
use Illuminate\Http\Request;

class Stat28ApiController extends Controller
{
    protected Stat28Service $service;

    public function __construct(Stat28Service $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $userId = auth()->id();
        if (! $userId) {
            return response()->json(["success" => false, "error" => "Unauthenticated"], 401);
        }

        return response()->json(['success' => true, 'data' => $this->service->getStats($userId), 'stat' => 28]);
    }

    public function show(Request $request, int $userId)
    {
        return response()->json(['success' => true, 'data' => $this->service->getStats($userId), 'stat' => 28]);
    }

    public function stats(Request $request)
    {
        $userId = auth()->id();
        if (! $userId) {
            return response()->json(["success" => false, "error" => "Unauthenticated"], 401);
        }

        return response()->json(['success' => true, 'data' => $this->service->getAllStats($userId), 'stat' => 28]);
    }
}
