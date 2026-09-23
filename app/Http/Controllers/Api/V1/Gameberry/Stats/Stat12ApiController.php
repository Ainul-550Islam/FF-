<?php

namespace App\Http\Controllers\Api\V1\Gameberry\Stats;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\Stats\Stat12Service;
use Illuminate\Http\Request;

class Stat12ApiController extends Controller
{
    protected Stat12Service $service;

    public function __construct(Stat12Service $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $userId = $request->user()->id;

        return response()->json(['success' => true, 'data' => $this->service->getStats($userId)]);
    }

    public function show(Request $request, int $userId)
    {
        return response()->json(['success' => true, 'data' => $this->service->getStats($userId)]);
    }
}
