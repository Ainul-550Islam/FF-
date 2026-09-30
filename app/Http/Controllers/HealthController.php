<?php

namespace App\Http\Controllers;

use App\Services\HealthService;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __construct(
        protected HealthService $health
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => config('app.name', 'FF Arena'),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function live(): JsonResponse
    {
        return response()->json($this->health->live());
    }

    public function ready(): JsonResponse
    {
        $report = $this->health->ready();
        $status = ($report['status'] ?? '') === 'ready' ? 200 : 503;

        return response()->json($report, $status);
    }
}
