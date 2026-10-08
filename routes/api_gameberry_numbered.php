<?php

/*
|--------------------------------------------------------------------------
| Gameberry API — numbered simulation routes (GAP-10 A3, Option B)
|--------------------------------------------------------------------------
|
| API counterpart of routes/gameberry_numbered.php: `/v1/gameberry/core/...`,
| `/v1/gameberry/final{number}/...` and `/v1/gameberry/stats/...`. Same rules
| apply —
| registered only for the `local`/`testing` environment with
| GAMEBERRY_NUMBERED_SIMULATIONS=true, and always behind the
| `numbered.simulation` middleware so no mutating request can slip through
| in production.
|
*/

use Illuminate\Support\Facades\Route;

// AUDIT FIX-04/FIX-15: same hardening + envelope parity for the numbered
// (local/testing-only) Gameberry API families.
Route::middleware(['numbered.simulation', 'throttle:api', 'gameberry.envelope'])->group(function () {
    // Final7 — Feature 1001-1100 API — 15 API controllers
    Route::middleware(['auth:sanctum'])->prefix('v1/gameberry/final7')->name('api.v1.gameberry.final7.')->group(function () {
        for ($i = 1086; $i <= 1100; $i++) {
            $controller = "App\\Http\\Controllers\\Api\\V1\\Gameberry\\Final7\\Final{$i}ApiController";
            Route::get("/feature-{$i}", [$controller, 'index'])->name("feature_{$i}");
            Route::post("/feature-{$i}/play", [$controller, 'play'])->name("feature_{$i}.play");
            Route::get("/feature-{$i}/stats", [$controller, 'stats'])->name("feature_{$i}.stats");
        }
    });

    // Final8 — Feature 1101-1200 API — Part 17 — 15 API controllers
    Route::middleware(['auth:sanctum'])->prefix('v1/gameberry/final8')->name('api.v1.gameberry.final8.')->group(function () {
        for ($i = 1186; $i <= 1200; $i++) {
            $controller = "App\\Http\\Controllers\\Api\\V1\\Gameberry\\Final8\\Final{$i}ApiController";
            Route::get("/feature-{$i}", [$controller, 'index'])->name("feature_{$i}");
            Route::post("/feature-{$i}/play", [$controller, 'play'])->name("feature_{$i}.play");
            Route::get("/feature-{$i}/stats", [$controller, 'stats'])->name("feature_{$i}.stats");
        }
    });

    // ==== ADDED: Core parts 1-4 API controllers (191-200, 297-311, 357-361, 398-400) ====
    Route::middleware(['auth:sanctum'])->prefix('v1/gameberry/core')->name('api.v1.gameberry.core.')->group(function () {
        for ($i = 191; $i <= 200; $i++) {
            $controller = "App\\Http\\Controllers\\Api\\V1\\Gameberry\\Core\\Core{$i}ApiController";
            Route::get("/feature-{$i}", [$controller, 'index'])->name("feature_{$i}");
            Route::post("/feature-{$i}/play", [$controller, 'play'])->name("feature_{$i}.play");
            Route::get("/feature-{$i}/stats", [$controller, 'stats'])->name("feature_{$i}.stats");
        }
        for ($i = 297; $i <= 311; $i++) {
            $controller = "App\\Http\\Controllers\\Api\\V1\\Gameberry\\Core\\Core{$i}ApiController";
            Route::get("/feature-{$i}", [$controller, 'index'])->name("feature_{$i}");
            Route::post("/feature-{$i}/play", [$controller, 'play'])->name("feature_{$i}.play");
            Route::get("/feature-{$i}/stats", [$controller, 'stats'])->name("feature_{$i}.stats");
        }
        for ($i = 357; $i <= 361; $i++) {
            $controller = "App\\Http\\Controllers\\Api\\V1\\Gameberry\\Core\\Core{$i}ApiController";
            Route::get("/feature-{$i}", [$controller, 'index'])->name("feature_{$i}");
            Route::post("/feature-{$i}/play", [$controller, 'play'])->name("feature_{$i}.play");
            Route::get("/feature-{$i}/stats", [$controller, 'stats'])->name("feature_{$i}.stats");
        }
        for ($i = 398; $i <= 400; $i++) {
            $controller = "App\\Http\\Controllers\\Api\\V1\\Gameberry\\Core\\Core{$i}ApiController";
            Route::get("/feature-{$i}", [$controller, 'index'])->name("feature_{$i}");
            Route::post("/feature-{$i}/play", [$controller, 'play'])->name("feature_{$i}.play");
            Route::get("/feature-{$i}/stats", [$controller, 'stats'])->name("feature_{$i}.stats");
        }
    });

    // ==== ADDED: Stats 21-30 API ====
    Route::middleware(['auth:sanctum'])->prefix('v1/gameberry/stats')->name('api.v1.gameberry.stats.')->group(function () {
        for ($i = 21; $i <= 30; $i++) {
            $controller = "App\\Http\\Controllers\\Api\\V1\\Gameberry\\Stats\\Stat{$i}ApiController";
            Route::get("/stat-{$i}", [$controller, 'index'])->name('stat_'.$i);
            Route::get("/stat-{$i}/{userId}", [$controller, 'show'])->name('stat_'.$i.'.show');
            Route::get("/stat-{$i}/all", [$controller, 'stats'])->name('stat_'.$i.'.all');
        }
    });

    // Part 18 File 1201-1300 - Final9 API - 15 API controllers - Full Code No Skip
    Route::middleware(['auth:sanctum'])->prefix('v1/gameberry/final9')->name('api.v1.gameberry.final9.')->group(function () {
        for ($i = 1286; $i <= 1300; $i++) {
            $controller = "App\\Http\\Controllers\\Api\\V1\\Gameberry\\Final9\\Final9{$i}ApiController";
            Route::get("/feature-{$i}", [$controller, 'index'])->name("feature_{$i}");
            Route::post("/feature-{$i}/play", [$controller, 'play'])->name("feature_{$i}.play");
            Route::get("/feature-{$i}/stats", [$controller, 'stats'])->name("feature_{$i}.stats");
        }
    });

    // Part 19 File 1301-1400 - Final10 API - 15 API controllers - Full Code No Skip
    Route::middleware(['auth:sanctum'])->prefix('v1/gameberry/final10')->name('api.v1.gameberry.final10.')->group(function () {
        for ($i = 1386; $i <= 1400; $i++) {
            $controller = "App\\Http\\Controllers\\Api\\V1\\Gameberry\\Final10\\Final10{$i}ApiController";
            Route::get("/feature-{$i}", [$controller, 'index'])->name("feature_{$i}");
            Route::post("/feature-{$i}/play", [$controller, 'play'])->name("feature_{$i}.play");
            Route::get("/feature-{$i}/stats", [$controller, 'stats'])->name("feature_{$i}.stats");
        }
    });

    // Part 20 File 1401-1500 - Final11 API - 15 API controllers - Full Code No Skip
    Route::middleware(['auth:sanctum'])->prefix('v1/gameberry/final11')->name('api.v1.gameberry.final11.')->group(function () {
        for ($i = 1486; $i <= 1500; $i++) {
            $controller = "App\\Http\\Controllers\\Api\\V1\\Gameberry\\Final11\\Final11{$i}ApiController";
            Route::get("/feature-{$i}", [$controller, 'index'])->name("feature_{$i}");
            Route::post("/feature-{$i}/play", [$controller, 'play'])->name("feature_{$i}.play");
            Route::get("/feature-{$i}/stats", [$controller, 'stats'])->name("feature_{$i}.stats");
        }
    });

    // Part 21 File 1501-1600 - Final12 API - 15 API controllers - Full Code No Skip
    Route::middleware(['auth:sanctum'])->prefix('v1/gameberry/final12')->name('api.v1.gameberry.final12.')->group(function () {
        for ($i = 1586; $i <= 1600; $i++) {
            $controller = "App\\Http\\Controllers\\Api\\V1\\Gameberry\\Final12\\Final12{$i}ApiController";
            Route::get("/feature-{$i}", [$controller, 'index'])->name("feature_{$i}");
            Route::post("/feature-{$i}/play", [$controller, 'play'])->name("feature_{$i}.play");
            Route::get("/feature-{$i}/stats", [$controller, 'stats'])->name("feature_{$i}.stats");
        }
    });

});
