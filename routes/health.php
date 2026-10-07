<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\MetricsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Phase 16 — health, liveness and readiness
|--------------------------------------------------------------------------
|
| Loaded without the web/api middleware groups so probes never touch the
| session and stay available during maintenance mode. Responses are minimal
| and never leak secrets or infrastructure details.
|
*/

Route::middleware('throttle:health')->group(function () {
    Route::get('/health', [HealthController::class, 'index'])->name('health.index');
    Route::get('/health/live', [HealthController::class, 'live'])->name('health.live');
    Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');

    /*
    |----------------------------------------------------------------------
    | GAP-10 C — Prometheus scrape endpoint
    |----------------------------------------------------------------------
    |
    | Registered here so it is reachable without the session/cookie stack
    | (a scraper has no session) and during maintenance mode. It is
    | fail-closed: without FEATURE_PROMETHEUS=true *and* a configured
    | METRICS_SCRAPE_TOKEN the controller answers 404, and a missing or wrong
    | bearer token answers 401.
    |
    */
    Route::get('/metrics', MetricsController::class)->name('metrics.scrape');
});
