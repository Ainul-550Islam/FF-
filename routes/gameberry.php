<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Gameberry\DiceController;
use App\Http\Controllers\Gameberry\LeagueController;
use App\Http\Controllers\Gameberry\PrivateTableController;
use App\Http\Controllers\Gameberry\ChatController;
use App\Http\Controllers\Gameberry\EconomyController;
use App\Http\Controllers\Gameberry\SocialController;
use App\Http\Controllers\Gameberry\SpinController;
use App\Http\Controllers\Gameberry\MagicChestController;
use App\Http\Controllers\Gameberry\WeeklyEventController;
use App\Http\Controllers\Gameberry\ReferralController;
use App\Http\Controllers\Gameberry\DashboardController;
use App\Http\Controllers\Gameberry\LevelController;
use App\Http\Controllers\Gameberry\GameModeController;
use App\Http\Controllers\Gameberry\AutoModeController;
use App\Http\Controllers\Gameberry\GoldWalletController;

Route::middleware(['auth', 'verified'])->prefix('gameberry')->name('gameberry.')->group(function () {
    // Dice Collection - 250+ dice, 52 max, Facebook-only exchange, Lucky dice gem reward
    Route::prefix('dice')->name('dice.')->group(function () {
        Route::get('/', [DiceController::class, 'index'])->name('index');
        Route::get('/collection', [DiceController::class, 'collection'])->name('collection');
        Route::get('/lucky', [DiceController::class, 'luckyDice'])->name('lucky');
        Route::get('/exchanges', [DiceController::class, 'exchanges'])->name('exchanges');
        Route::get('/{diceId}', [DiceController::class, 'show'])->name('show');
        Route::post('/{diceId}/equip', [DiceController::class, 'equip'])->name('equip');
        Route::post('/{diceId}/favorite', [DiceController::class, 'favorite'])->name('favorite');
        Route::post('/exchange', [DiceController::class, 'exchange'])->name('exchange');
        Route::post('/exchanges/{exchangeId}/accept', [DiceController::class, 'acceptExchange'])->name('exchange.accept');
        Route::post('/exchanges/{exchangeId}/deny', [DiceController::class, 'denyExchange'])->name('exchange.deny');
        Route::post('/lucky/{luckyDiceId}/roll', [DiceController::class, 'rollLuckyDice'])->name('lucky.roll');
    });

    // League System - 6-step Bronze Silver Gold Platinum Diamond Titan, Top 20% promotion Top 40 demotion Titan badges Level 4 unlock
    Route::prefix('league')->name('league.')->group(function () {
        Route::get('/', [LeagueController::class, 'index'])->name('index');
        Route::get('/history', [LeagueController::class, 'history'])->name('history');
        Route::get('/badges', [LeagueController::class, 'badges'])->name('badges');
        Route::get('/{slug}', [LeagueController::class, 'show'])->name('show');
        Route::get('/{slug}/leaderboard', [LeagueController::class, 'leaderboard'])->name('leaderboard');
    });

    // Private Tables - Code/Link sharing, Challenge button, Team-up mode, Classic/Master/Quick, Gold at stake, Auto mode on disconnect
    Route::prefix('private-tables')->name('private_tables.')->group(function () {
        Route::get('/', [PrivateTableController::class, 'index'])->name('index');
        Route::get('/create', [PrivateTableController::class, 'create'])->name('create');
        Route::post('/', [PrivateTableController::class, 'store'])->name('store');
        Route::get('/{code}', [PrivateTableController::class, 'show'])->name('show');
        Route::get('/{code}/share', [PrivateTableController::class, 'share'])->name('share');
        Route::post('/{code}/join', [PrivateTableController::class, 'join'])->name('join');
        Route::post('/{code}/leave', [PrivateTableController::class, 'leave'])->name('leave');
        Route::post('/{code}/ready', [PrivateTableController::class, 'ready'])->name('ready');
        Route::post('/{code}/start', [PrivateTableController::class, 'start'])->name('start');
        Route::post('/{code}/auto-mode', [PrivateTableController::class, 'autoMode'])->name('auto_mode');
    });

    // Chat & Emojis
    Route::prefix('chat/{code}')->name('chat.')->group(function () {
        Route::post('/send', [ChatController::class, 'send'])->name('send');
        Route::post('/emoji', [ChatController::class, 'emoji'])->name('emoji');
        Route::post('/quick', [ChatController::class, 'quick'])->name('quick');
        Route::get('/messages', [ChatController::class, 'messages'])->name('messages');
    });

    // Economy - Gold at stake, Magic chest, Video ads free gold, Gems, Lucky dice gem reward, Spin2win, Gold wallets gem wallets transactions reconciliation
    Route::prefix('economy')->name('economy.')->group(function () {
        Route::get('/', [EconomyController::class, 'index'])->name('index');
        Route::get('/gold-history', [EconomyController::class, 'goldHistory'])->name('gold_history');
        Route::get('/gem-history', [EconomyController::class, 'gemHistory'])->name('gem_history');
        Route::get('/video-ads', [EconomyController::class, 'videoAds'])->name('video_ads');
        Route::post('/video-ads/watch', [EconomyController::class, 'watchVideoAd'])->name('watch_ad');
        Route::get('/magic-chests', [EconomyController::class, 'magicChests'])->name('magic_chests');
        Route::post('/magic-chests/{chestId}/open', [EconomyController::class, 'openChest'])->name('open_chest');
        Route::post('/magic-chests/get', [EconomyController::class, 'getChest'])->name('get_chest');
    });

    // Social - Game Buddies max 25, Hide online status, Notify friends online, Challenge button, Auto mode, Team-up
    Route::prefix('social')->name('social.')->group(function () {
        Route::get('/', [SocialController::class, 'index'])->name('index');
        Route::post('/buddies', [SocialController::class, 'addBuddy'])->name('add_buddy');
        Route::post('/buddies/{buddyId}/accept', [SocialController::class, 'acceptBuddy'])->name('accept_buddy');
        Route::post('/buddies/{buddyId}/remove', [SocialController::class, 'removeBuddy'])->name('remove_buddy');
        Route::post('/online-status', [SocialController::class, 'onlineStatus'])->name('online_status');
        Route::post('/hide-status', [SocialController::class, 'hideOnlineStatus'])->name('hide_status');
        Route::post('/notify-friends', [SocialController::class, 'notifyFriends'])->name('notify_friends');
        Route::post('/auto-mode', [SocialController::class, 'autoMode'])->name('auto_mode');
        Route::post('/challenge', [SocialController::class, 'challenge'])->name('challenge');
        Route::get('/challenges', [SocialController::class, 'challenges'])->name('challenges');
        Route::post('/challenges/{challengeId}/accept', [SocialController::class, 'acceptChallenge'])->name('challenge.accept');
        Route::post('/challenges/{challengeId}/deny', [SocialController::class, 'denyChallenge'])->name('challenge.deny');
    });

    // Spin2Win
    Route::prefix('spin')->name('spin.')->group(function () {
        Route::get('/', [SpinController::class, 'index'])->name('index');
        Route::post('/spin', [SpinController::class, 'spin'])->name('spin');
        Route::get('/history', [SpinController::class, 'history'])->name('history');
    });

    // Magic Chests
    Route::prefix('chests')->name('chests.')->group(function () {
        Route::get('/', [MagicChestController::class, 'index'])->name('index');
        Route::post('/{chestId}/open', [MagicChestController::class, 'open'])->name('open');
        Route::post('/create', [MagicChestController::class, 'create'])->name('create');
    });

    // Weekly Special Events
    Route::prefix('events')->name('events.')->group(function () {
        Route::get('/', [WeeklyEventController::class, 'index'])->name('index');
        Route::get('/{eventId}', [WeeklyEventController::class, 'show'])->name('show');
        Route::post('/{eventId}/join', [WeeklyEventController::class, 'join'])->name('join');
        Route::post('/{eventId}/claim', [WeeklyEventController::class, 'claim'])->name('claim');
        Route::get('/{eventId}/leaderboard', [WeeklyEventController::class, 'leaderboard'])->name('leaderboard');
    });

    // Referral BGI20 ₹25 bonus + Scratch cards
    Route::prefix('referral')->name('referral.')->group(function () {
        Route::get('/', [ReferralController::class, 'index'])->name('index');
        Route::post('/generate', [ReferralController::class, 'generateCode'])->name('generate');
        Route::post('/apply', [ReferralController::class, 'applyCode'])->name('apply');
        Route::get('/scratch-cards', [ReferralController::class, 'scratchCards'])->name('scratch_cards');
        Route::post('/scratch-cards/{cardId}/scratch', [ReferralController::class, 'scratch'])->name('scratch');
    });

    // Dashboard - LudoStar style overview: gold/gem wallets, dice, league, level, buddies, events, referral
    // (resolves route('gameberry.dashboard.index') used by resources/views/gameberry/layout.blade.php)
    Route::prefix('dashboard')->name('dashboard.')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('index');
    });

    // Level system - Level 4 Bronze unlock, Level 12 Titan unlock
    Route::prefix('level')->name('level.')->group(function () {
        Route::get('/', [LevelController::class, 'index'])->name('index');
        Route::get('/{userId}', [LevelController::class, 'show'])->whereNumber('userId')->name('show');
    });

    // Game modes - classic / master / quick / team_up
    Route::prefix('game-modes')->name('game_modes.')->group(function () {
        Route::get('/', [GameModeController::class, 'index'])->name('index');
        Route::get('/{mode}', [GameModeController::class, 'show'])->name('show');
    });

    // Auto mode - auto-play on disconnect
    // (resolves route('gameberry.auto_mode.enable') / route('gameberry.auto_mode.disable')
    //  posted by resources/views/gameberry/auto_mode/index.blade.php)
    Route::prefix('auto-mode')->name('auto_mode.')->group(function () {
        Route::get('/', [AutoModeController::class, 'index'])->name('index');
        Route::post('/enable', [AutoModeController::class, 'enable'])->name('enable');
        Route::post('/disable', [AutoModeController::class, 'disable'])->name('disable');
    });

    // Gold wallet ledger + reconciliation (GoldWalletController; the economy group keeps its own names)
    Route::prefix('gold-wallet')->name('gold_wallet.')->group(function () {
        Route::get('/', [GoldWalletController::class, 'index'])->name('index');
        Route::get('/stats', [GoldWalletController::class, 'stats'])->name('stats');
    });
});

// Final7 — Feature 1001-1050 Production 1000+ Files — Full Code No Skip — 50 views + 15 controllers
Route::middleware(['auth', 'verified'])->prefix('gameberry/final7')->name('gameberry.final7.')->group(function () {
    for ($i = 1001; $i <= 1050; $i++) {
        $controller = "App\\Http\\Controllers\\Gameberry\\Final7\\Final" . (1071 + ($i - 1001) % 15) . "Controller";
        // Use first 15 controllers cycling for 50 views
        if ($i <= 1015) {
            $controller = "App\\Http\\Controllers\\Gameberry\\Final7\\Final" . (1071 + $i - 1001) . "Controller";
        } else {
            $controller = "App\\Http\\Controllers\\Gameberry\\Final7\\Final1071Controller";
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
        $controller = "App\\Http\\Controllers\\Gameberry\\Final8\\Final" . (1171 + ($i - 1101) % 15) . "Controller";
        if ($i <= 1115) {
            $controller = "App\\Http\\Controllers\\Gameberry\\Final8\\Final" . (1171 + $i - 1101) . "Controller";
        } else {
            $controller = "App\\Http\\Controllers\\Gameberry\\Final8\\Final1171Controller";
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
    Route::get('/view/{feature}', [\App\Http\Controllers\Gameberry\Core\CoreFeatureViewController::class, 'show'])
        ->whereNumber('feature')->name('view');
    Route::get('/coverage', [\App\Http\Controllers\Gameberry\Core\CoreFeatureViewController::class, 'coverage'])->name('coverage');
    for ($i = 121; $i <= 160; $i++) {
        $controller = "App\\Http\\Controllers\\Gameberry\\Core\\Core" . (181 + ($i - 121) % 10) . "Controller";
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
        $controller = "App\\Http\\Controllers\\Gameberry\\Core\\Core" . (282 + ($i - 201) % 15) . "Controller";
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
        $controller = "App\\Http\\Controllers\\Gameberry\\Core\\Core" . (352 + ($i - 312) % 5) . "Controller";
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
        $controller = "App\\Http\\Controllers\\Gameberry\\Core\\Core" . (395 + ($i - 362) % 3) . "Controller";
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
    Route::get('/view/{feature}', [\App\Http\Controllers\Gameberry\Final7\Final7ViewController::class, 'show'])
        ->whereNumber('feature')->name('view');
});
Route::middleware(['auth', 'verified'])->prefix('gameberry/final8')->name('gameberry.final8.')->group(function () {
    Route::get('/view/{feature}', [\App\Http\Controllers\Gameberry\Final8\Final8ViewController::class, 'show'])
        ->whereNumber('feature')->name('view');
});

// ==== ADDED: Stats controllers 21-30 (services + tests + dashboard views already existed) ====
Route::middleware(['auth', 'verified'])->prefix('gameberry/stats')->name('gameberry.stats.')->group(function () {
    for ($i = 21; $i <= 30; $i++) {
        $controller = "App\\Http\\Controllers\\Gameberry\\Stats\\Stat{$i}Controller";
        Route::get("/stat-{$i}", [$controller, 'index'])->name('stat_' . $i);
        Route::get("/stat-{$i}/{userId}", [$controller, 'show'])->name('stat_' . $i . '.show');
        Route::post("/stat-{$i}/calculate", [$controller, 'calculate'])->name('stat_' . $i . '.calculate');
    }
});

// Part 18 File 1201-1300 - Final9 - 50 views + 15 controllers + 20 services + 15 api - Full Code No Skip - 115 files
Route::middleware(['auth', 'verified'])->prefix('gameberry/final9')->name('gameberry.final9.')->group(function () {
    Route::get('/view/{feature}', [\App\Http\Controllers\Gameberry\Final9\Final9ViewController::class, 'show'])
        ->whereNumber('feature')->name('view');
    Route::get('/coverage', [\App\Http\Controllers\Gameberry\Final9\Final9ViewController::class, 'coverage'])->name('coverage');
    for ($i = 1201; $i <= 1250; $i++) {
        $controller = "App\\Http\\Controllers\\Gameberry\\Final9\\Final9" . (1271 + ($i - 1201) % 15) . "Controller";
        // Use first 15 controllers cycling for 50 views
        if ($i <= 1215) {
            $controller = "App\\Http\\Controllers\\Gameberry\\Final9\\Final9" . (1271 + $i - 1201) . "Controller";
        } else {
            $controller = "App\\Http\\Controllers\\Gameberry\\Final9\\Final91271Controller";
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
    Route::get('/view/{feature}', [\App\Http\Controllers\Gameberry\Final10\Final10ViewController::class, 'show'])
        ->whereNumber('feature')->name('view');
    Route::get('/coverage', [\App\Http\Controllers\Gameberry\Final10\Final10ViewController::class, 'coverage'])->name('coverage');
    for ($i = 1301; $i <= 1350; $i++) {
        $controller = "App\\Http\\Controllers\\Gameberry\\Final10\\Final10" . (1371 + ($i - 1301) % 15) . "Controller";
        // Use first 15 controllers cycling for 50 views
        if ($i <= 1315) {
            $controller = "App\\Http\\Controllers\\Gameberry\\Final10\\Final10" . (1371 + $i - 1301) . "Controller";
        } else {
            $controller = "App\\Http\\Controllers\\Gameberry\\Final10\\Final101371Controller";
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
    Route::get('/view/{feature}', [\App\Http\Controllers\Gameberry\Final11\Final11ViewController::class, 'show'])
        ->whereNumber('feature')->name('view');
    Route::get('/coverage', [\App\Http\Controllers\Gameberry\Final11\Final11ViewController::class, 'coverage'])->name('coverage');
    for ($i = 1401; $i <= 1450; $i++) {
        $controller = "App\\Http\\Controllers\\Gameberry\\Final11\\Final11" . (1471 + ($i - 1401) % 15) . "Controller";
        // Use first 15 controllers cycling for 50 views
        if ($i <= 1415) {
            $controller = "App\\Http\\Controllers\\Gameberry\\Final11\\Final11" . (1471 + $i - 1401) . "Controller";
        } else {
            $controller = "App\\Http\\Controllers\\Gameberry\\Final11\\Final111471Controller";
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
    Route::get('/view/{feature}', [\App\Http\Controllers\Gameberry\Final12\Final12ViewController::class, 'show'])
        ->whereNumber('feature')->name('view');
    Route::get('/coverage', [\App\Http\Controllers\Gameberry\Final12\Final12ViewController::class, 'coverage'])->name('coverage');
    for ($i = 1501; $i <= 1550; $i++) {
        $controller = "App\\Http\\Controllers\\Gameberry\\Final12\\Final12" . (1571 + ($i - 1501) % 15) . "Controller";
        // Use first 15 controllers cycling for 50 views
        if ($i <= 1515) {
            $controller = "App\\Http\\Controllers\\Gameberry\\Final12\\Final12" . (1571 + $i - 1501) . "Controller";
        } else {
            $controller = "App\\Http\\Controllers\\Gameberry\\Final12\\Final121571Controller";
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
