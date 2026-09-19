<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Gameberry\DiceApiController;
use App\Http\Controllers\Api\V1\Gameberry\LeagueApiController;
use App\Http\Controllers\Api\V1\Gameberry\PrivateTableApiController;
use App\Http\Controllers\Api\V1\Gameberry\EconomyApiController;
use App\Http\Controllers\Api\V1\Gameberry\SocialApiController;
use App\Http\Controllers\Api\V1\Gameberry\ReferralApiController;
use App\Http\Controllers\Api\V1\Gameberry\SpinApiController;
use App\Http\Controllers\Api\V1\Gameberry\ChatApiController;
use App\Http\Controllers\Api\V1\Gameberry\WeeklyEventApiController;
use App\Http\Controllers\Api\V1\Gameberry\VideoAdApiController;
use App\Http\Controllers\Api\V1\Gameberry\MagicChestApiController;

/*
|--------------------------------------------------------------------------
| Gameberry API Routes - LudoStar Style Features
|--------------------------------------------------------------------------
| 250+ dice collection, lucky dice, Facebook-only exchange, 6-step league
| Bronze Silver Gold Platinum Diamond Titan, Top 20% promotion Top 40 demotion
| Titan badges, Game Buddies max 25, private table code/link sharing,
| challenge button, team-up mode, classic/master/quick variations,
| chat & emojis, weekly special events, gold at stake, magic chest,
| video ads free gold, gems, lucky dice gem reward, spin2win,
| auto mode on disconnect, hide online status, notify friends online,
| level system Level 4 Bronze unlock, referral BGI20 ₹25 bonus, scratch cards,
| gold wallets gem wallets transactions reconciliation
*/

Route::middleware(['auth:sanctum'])->prefix('v1/gameberry')->name('api.v1.gameberry.')->group(function () {
    // Dice Collection - 250+ dice, max 52, Facebook-only, Lucky dice
    Route::prefix('dice')->name('dice.')->group(function () {
        Route::get('/collection', [DiceApiController::class, 'collection'])->name('collection');
        Route::get('/available', [DiceApiController::class, 'available'])->name('available');
        Route::get('/lucky', [DiceApiController::class, 'luckyDice'])->name('lucky');
        Route::get('/exchanges', [DiceApiController::class, 'exchanges'])->name('exchanges');
        Route::post('/{diceId}/equip', [DiceApiController::class, 'equip'])->name('equip');
        Route::post('/{diceId}/favorite', [DiceApiController::class, 'favorite'])->name('favorite');
        Route::post('/exchange', [DiceApiController::class, 'exchange'])->name('exchange');
        Route::post('/exchanges/{exchangeId}/accept', [DiceApiController::class, 'acceptExchange'])->name('exchange.accept');
        Route::post('/exchanges/{exchangeId}/deny', [DiceApiController::class, 'denyExchange'])->name('exchange.deny');
        Route::post('/lucky/{luckyDiceId}/roll', [DiceApiController::class, 'rollLuckyDice'])->name('lucky.roll');
    });

    // League System - 6-step Bronze to Titan
    Route::prefix('league')->name('league.')->group(function () {
        Route::get('/', [LeagueApiController::class, 'index'])->name('index');
        Route::get('/history', [LeagueApiController::class, 'history'])->name('history');
        Route::get('/{slug}', [LeagueApiController::class, 'show'])->name('show');
        Route::get('/{slug}/leaderboard', [LeagueApiController::class, 'leaderboard'])->name('leaderboard');
        Route::post('/trophies', [LeagueApiController::class, 'addTrophies'])->name('trophies');
    });

    // Private Tables - Code/Link sharing, Challenge, Team-up, Gold at stake, Auto mode
    Route::prefix('private-tables')->name('private_tables.')->group(function () {
        Route::get('/', [PrivateTableApiController::class, 'index'])->name('index');
        Route::post('/', [PrivateTableApiController::class, 'store'])->name('store');
        Route::get('/{code}', [PrivateTableApiController::class, 'show'])->name('show');
        Route::get('/{code}/share', [PrivateTableApiController::class, 'share'])->name('share');
        Route::post('/{code}/join', [PrivateTableApiController::class, 'join'])->name('join');
        Route::post('/{code}/leave', [PrivateTableApiController::class, 'leave'])->name('leave');
        Route::post('/{code}/ready', [PrivateTableApiController::class, 'ready'])->name('ready');
        Route::post('/{code}/start', [PrivateTableApiController::class, 'start'])->name('start');
        Route::post('/{code}/auto-mode', [PrivateTableApiController::class, 'autoMode'])->name('auto_mode');
        Route::post('/challenge', [PrivateTableApiController::class, 'challenge'])->name('challenge');
    });

    // Chat & Emojis
    Route::prefix('chat/{code}')->name('chat.')->group(function () {
        Route::post('/send', [ChatApiController::class, 'send'])->name('send');
        Route::post('/emoji', [ChatApiController::class, 'emoji'])->name('emoji');
        Route::post('/quick', [ChatApiController::class, 'quick'])->name('quick');
        Route::get('/messages', [ChatApiController::class, 'messages'])->name('messages');
    });

    // Economy - Gold wallets gem wallets transactions reconciliation, Magic chest, Video ads free gold, Gems, Spin2win
    Route::prefix('economy')->name('economy.')->group(function () {
        Route::get('/', [EconomyApiController::class, 'index'])->name('index');
        Route::get('/gold', [EconomyApiController::class, 'goldBalance'])->name('gold');
        Route::get('/gems', [EconomyApiController::class, 'gemBalance'])->name('gems');
        Route::get('/gold/history', [EconomyApiController::class, 'goldHistory'])->name('gold.history');
        Route::get('/gems/history', [EconomyApiController::class, 'gemHistory'])->name('gems.history');
        Route::post('/video-ads/watch', [EconomyApiController::class, 'watchAd'])->name('video_ads.watch');
        Route::get('/video-ads/stats', [EconomyApiController::class, 'videoStats'])->name('video_ads.stats');
        Route::get('/chests', [EconomyApiController::class, 'chests'])->name('chests');
        Route::post('/chests/{chestId}/open', [EconomyApiController::class, 'openChest'])->name('chests.open');
        Route::post('/chests/create', [EconomyApiController::class, 'createChest'])->name('chests.create');
        Route::post('/spin', [EconomyApiController::class, 'spin'])->name('spin');
        Route::get('/spin/stats', [EconomyApiController::class, 'spinStats'])->name('spin.stats');
        Route::get('/spin/history', [EconomyApiController::class, 'spinHistory'])->name('spin.history');
    });

    // Social - Game Buddies max 25, Hide online status, Notify friends online, Challenge button, Auto mode
    Route::prefix('social')->name('social.')->group(function () {
        Route::get('/buddies', [SocialApiController::class, 'buddies'])->name('buddies');
        Route::post('/buddies', [SocialApiController::class, 'addBuddy'])->name('buddies.add');
        Route::post('/buddies/{buddyId}/accept', [SocialApiController::class, 'acceptBuddy'])->name('buddies.accept');
        Route::post('/buddies/{buddyId}/remove', [SocialApiController::class, 'removeBuddy'])->name('buddies.remove');
        Route::post('/online-status', [SocialApiController::class, 'onlineStatus'])->name('online_status');
        Route::post('/hide-status', [SocialApiController::class, 'hideOnlineStatus'])->name('hide_status');
        Route::post('/notify-friends', [SocialApiController::class, 'notifyFriends'])->name('notify_friends');
        Route::post('/auto-mode', [SocialApiController::class, 'autoMode'])->name('auto_mode');
        Route::post('/challenge', [SocialApiController::class, 'challenge'])->name('challenge');
        Route::get('/challenges', [SocialApiController::class, 'challenges'])->name('challenges');
        Route::post('/challenges/{challengeId}/accept', [SocialApiController::class, 'acceptChallenge'])->name('challenges.accept');
        Route::get('/notifications', [SocialApiController::class, 'notifications'])->name('notifications');
        Route::get('/stats', [SocialApiController::class, 'stats'])->name('stats');
    });

    // Spin2Win dedicated
    Route::prefix('spin')->name('spin.')->group(function () {
        Route::get('/', [SpinApiController::class, 'index'])->name('index');
        Route::post('/', [SpinApiController::class, 'spin'])->name('spin');
        Route::get('/stats', [SpinApiController::class, 'stats'])->name('stats');
        Route::get('/history', [SpinApiController::class, 'history'])->name('history');
    });

    // Magic Chest dedicated
    Route::prefix('magic-chests')->name('magic_chests.')->group(function () {
        Route::get('/', [MagicChestApiController::class, 'index'])->name('index');
        Route::post('/', [MagicChestApiController::class, 'create'])->name('create');
        Route::post('/{chestId}/open', [MagicChestApiController::class, 'open'])->name('open');
        Route::get('/available', [MagicChestApiController::class, 'available'])->name('available');
    });

    // Weekly Special Events
    Route::prefix('events')->name('events.')->group(function () {
        Route::get('/', [WeeklyEventApiController::class, 'index'])->name('index');
        Route::get('/{eventId}', [WeeklyEventApiController::class, 'show'])->name('show');
        Route::post('/{eventId}/join', [WeeklyEventApiController::class, 'join'])->name('join');
        Route::post('/{eventId}/claim', [WeeklyEventApiController::class, 'claim'])->name('claim');
        Route::get('/{eventId}/leaderboard', [WeeklyEventApiController::class, 'leaderboard'])->name('leaderboard');
    });

    // Video Ads Free Gold
    Route::prefix('video-ads')->name('video_ads.')->group(function () {
        Route::get('/stats', [VideoAdApiController::class, 'stats'])->name('stats');
        Route::post('/watch', [VideoAdApiController::class, 'watch'])->name('watch');
        Route::get('/history', [VideoAdApiController::class, 'history'])->name('history');
    });

    // Referral BGI20 ₹25 bonus + Scratch cards
    Route::prefix('referral')->name('referral.')->group(function () {
        Route::get('/', [ReferralApiController::class, 'index'])->name('index');
        Route::post('/generate', [ReferralApiController::class, 'generateCode'])->name('generate');
        Route::post('/apply', [ReferralApiController::class, 'applyCode'])->name('apply');
        Route::get('/scratch-cards', [ReferralApiController::class, 'scratchCards'])->name('scratch_cards');
        Route::post('/scratch-cards/{cardId}/scratch', [ReferralApiController::class, 'scratch'])->name('scratch');
        Route::get('/stats', [ReferralApiController::class, 'stats'])->name('stats');
    });
});

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
