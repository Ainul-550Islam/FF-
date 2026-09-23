<?php

namespace App\Http\Controllers\Api\V1\Gameberry\Stats;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\Stats\Stat10Service;
use Illuminate\Http\Request;

class Stat10ApiController extends Controller
{
    protected Stat10Service $service;

    public function __construct(Stat10Service $service)
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
