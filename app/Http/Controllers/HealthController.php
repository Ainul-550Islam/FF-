<?php

namespace App\Http\Controllers;

use App\Services\HealthService;
use Illuminate\Http\JsonResponse;

/**
 * Phase 16 — public liveness/readiness endpoints.
 *
 * /health/live and /health are intentionally minimal (no internals, no
 * secrets). /health/ready exposes only per-check "ok" booleans; detailed
 * diagnostics are admin/CLI-only (ffarena:health, admin ops dashboard).
 */
class HealthController extends Controller
{
    public function __construct(protected HealthService $health) {}

    /**
     * GET /health — liveness + service identity (safe to expose).
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => config('app.name', 'ff-arena'),
        ]);
    }

    /**
     * GET /health/live — process liveness only.
     */
    public function live(): JsonResponse
    {
        return response()->json($this->health->live());
    }

    /**
     * GET /health/ready — readiness (200 ready / 503 not ready).
     */
    public function ready(): JsonResponse
    {
        $result = $this->health->ready();

        return response()->json($result, $result['status'] === 'ready' ? 200 : 503);
    }
}
