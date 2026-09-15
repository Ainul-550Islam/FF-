<?php

use App\Http\Controllers\Api\V1\AppMetaController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\DisputeController;
use App\Http\Controllers\Api\V1\LeaderboardController;
use App\Http\Controllers\Api\V1\LiveController;
use App\Http\Controllers\Api\V1\MatchController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PlayerController;
use App\Http\Controllers\Api\V1\SupportController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\TokenController;
use App\Http\Controllers\Api\V1\TournamentController;
use App\Http\Controllers\Api\V1\WalletController;
use App\Http\Controllers\Api\V1\WebhookInboundController;
use App\Http\Controllers\Api\V1\WebhookSubscriptionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| FF Arena Public API (Phase 15)
|--------------------------------------------------------------------------
|
| All public business endpoints are versioned under /api/v1. A future
| /api/v2 can be added alongside without breaking v1 clients. Sessions
| (cookies) are never accepted here — bearer tokens only.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function () {

    // ------------------------------------------------------------------
    // Authentication (anonymous, tightly rate-limited)
    // ------------------------------------------------------------------
    Route::post('auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:api_register')->name('auth.register');

    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:api_login')->name('auth.login');

    Route::post('auth/google', [AuthController::class, 'google'])
        ->middleware('throttle:api_login')->name('auth.google');

    Route::post('auth/otp/request', [AuthController::class, 'otpRequest'])
        ->middleware('throttle:api_otp_request')->name('auth.otp.request');

    Route::post('auth/otp/verify', [AuthController::class, 'otpVerify'])
        ->middleware('throttle:api_otp_verify')->name('auth.otp.verify');

    // ------------------------------------------------------------------
    // Public discovery (anonymous, rate-limited)
    // ------------------------------------------------------------------
    Route::middleware('throttle:api_anon')->group(function () {
        Route::get('app/meta', [AppMetaController::class, 'meta'])->name('app.meta');
        Route::get('tournaments', [TournamentController::class, 'index'])->name('tournaments.index');
        Route::get('tournaments/{tournament}', [TournamentController::class, 'show'])->name('tournaments.show');
        Route::get('tournaments/{tournament}/matches', [TournamentController::class, 'matches'])->name('tournaments.matches');
        Route::get('tournaments/{tournament}/leaderboard', [TournamentController::class, 'leaderboard'])->name('tournaments.leaderboard');
        Route::get('tournaments/{tournament}/bracket', [TournamentController::class, 'bracket'])->name('tournaments.bracket');
        Route::get('matches/{match}', [MatchController::class, 'show'])->name('matches.show');
        Route::get('players/{user}', [PlayerController::class, 'show'])->name('players.show');
        Route::get('players/{user}/ranking', [LeaderboardController::class, 'playerRanking'])->name('players.ranking');
        Route::get('leaderboards', [LeaderboardController::class, 'index'])->name('leaderboards.index');
        Route::get('leaderboards/{tournament}', [LeaderboardController::class, 'show'])->name('leaderboards.show');
    });

    // ------------------------------------------------------------------
    // Authenticated (bearer token + valid, active account)
    // ------------------------------------------------------------------
    Route::middleware(['bearer', 'auth:sanctum', 'api.token', 'throttle:api'])->group(function () {

        // Me / profile
        Route::get('me', [MeController::class, 'show'])->middleware('abilities:profile:read')->name('me.show');
        Route::put('me/profile', [MeController::class, 'update'])->middleware('abilities:profile:write')->name('me.profile.update');
        Route::patch('me/profile', [MeController::class, 'update'])->middleware('abilities:profile:write')->name('me.profile.patch');

        // Security / sessions
        Route::get('me/security', [MeController::class, 'security'])->middleware('abilities:profile:read')->name('me.security');
        Route::get('me/sessions', [MeController::class, 'sessions'])->middleware('abilities:profile:read')->name('me.sessions');
        Route::delete('me/sessions/{session}', [MeController::class, 'revokeSession'])->middleware('abilities:profile:write')->name('me.sessions.revoke');
        Route::post('me/sessions/revoke-others', [MeController::class, 'revokeOthers'])->middleware('abilities:profile:write')->name('me.sessions.revoke_others');
        Route::post('me/sessions/revoke-all', [MeController::class, 'revokeAll'])->middleware('abilities:profile:write')->name('me.sessions.revoke_all');

        // Notifications
        Route::get('me/notifications', [NotificationController::class, 'index'])->middleware('abilities:notifications:read')->name('me.notifications');
        Route::get('me/notifications/unread-count', [NotificationController::class, 'unreadCount'])->middleware('abilities:notifications:read')->name('me.notifications.unread_count');
        Route::post('me/notifications/{notification}/read', [NotificationController::class, 'markRead'])->middleware('abilities:notifications:write')->name('me.notifications.read');
        Route::post('me/notifications/read-all', [NotificationController::class, 'markAllRead'])->middleware('abilities:notifications:write')->name('me.notifications.read_all');

        // Push notification preferences (Phase 19)
        Route::get('me/notification-preferences', [NotificationPreferenceController::class, 'index'])->middleware('abilities:notifications:read')->name('me.notification_preferences');
        Route::patch('me/notification-preferences', [NotificationPreferenceController::class, 'update'])->middleware('abilities:notifications:write')->name('me.notification_preferences.update');

        // Realtime
        Route::get('me/live', [LiveController::class, 'me'])->middleware('abilities:notifications:read')->name('me.live');
        Route::get('tournaments/{tournament}/live', [TournamentController::class, 'live'])->middleware('throttle:api_anon')->name('tournaments.live');

        // Teams
        Route::get('me/teams', [TeamController::class, 'index'])->middleware('abilities:teams:read')->name('me.teams');
        Route::get('teams/{team}', [TeamController::class, 'show'])->middleware('abilities:teams:read')->name('teams.show');
        Route::patch('teams/{team}', [TeamController::class, 'update'])->middleware('abilities:teams:write')->name('teams.update');
        Route::get('teams/{team}/roster', [TeamController::class, 'roster'])->middleware('abilities:roster:read')->name('teams.roster');
        Route::post('teams/{team}/roster', [TeamController::class, 'addMember'])->middleware('abilities:roster:write')->name('teams.roster.add');
        Route::delete('teams/{team}/roster/{member}', [TeamController::class, 'removeMember'])->middleware('abilities:roster:write')->name('teams.roster.remove');
        Route::post('teams/{team}/withdraw', [TeamController::class, 'withdraw'])->middleware('abilities:teams:write')->name('teams.withdraw');

        // Registration / check-in / waitlist
        Route::post('tournaments/{tournament}/registrations', [TournamentController::class, 'register'])
            ->middleware(['abilities:tournaments:register', 'idempotency'])->name('tournaments.register');
        Route::post('tournaments/{tournament}/check-in', [TournamentController::class, 'checkIn'])
            ->middleware('abilities:tournaments:register')->name('tournaments.checkin');
        Route::get('tournaments/{tournament}/waitlist', [TournamentController::class, 'waitlist'])
            ->middleware('abilities:tournaments:read')->name('tournaments.waitlist');

        // Score submission
        Route::post('matches/{match}/scores', [MatchController::class, 'submitScore'])
            ->middleware(['abilities:scores:submit', 'throttle:api_score', 'idempotency'])->name('matches.scores.submit');

        // Payments
        Route::get('payments/methods', [PaymentController::class, 'methods'])->middleware('abilities:wallet:read')->name('payments.methods');
        Route::post('payments', [PaymentController::class, 'store'])
            ->middleware(['abilities:payments:create', 'throttle:api_payment', 'idempotency'])->name('payments.store');
        Route::get('payments/{payment}', [PaymentController::class, 'show'])->middleware('abilities:payments:read')->name('payments.show');

        // Wallet / payouts (read-only)
        Route::get('me/wallet', [WalletController::class, 'show'])->middleware('abilities:wallet:read')->name('me.wallet');
        Route::get('me/wallet/ledger', [WalletController::class, 'ledger'])->middleware('abilities:wallet:read')->name('me.wallet.ledger');
        Route::get('me/payouts', [WalletController::class, 'payouts'])->middleware('abilities:payouts:read')->name('me.payouts');

        // Mobile push devices (Phase 18)
        Route::get('me/devices', [DeviceController::class, 'index'])->middleware('abilities:notifications:read')->name('me.devices');
        Route::post('me/devices', [DeviceController::class, 'store'])->middleware('abilities:notifications:write')->name('me.devices.store');
        Route::delete('me/devices/{device}', [DeviceController::class, 'destroy'])->middleware('abilities:notifications:write')->name('me.devices.destroy');

        // Support / disputes
        Route::get('me/support', [SupportController::class, 'index'])->middleware('abilities:support:read')->name('me.support.index');
        Route::post('me/support', [SupportController::class, 'store'])
            ->middleware(['abilities:support:write', 'throttle:api_support', 'idempotency'])->name('me.support.store');
        Route::get('me/support/{ticket}', [SupportController::class, 'show'])->middleware('abilities:support:read')->name('me.support.show');
        Route::get('me/support/{ticket}/messages', [SupportController::class, 'messages'])->middleware('abilities:support:read')->name('me.support.messages');
        Route::post('me/support/{ticket}/messages', [SupportController::class, 'reply'])
            ->middleware(['abilities:support:write', 'throttle:api_support'])->name('me.support.reply');
        Route::get('me/disputes', [DisputeController::class, 'index'])->middleware('abilities:disputes:read')->name('me.disputes');
        Route::get('disputes/{dispute}', [DisputeController::class, 'show'])->middleware('abilities:disputes:read')->name('disputes.show');

        // Personal access tokens / API clients
        Route::post('me/tokens', [TokenController::class, 'store'])
            ->middleware(['abilities:profile:write', 'throttle:api_token_issue'])->name('me.tokens.store');
        Route::get('me/tokens', [TokenController::class, 'index'])->middleware('abilities:profile:read')->name('me.tokens.index');
        Route::delete('me/tokens/{tokenId}', [TokenController::class, 'destroy'])->middleware('abilities:profile:write')->name('me.tokens.destroy');
        Route::get('me/clients', [TokenController::class, 'clients'])->middleware('abilities:profile:read')->name('me.clients.index');
        Route::post('me/clients', [TokenController::class, 'storeClient'])
            ->middleware(['abilities:profile:write', 'throttle:api_token_issue'])->name('me.clients.store');
        Route::delete('me/clients/{client}', [TokenController::class, 'destroyClient'])->middleware('abilities:profile:write')->name('me.clients.destroy');

        // ------------------------------------------------------------------
        // Admin-only: outbound webhook subscriptions
        // ------------------------------------------------------------------
        Route::middleware(['admin', 'abilities:admin'])->prefix('admin')->name('admin.')->group(function () {
            Route::get('webhooks/endpoints', [WebhookSubscriptionController::class, 'index'])->name('webhooks.endpoints.index');
            Route::post('webhooks/endpoints', [WebhookSubscriptionController::class, 'store'])->name('webhooks.endpoints.store');
            Route::get('webhooks/endpoints/{endpoint}', [WebhookSubscriptionController::class, 'show'])->name('webhooks.endpoints.show');
            Route::post('webhooks/endpoints/{endpoint}/rotate-secret', [WebhookSubscriptionController::class, 'rotateSecret'])->name('webhooks.endpoints.rotate');
            Route::post('webhooks/endpoints/{endpoint}/toggle', [WebhookSubscriptionController::class, 'toggle'])->name('webhooks.endpoints.toggle');
            Route::get('webhooks/endpoints/{endpoint}/deliveries', [WebhookSubscriptionController::class, 'deliveries'])->name('webhooks.endpoints.deliveries');
            Route::get('webhooks/events', [WebhookSubscriptionController::class, 'events'])->name('webhooks.events.index');
        });
    });

    // ------------------------------------------------------------------
    // Inbound provider webhooks (HMAC-authenticated; no bearer required)
    // ------------------------------------------------------------------
    Route::post('webhooks/inbound/{provider}', [WebhookInboundController::class, 'handle'])
        ->middleware('throttle:api_webhook')->name('webhooks.inbound');
});
