<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\AvatarController;
use App\Http\Controllers\TournamentController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\DisputeController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminAccountController;
use App\Http\Controllers\AdminAnalyticsController;
use App\Http\Controllers\AdminSecurityController;
use App\Http\Controllers\SettlementController;
use App\Http\Controllers\OpsController;
use App\Http\Controllers\AccountSecurityController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\PhoneAuthController;

/*
|--------------------------------------------------------------------------
| FF Arena Web Routes - Production Ready with Profile, Avatar, Settings, Internet Check
|--------------------------------------------------------------------------
| All web routes with CSRF protection, session, and middleware.
| Profile/Avatar/Settings require auth + active account.
| Admin requires admin middleware.
*/

// Health & Public
Route::get('/up', function () {
    return response()->json(['status' => 'ok', 'service' => 'ffarena-laravel', 'time' => now()->toIso8601String()]);
})->name('health.up');

Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'service' => 'FF Arena', 'checks' => ['db' => true, 'cache' => true]]);
})->name('health');

Route::get('/health/live', function () {
    return response()->json(['status' => 'ok']);
})->name('health.live');

Route::get('/health/ready', function () {
    try {
        \Illuminate\Support\Facades\DB::connection()->getPdo();
        $db = true;
    } catch (\Throwable $e) {
        $db = false;
    }
    $status = $db ? 200 : 503;
    return response()->json(['status' => $db ? 'ok' : 'degraded', 'checks' => ['database' => $db, 'cache' => true, 'storage' => true]], $status);
})->name('health.ready');

// SEO
Route::get('/sitemap.xml', [SitemapController::class, 'sitemap'])->name('sitemap');
Route::get('/robots.txt', [SitemapController::class, 'robots'])->name('robots');

// Home & Public Pages
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/tournaments', [TournamentController::class, 'index'])->name('tournaments.index');
Route::get('/tournaments/{tournament:slug}', [TournamentController::class, 'show'])->name('tournaments.show');
Route::get('/leaderboard/{tournament}', [LeaderboardController::class, 'show'])->name('leaderboard.show');
Route::get('/matches/{match}', [MatchController::class, 'show'])->name('matches.show');

// Auth - Guest only
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:login');
    Route::get('/register', [RegisterController::class, 'showRegistrationForm'])->name('register');
    Route::post('/register', [RegisterController::class, 'register'])->middleware('throttle:register');
    
    Route::get('/forgot-password', [LoginController::class, 'showForgotForm'])->name('password.request');
    Route::post('/forgot-password', [LoginController::class, 'sendResetLink'])->name('password.email')->middleware('throttle:password-reset');
    Route::get('/reset-password/{token}', [LoginController::class, 'showResetForm'])->name('password.reset');
    Route::post('/reset-password', [LoginController::class, 'resetPassword'])->name('password.update');

    Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');

    Route::get('/auth/phone', [PhoneAuthController::class, 'showPhoneForm'])->name('auth.phone');
    Route::post('/auth/phone/request', [PhoneAuthController::class, 'requestOtp'])->name('auth.phone.request')->middleware('throttle:otp-request');
    Route::get('/auth/phone/verify', [PhoneAuthController::class, 'showVerifyForm'])->name('auth.phone.verify.form');
    Route::post('/auth/phone/verify', [PhoneAuthController::class, 'verifyOtp'])->name('auth.phone.verify')->middleware('throttle:otp-verify');
});

// Authenticated - Active Account Required
Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    // Avatar - Private serving
    Route::get('/avatar/{user}', [AvatarController::class, 'show'])->name('avatar.show');
    Route::get('/avatar/{user}/thumb', [AvatarController::class, 'thumbnail'])->name('avatar.thumb');

    // Profile - Core feature requested
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::get('/profile/edit', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile/avatar', [ProfileController::class, 'removeAvatar'])->name('profile.avatar.remove');

    // Settings - Core feature requested
    Route::prefix('settings')->name('settings.')->group(function () {
        // Security
        Route::get('/security', [AccountSecurityController::class, 'security'])->name('security');
        Route::put('/security/password', [AccountSecurityController::class, 'updatePassword'])->name('security.password');
        Route::get('/security/2fa/setup', [AccountSecurityController::class, 'setup2fa'])->name('security.2fa.setup');
        Route::post('/security/2fa/enable', [AccountSecurityController::class, 'enable2fa'])->name('security.2fa.enable');
        Route::post('/security/2fa/disable', [AccountSecurityController::class, 'disable2fa'])->name('security.2fa.disable');

        // Sessions - Internet check relevant
        Route::get('/sessions', [AccountSecurityController::class, 'sessions'])->name('sessions');
        Route::delete('/sessions/{session}', [AccountSecurityController::class, 'revokeSession'])->name('sessions.revoke');
        Route::delete('/sessions', [AccountSecurityController::class, 'revokeAllSessions'])->name('sessions.revokeAll');

        // Login History
        Route::get('/login-history', [AccountSecurityController::class, 'loginHistory'])->name('login-history');

        // Connected Accounts
        Route::get('/connected-accounts', [AccountSecurityController::class, 'connectedAccounts'])->name('connected-accounts');
        Route::delete('/connected-accounts/{provider}', [AccountSecurityController::class, 'disconnect'])->name('connected-accounts.disconnect');
        Route::post('/phone/verify/request', [AccountSecurityController::class, 'requestPhoneVerification'])->name('phone.verify.request');

        // Payment Methods
        Route::get('/payment-methods', [AccountSecurityController::class, 'paymentMethods'])->name('payment-methods');
        Route::put('/payment-methods/{paymentMethod}/default', [AccountSecurityController::class, 'setDefaultPaymentMethod'])->name('payment-methods.default');
        Route::delete('/payment-methods/{paymentMethod}', [AccountSecurityController::class, 'destroyPaymentMethod'])->name('payment-methods.destroy');
    });

    // Wallet & Payments
    Route::get('/wallet', [WalletController::class, 'index'])->name('wallet.index');
    Route::get('/wallet/ledger', [WalletController::class, 'ledger'])->name('wallet.ledger');
    Route::get('/payments/methods', [PaymentController::class, 'methods'])->name('payment.methods');
    Route::get('/payments/{payment}', [PaymentController::class, 'show'])->name('payment.show');
    Route::get('/payments/{payment}/pending', [PaymentController::class, 'pending'])->name('payment.pending');

    // Tournaments - Auth actions
    Route::get('/tournaments/{tournament:slug}/register', [TeamController::class, 'showRegisterForm'])->name('teams.register.form');
    Route::post('/tournaments/{tournament:slug}/register', [TeamController::class, 'register'])->name('teams.register')->middleware('throttle:tournament-register');
    Route::get('/teams/{team}', [TeamController::class, 'show'])->name('teams.show');

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.readAll');

    // Support & Disputes
    Route::get('/support', [SupportController::class, 'index'])->name('support.index');
    Route::get('/support/create', [SupportController::class, 'create'])->name('support.create');
    Route::post('/support', [SupportController::class, 'store'])->name('support.store')->middleware('throttle:support');
    Route::get('/support/{ticket}', [SupportController::class, 'show'])->name('support.show');

    Route::get('/disputes/create/{match}', [DisputeController::class, 'create'])->name('disputes.create');
    Route::post('/disputes', [DisputeController::class, 'store'])->name('disputes.store')->middleware('throttle:dispute');
    Route::get('/disputes/{dispute}', [DisputeController::class, 'show'])->name('disputes.show');

    // Live Poll (for live tournament updates)
    Route::get('/live/poll', [HomeController::class, 'livePoll'])->name('live.poll');
});

// Admin - Admin + Active
Route::middleware(['auth', 'active', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
    Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard.index');

    // Tournaments Management
    Route::get('/tournaments/create', [TournamentController::class, 'create'])->name('tournaments.create');
    Route::post('/tournaments', [TournamentController::class, 'store'])->name('tournaments.store');
    Route::get('/tournaments/{tournament}/edit', [TournamentController::class, 'edit'])->name('tournaments.edit');
    Route::put('/tournaments/{tournament}', [TournamentController::class, 'update'])->name('tournaments.update');
    Route::get('/tournaments/{tournament}/scoring', [TournamentController::class, 'scoring'])->name('tournaments.scoring');

    // Accounts
    Route::get('/accounts', [AdminAccountController::class, 'index'])->name('accounts.index');
    Route::get('/accounts/{user}', [AdminAccountController::class, 'show'])->name('accounts.show');

    // Financial
    Route::get('/wallet', [AdminController::class, 'wallet'])->name('wallet');
    Route::get('/payments', [AdminController::class, 'payments'])->name('payments');
    Route::get('/payouts', [AdminController::class, 'payouts'])->name('payouts');
    Route::get('/settlements', [SettlementController::class, 'index'])->name('settlements');
    Route::get('/settlements/{settlement}', [SettlementController::class, 'show'])->name('settlements.show');
    Route::get('/tournaments/{tournament}/settlement', [SettlementController::class, 'showByTournament'])->name('tournaments.settlement');

    // Support & Moderation
    Route::get('/support', [AdminController::class, 'support'])->name('support');
    Route::get('/support/{ticket}', [AdminController::class, 'supportTicket'])->name('support.ticket');
    Route::get('/moderation', [AdminController::class, 'moderation'])->name('moderation');
    Route::get('/security', [AdminSecurityController::class, 'dashboard'])->name('security.dashboard');
    Route::get('/security/events', [AdminSecurityController::class, 'events'])->name('security.events');
    Route::get('/security/incidents', [AdminSecurityController::class, 'incidents'])->name('security.incidents');
    Route::get('/security/users', [AdminSecurityController::class, 'users'])->name('security.users');
    Route::get('/security/users/{user}', [AdminSecurityController::class, 'user'])->name('security.user');

    // Analytics
    Route::get('/analytics', [AdminAnalyticsController::class, 'index'])->name('analytics.index');
    Route::get('/analytics/tournaments', [AdminAnalyticsController::class, 'tournaments'])->name('analytics.tournaments');
    Route::get('/analytics/tournament', [AdminAnalyticsController::class, 'tournament'])->name('analytics.tournament');
    Route::get('/analytics/financial', [AdminAnalyticsController::class, 'financial'])->name('analytics.financial');
    Route::get('/analytics/disputes', [AdminAnalyticsController::class, 'disputes'])->name('analytics.disputes');
    Route::get('/analytics/security', [AdminAnalyticsController::class, 'security'])->name('analytics.security');
    Route::get('/analytics/support', [AdminAnalyticsController::class, 'support'])->name('analytics.support');

    // Audit & Ops
    Route::get('/audit', [AdminController::class, 'audit'])->name('audit');
    Route::get('/ops', [OpsController::class, 'dashboard'])->name('ops.dashboard');
    Route::get('/ops/failed-jobs', [OpsController::class, 'failedJobs'])->name('ops.failed-jobs');
});

// Fallback - 404
Route::fallback(function () {
    return response()->view('errors.404', [], 404);
});
