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
