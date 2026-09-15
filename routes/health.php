<?php

use App\Http\Controllers\HealthController;
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
});
