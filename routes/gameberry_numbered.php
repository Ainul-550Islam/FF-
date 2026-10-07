<?php

/*
|--------------------------------------------------------------------------
| Gameberry — numbered simulation routes (GAP-10 A3, Option B)
|--------------------------------------------------------------------------
|
| Every route in this file belongs to one of the template-generated
| "numbered" families (`core`, `final*`, `stats`). They are NOT product
| features: the controllers/services behind them are near-identical clones
| that differ only in numbers, several of them mutate the virtual
| gold/gem ledgers with a non-cryptographic PRNG outcome, and their JSON
| payloads carried the generator's prompt-scaffolding fields. Those fields have
| been stripped repo-wide (GAP-10 A3; see tools/prune_numbered_simulations.py),
| and the unpredictable `random_int()` replaced the seeded PRNG.
|
| They are therefore isolated here and registered ONLY when all of the
| following hold (see bootstrap/app.php):
|
|   1. `features.gameberry_numbered_simulations` is true
|      (env GAMEBERRY_NUMBERED_SIMULATIONS, default false), and
|   2. the application runs in the `local` or `testing` environment.
|
| In every other environment these route files are never required, so the
| endpoints do not exist at all. As defence in depth the whole group also
| carries the `numbered.simulation` middleware
| (App\Http\Middleware\EnsureNumberedSimulationSafe), which fails closed
| with a 403 for any mutating request outside local/testing even if the
| registration gate is ever loosened by mistake.
|
| Deleting these families is the planned follow-up release; see
| tools/prune_numbered_simulations.sh and FILE_AUDIT.md.
|
*/

use App\Http\Controllers\Gameberry\Core\CoreFeatureViewController;
use App\Http\Controllers\Gameberry\Final10\Final10ViewController;
use App\Http\Controllers\Gameberry\Final11\Final11ViewController;
use App\Http\Controllers\Gameberry\Final12\Final12ViewController;
use App\Http\Controllers\Gameberry\Final7\Final7ViewController;
use App\Http\Controllers\Gameberry\Final8\Final8ViewController;
use App\Http\Controllers\Gameberry\Final9\Final9ViewController;
use Illuminate\Support\Facades\Route;

Route::middleware(['numbered.simulation'])->group(function () {
    // Final7 — Feature 1001-1050 Production 1000+ Files — Full Code No Skip — 50 views + 15 controllers
    Route::middleware(['auth', 'verified'])->prefix('gameberry/final7')->name('gameberry.final7.')->group(function () {
        for ($i = 1001; $i <= 1050; $i++) {
            $controller = 'App\\Http\\Controllers\\Gameberry\\Final7\\Final'.(1071 + ($i - 1001) % 15).'Controller';
            // Use first 15 controllers cycling for 50 views
            if ($i <= 1015) {
                $controller = 'App\\Http\\Controllers\\Gameberry\\Final7\\Final'.(1071 + $i - 1001).'Controller';
            } else {
                $controller = 'App\\Http\\Controllers\\Gameberry\\Final7\\Final1071Controller';
            }
            Route::get("/feature-{$i}", [$controller, 'index'])->name("feature_{$i}");
            Route::post("/feature-{$i}/play", [$controller, 'play'])->name("feature_{$i}.play");
        }
        // Explicit controllers 1071-1085
        for ($i = 1071; $i <= 1085; $i++) {
            $controller = "App\\Http\\Controllers\\Gameberry\\Final7\\Final{$i}Controller";
            Route::get("/controller-{$i}", [$controller, 'index'])->name("controller_{$i}");
            Route::post("/controller-{$i}/play", [$controller, 'play'])->name("controller_{$i}.play");
            Route::get("/controller-{$i}/stats", [$controller, 'stats'])->name("controller_{$i}.stats");
        }
    });

    // Final8 — Feature 1101-1150 Production 1100+ Files — Part 17 — 50 views +15 controllers
    Route::middleware(['auth', 'verified'])->prefix('gameberry/final8')->name('gameberry.final8.')->group(function () {
        for ($i = 1101; $i <= 1150; $i++) {
            $controller = 'App\\Http\\Controllers\\Gameberry\\Final8\\Final'.(1171 + ($i - 1101) % 15).'Controller';
            if ($i <= 1115) {
                $controller = 'App\\Http\\Controllers\\Gameberry\\Final8\\Final'.(1171 + $i - 1101).'Controller';
            } else {
                $controller = 'App\\Http\\Controllers\\Gameberry\\Final8\\Final1171Controller';
            }
            Route::get("/feature-{$i}", [$controller, 'index'])->name("feature_{$i}");
            Route::post("/feature-{$i}/play", [$controller, 'play'])->name("feature_{$i}.play");
        }
        for ($i = 1171; $i <= 1185; $i++) {
            $controller = "App\\Http\\Controllers\\Gameberry\\Final8\\Final{$i}Controller";
            Route::get("/controller-{$i}", [$controller, 'index'])->name("controller_{$i}");
            Route::post("/controller-{$i}/play", [$controller, 'play'])->name("controller_{$i}.play");
            Route::get("/controller-{$i}/stats", [$controller, 'stats'])->name("controller_{$i}.stats");
        }
    });

    // ==== ADDED: Core parts 1-4 (files 121-400) - report-proven missing, one file per number ====
    Route::middleware(['auth', 'verified'])->prefix('gameberry/core')->name('gameberry.core.')->group(function () {
        Route::get('/view/{feature}', [CoreFeatureViewController::class, 'show'])
            ->whereNumber('feature')->name('view');
        Route::get('/coverage', [CoreFeatureViewController::class, 'coverage'])->name('coverage');
        for ($i = 121; $i <= 160; $i++) {
            $controller = 'App\\Http\\Controllers\\Gameberry\\Core\\Core'.(181 + ($i - 121) % 10).'Controller';
            Route::get("/feature-{$i}", [$controller, 'index'])->name("feature_{$i}");
            Route::post("/feature-{$i}/play", [$controller, 'play'])->name("feature_{$i}.play");
        }
        for ($i = 181; $i <= 190; $i++) {
            $controller = "App\\Http\\Controllers\\Gameberry\\Core\\Core{$i}Controller";
            Route::get("/controller-{$i}", [$controller, 'index'])->name("controller_{$i}");
            Route::post("/controller-{$i}/play", [$controller, 'play'])->name("controller_{$i}.play");
            Route::get("/controller-{$i}/stats", [$controller, 'stats'])->name("controller_{$i}.stats");
        }
        for ($i = 201; $i <= 250; $i++) {
            $controller = 'App\\Http\\Controllers\\Gameberry\\Core\\Core'.(282 + ($i - 201) % 15).'Controller';
            Route::get("/feature-{$i}", [$controller, 'index'])->name("feature_{$i}");
            Route::post("/feature-{$i}/play", [$controller, 'play'])->name("feature_{$i}.play");
        }
        for ($i = 282; $i <= 296; $i++) {
            $controller = "App\\Http\\Controllers\\Gameberry\\Core\\Core{$i}Controller";
            Route::get("/controller-{$i}", [$controller, 'index'])->name("controller_{$i}");
            Route::post("/controller-{$i}/play", [$controller, 'play'])->name("controller_{$i}.play");
            Route::get("/controller-{$i}/stats", [$controller, 'stats'])->name("controller_{$i}.stats");
        }
        for ($i = 312; $i <= 331; $i++) {
            $controller = 'App\\Http\\Controllers\\Gameberry\\Core\\Core'.(352 + ($i - 312) % 5).'Controller';
            Route::get("/feature-{$i}", [$controller, 'index'])->name("feature_{$i}");
            Route::post("/feature-{$i}/play", [$controller, 'play'])->name("feature_{$i}.play");
        }
        for ($i = 352; $i <= 356; $i++) {
            $controller = "App\\Http\\Controllers\\Gameberry\\Core\\Core{$i}Controller";
            Route::get("/controller-{$i}", [$controller, 'index'])->name("controller_{$i}");
            Route::post("/controller-{$i}/play", [$controller, 'play'])->name("controller_{$i}.play");
            Route::get("/controller-{$i}/stats", [$controller, 'stats'])->name("controller_{$i}.stats");
        }
        for ($i = 362; $i <= 381; $i++) {
            $controller = 'App\\Http\\Controllers\\Gameberry\\Core\\Core'.(395 + ($i - 362) % 3).'Controller';
            Route::get("/feature-{$i}", [$controller, 'index'])->name("feature_{$i}");
            Route::post("/feature-{$i}/play", [$controller, 'play'])->name("feature_{$i}.play");
        }
        for ($i = 395; $i <= 397; $i++) {
            $controller = "App\\Http\\Controllers\\Gameberry\\Core\\Core{$i}Controller";
            Route::get("/controller-{$i}", [$controller, 'index'])->name("controller_{$i}");
            Route::post("/controller-{$i}/play", [$controller, 'play'])->name("controller_{$i}.play");
            Route::get("/controller-{$i}/stats", [$controller, 'stats'])->name("controller_{$i}.stats");
        }
    });

    // ==== ADDED: Final7 (1016-1050) + Final8 (1116-1150) feature views - every added view reachable ====
    Route::middleware(['auth', 'verified'])->prefix('gameberry/final7')->name('gameberry.final7.')->group(function () {
        Route::get('/view/{feature}', [Final7ViewController::class, 'show'])
            ->whereNumber('feature')->name('view');
    });
    Route::middleware(['auth', 'verified'])->prefix('gameberry/final8')->name('gameberry.final8.')->group(function () {
        Route::get('/view/{feature}', [Final8ViewController::class, 'show'])
            ->whereNumber('feature')->name('view');
    });

    // ==== ADDED: Stats controllers 21-30 (services + tests + dashboard views already existed) ====
    Route::middleware(['auth', 'verified'])->prefix('gameberry/stats')->name('gameberry.stats.')->group(function () {
        for ($i = 21; $i <= 30; $i++) {
            $controller = "App\\Http\\Controllers\\Gameberry\\Stats\\Stat{$i}Controller";
            Route::get("/stat-{$i}", [$controller, 'index'])->name('stat_'.$i);
            Route::get("/stat-{$i}/{userId}", [$controller, 'show'])->name('stat_'.$i.'.show');
            Route::post("/stat-{$i}/calculate", [$controller, 'calculate'])->name('stat_'.$i.'.calculate');
        }
    });

    // Part 18 File 1201-1300 - Final9 - 50 views + 15 controllers + 20 services + 15 api - Full Code No Skip - 115 files
    Route::middleware(['auth', 'verified'])->prefix('gameberry/final9')->name('gameberry.final9.')->group(function () {
        Route::get('/view/{feature}', [Final9ViewController::class, 'show'])
            ->whereNumber('feature')->name('view');
        Route::get('/coverage', [Final9ViewController::class, 'coverage'])->name('coverage');
        for ($i = 1201; $i <= 1250; $i++) {
            $controller = 'App\\Http\\Controllers\\Gameberry\\Final9\\Final9'.(1271 + ($i - 1201) % 15).'Controller';
            // Use first 15 controllers cycling for 50 views
            if ($i <= 1215) {
                $controller = 'App\\Http\\Controllers\\Gameberry\\Final9\\Final9'.(1271 + $i - 1201).'Controller';
            } else {
                $controller = 'App\\Http\\Controllers\\Gameberry\\Final9\\Final91271Controller';
            }
            Route::get("/feature-{$i}", [$controller, 'index'])->name("feature_{$i}");
            Route::post("/feature-{$i}/play", [$controller, 'play'])->name("feature_{$i}.play");
        }
        for ($i = 1271; $i <= 1285; $i++) {
            $controller = "App\\Http\\Controllers\\Gameberry\\Final9\\Final9{$i}Controller";
            Route::get("/controller-{$i}", [$controller, 'index'])->name("controller_{$i}");
            Route::post("/controller-{$i}/play", [$controller, 'play'])->name("controller_{$i}.play");
            Route::get("/controller-{$i}/stats", [$controller, 'stats'])->name("controller_{$i}.stats");
        }
    });

    // Part 19 File 1301-1400 - Final10 - 50 views + 15 controllers + 20 services + 15 api - Full Code No Skip - 115 files
    Route::middleware(['auth', 'verified'])->prefix('gameberry/final10')->name('gameberry.final10.')->group(function () {
        Route::get('/view/{feature}', [Final10ViewController::class, 'show'])
            ->whereNumber('feature')->name('view');
        Route::get('/coverage', [Final10ViewController::class, 'coverage'])->name('coverage');
        for ($i = 1301; $i <= 1350; $i++) {
            $controller = 'App\\Http\\Controllers\\Gameberry\\Final10\\Final10'.(1371 + ($i - 1301) % 15).'Controller';
            // Use first 15 controllers cycling for 50 views
            if ($i <= 1315) {
                $controller = 'App\\Http\\Controllers\\Gameberry\\Final10\\Final10'.(1371 + $i - 1301).'Controller';
            } else {
                $controller = 'App\\Http\\Controllers\\Gameberry\\Final10\\Final101371Controller';
            }
            Route::get("/feature-{$i}", [$controller, 'index'])->name("feature_{$i}");
            Route::post("/feature-{$i}/play", [$controller, 'play'])->name("feature_{$i}.play");
        }
        for ($i = 1371; $i <= 1385; $i++) {
            $controller = "App\\Http\\Controllers\\Gameberry\\Final10\\Final10{$i}Controller";
            Route::get("/controller-{$i}", [$controller, 'index'])->name("controller_{$i}");
            Route::post("/controller-{$i}/play", [$controller, 'play'])->name("controller_{$i}.play");
            Route::get("/controller-{$i}/stats", [$controller, 'stats'])->name("controller_{$i}.stats");
        }
    });

    // Part 20 File 1401-1500 - Final11 - 50 views + 15 controllers + 20 services + 15 api - Full Code No Skip - 115 files
    Route::middleware(['auth', 'verified'])->prefix('gameberry/final11')->name('gameberry.final11.')->group(function () {
        Route::get('/view/{feature}', [Final11ViewController::class, 'show'])
            ->whereNumber('feature')->name('view');
        Route::get('/coverage', [Final11ViewController::class, 'coverage'])->name('coverage');
        for ($i = 1401; $i <= 1450; $i++) {
            $controller = 'App\\Http\\Controllers\\Gameberry\\Final11\\Final11'.(1471 + ($i - 1401) % 15).'Controller';
            // Use first 15 controllers cycling for 50 views
            if ($i <= 1415) {
                $controller = 'App\\Http\\Controllers\\Gameberry\\Final11\\Final11'.(1471 + $i - 1401).'Controller';
            } else {
                $controller = 'App\\Http\\Controllers\\Gameberry\\Final11\\Final111471Controller';
            }
            Route::get("/feature-{$i}", [$controller, 'index'])->name("feature_{$i}");
            Route::post("/feature-{$i}/play", [$controller, 'play'])->name("feature_{$i}.play");
        }
        for ($i = 1471; $i <= 1485; $i++) {
            $controller = "App\\Http\\Controllers\\Gameberry\\Final11\\Final11{$i}Controller";
            Route::get("/controller-{$i}", [$controller, 'index'])->name("controller_{$i}");
            Route::post("/controller-{$i}/play", [$controller, 'play'])->name("controller_{$i}.play");
            Route::get("/controller-{$i}/stats", [$controller, 'stats'])->name("controller_{$i}.stats");
        }
    });

    // Part 21 File 1501-1600 - Final12 - 50 views + 15 controllers + 20 services + 15 api - Full Code No Skip - 115 files
    Route::middleware(['auth', 'verified'])->prefix('gameberry/final12')->name('gameberry.final12.')->group(function () {
        Route::get('/view/{feature}', [Final12ViewController::class, 'show'])
            ->whereNumber('feature')->name('view');
        Route::get('/coverage', [Final12ViewController::class, 'coverage'])->name('coverage');
        for ($i = 1501; $i <= 1550; $i++) {
            $controller = 'App\\Http\\Controllers\\Gameberry\\Final12\\Final12'.(1571 + ($i - 1501) % 15).'Controller';
            // Use first 15 controllers cycling for 50 views
            if ($i <= 1515) {
                $controller = 'App\\Http\\Controllers\\Gameberry\\Final12\\Final12'.(1571 + $i - 1501).'Controller';
            } else {
                $controller = 'App\\Http\\Controllers\\Gameberry\\Final12\\Final121571Controller';
            }
            Route::get("/feature-{$i}", [$controller, 'index'])->name("feature_{$i}");
            Route::post("/feature-{$i}/play", [$controller, 'play'])->name("feature_{$i}.play");
        }
        for ($i = 1571; $i <= 1585; $i++) {
            $controller = "App\\Http\\Controllers\\Gameberry\\Final12\\Final12{$i}Controller";
            Route::get("/controller-{$i}", [$controller, 'index'])->name("controller_{$i}");
            Route::post("/controller-{$i}/play", [$controller, 'play'])->name("controller_{$i}.play");
            Route::get("/controller-{$i}/stats", [$controller, 'stats'])->name("controller_{$i}.stats");
        }
    });

});
