<?php

use App\Http\Controllers\AccountLiveController;
use App\Http\Controllers\AccountSecurityController;
use App\Http\Controllers\AdminAccountController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminSupportController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\DisputeController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\LiveController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\ModerationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OpsController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentGatewayCallbackController;
use App\Http\Controllers\PaymentMethodsController;
use App\Http\Controllers\PayoutController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ScoringRuleController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SettlementController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TournamentController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

// SEO — dynamic robots.txt (absolute Sitemap URL) + XML sitemap (Phase 17).
Route::get('/robots.txt', [SitemapController::class, 'robots'])->name('robots');
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');

// Mobile deep links (Phase 19) — Android App Links and iOS Universal Links
// verification files. Served with the correct JSON content type when the
// request reaches the app (static web servers may serve them directly from
// public/.well-known/ instead; see docs/MOBILE_DEEP_LINKS.md).
Route::get('/.well-known/assetlinks.json', function () {
    $path = public_path('.well-known/assetlinks.json');
    abort_unless(is_file($path), 404);

    return response()->file($path, ['Content-Type' => 'application/json']);
})->name('assetlinks');

Route::get('/.well-known/apple-app-site-association', function () {
    $path = public_path('.well-known/apple-app-site-association');
    abort_unless(is_file($path), 404);

    return response()->file($path, ['Content-Type' => 'application/json']);
})->name('apple-app-site-association');

// Guest auth
Route::middleware('guest')->group(function () {
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

    // Password reset (enumeration-safe)
    Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-reset')->name('password.email');
    Route::get('/reset-password/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.update');

    // Phone login
    Route::get('/login/phone', [AuthController::class, 'showPhoneLogin'])->name('phone.login');
    Route::post('/login/phone', [AuthController::class, 'requestPhoneOtp'])->middleware('throttle:otp-request')->name('phone.request');
});

// Phone verification page + login verify (guests and authed users — the
// same page serves both the login and the account-linking flows).
Route::get('/login/phone/verify', [AuthController::class, 'showPhoneVerify'])->name('phone.verify');
Route::post('/login/phone/verify', [AuthController::class, 'verifyPhoneLogin'])->middleware('throttle:otp-verify')->name('phone.login.verify');

// Google Sign-In (guests sign in; authed users may link via the settings
// redirect which sets a session link-intent flag).
Route::get('/auth/google', [AuthController::class, 'redirectToGoogle'])->name('google.redirect');
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback'])->middleware('throttle:google-callback')->name('google.callback');

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// Provider payment webhook — authenticated by HMAC signature, not session.
Route::post('/webhooks/payments/{provider}', [WebhookController::class, 'handle'])->name('webhooks.payments');

// Payer return from a hosted gateway (bKash Tokenized Checkout, Nagad,
// SSLCommerz, …) — signed `state` token authenticates the redirect; the
// handler re-verifies with the provider server-to-server before settling.
// GET (bKash/Nagad redirect) and POST (SSLCommerz form post) are both
// accepted. Outside auth (session may be lost across the external redirect).
Route::match(['get', 'post'], '/payments/callback/{provider}', [PaymentGatewayCallbackController::class, 'confirm'])
    ->middleware('throttle:payment-initiate')->name('payments.callback');

// Public tournament browsing
Route::get('/tournaments', [TournamentController::class, 'index'])->name('tournaments.index');
Route::get('/tournaments/{tournament}', [TournamentController::class, 'show'])->name('tournaments.show');
Route::get('/tournaments/{tournament}/leaderboard', [LeaderboardController::class, 'show'])->name('leaderboard.show');

// Public profile (privacy-gated server-side; guests see only what the
// target's privacy preset allows). The edit route is declared FIRST so the
// literal `/profile/edit` wins over the `{user}` parameter route.
Route::get('/profile/edit', [ProfileController::class, 'edit'])->middleware('auth')->name('profile.edit');
Route::get('/profile/{user}', [ProfileController::class, 'show'])->name('profile.show');

// Realtime / live updates (Phase 12) — public read, server-side visibility
Route::get('/tournaments/{tournament}/live', [LiveController::class, 'tournamentLive'])->name('tournaments.live');
Route::get('/tournaments/{tournament}/stream', [LiveController::class, 'stream'])->name('tournaments.stream');

// Authenticated — every sensitive action is authorized server-side
Route::middleware('auth')->group(function () {
    // Organizer tournament lifecycle + participation controls
    Route::get('/organizer/tournaments/create', [TournamentController::class, 'create'])->name('tournaments.create');
    Route::post('/organizer/tournaments', [TournamentController::class, 'store'])->name('tournaments.store');
    Route::get('/organizer/tournaments/{tournament}/edit', [TournamentController::class, 'edit'])->name('tournaments.edit');
    Route::put('/organizer/tournaments/{tournament}', [TournamentController::class, 'update'])->name('tournaments.update');
    Route::post('/organizer/tournaments/{tournament}/publish', [TournamentController::class, 'publish'])->name('tournaments.publish');
    Route::post('/organizer/tournaments/{tournament}/close', [TournamentController::class, 'closeRegistration'])->name('tournaments.close');
    Route::post('/organizer/tournaments/{tournament}/bracket', [TournamentController::class, 'start'])->name('tournaments.bracket');
    Route::post('/organizer/tournaments/{tournament}/complete', [TournamentController::class, 'complete'])->name('tournaments.complete');
    Route::post('/organizer/tournaments/{tournament}/cancel', [TournamentController::class, 'cancel'])->name('tournaments.cancel');
    Route::post('/organizer/tournaments/{tournament}/no-shows', [TournamentController::class, 'markNoShows'])->name('tournaments.noshows');
    Route::post('/organizer/tournaments/{tournament}/waitlist/promote', [TournamentController::class, 'promoteWaitlisted'])->name('tournaments.waitlist.promote');

    // Scoring rules configuration (organizer/admin only)
    Route::get('/organizer/tournaments/{tournament}/scoring', [ScoringRuleController::class, 'show'])->name('tournaments.scoring.show');
    Route::post('/organizer/tournaments/{tournament}/scoring', [ScoringRuleController::class, 'store'])->name('tournaments.scoring.store');
    Route::post('/organizer/tournaments/{tournament}/scoring/{rule}/activate', [ScoringRuleController::class, 'activate'])->name('tournaments.scoring.activate');

    // Team registration, check-in, payment + roster management
    Route::get('/tournaments/{tournament}/register', [TeamController::class, 'showRegistration'])->name('teams.register');
    Route::post('/tournaments/{tournament}/register', [TeamController::class, 'register'])->name('teams.store');
    Route::get('/tournaments/{tournament}/teams/{team}', [TeamController::class, 'show'])->name('teams.show');
    Route::put('/tournaments/{tournament}/teams/{team}/profile', [TeamController::class, 'updateProfile'])->name('teams.update');
    Route::post('/tournaments/{tournament}/teams/{team}/members', [TeamController::class, 'addMember'])->name('teams.members.store');
    Route::post('/tournaments/{tournament}/teams/{team}/members/{member}/remove', [TeamController::class, 'removeMember'])->name('teams.members.remove');
    Route::post('/tournaments/{tournament}/teams/{team}/withdraw', [TeamController::class, 'withdraw'])->name('teams.withdraw');
    Route::post('/tournaments/{tournament}/teams/{team}/check-in', [TeamController::class, 'checkIn'])->name('teams.checkin');
    Route::get('/tournaments/{tournament}/teams/{team}/pay', [PaymentController::class, 'show'])->name('payment.show');
    Route::post('/tournaments/{tournament}/teams/{team}/pay', [PaymentController::class, 'verify'])->name('payment.verify');
    Route::get('/tournaments/{tournament}/teams/{team}/pay/{payment}/pending', [PaymentController::class, 'pending'])->name('payment.pending');

    // Checkout provider selection (Phase 14)
    Route::get('/tournaments/{tournament}/teams/{team}/pay/methods', [CheckoutController::class, 'methods'])->name('payment.methods');
    Route::post('/tournaments/{tournament}/teams/{team}/pay/initiate', [CheckoutController::class, 'initiate'])->middleware('throttle:payment-initiate')->name('payment.initiate');

    // Matches (bracket progression)
    Route::get('/tournaments/{tournament}/matches/{match}', [MatchController::class, 'show'])->name('matches.show');
    Route::post('/tournaments/{tournament}/matches/{match}/room', [MatchController::class, 'setRoom'])->name('matches.room');
    Route::post('/tournaments/{tournament}/matches/{match}/score', [MatchController::class, 'submitScore'])->name('matches.score');
    Route::post('/tournaments/{tournament}/matches/{match}/adjustment', [MatchController::class, 'addAdjustment'])->name('matches.adjustment');
    Route::post('/tournaments/{tournament}/matches/{match}/winner', [MatchController::class, 'setWinner'])->name('matches.winner');
    Route::post('/tournaments/{tournament}/matches/{match}/dispute', [MatchController::class, 'dispute'])->name('matches.dispute');
    Route::post('/tournaments/{tournament}/matches/{match}/resolve', [MatchController::class, 'resolve'])->name('matches.resolve');

    // Disputes (Phase 07) — nested under tournament + match so every record
    // is validated against its parents; authorization never relies on route
    // model binding alone.
    Route::get('/tournaments/{tournament}/matches/{match}/disputes/create', [DisputeController::class, 'create'])->name('matches.disputes.create');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes', [DisputeController::class, 'store'])->name('matches.disputes.store');
    Route::get('/tournaments/{tournament}/matches/{match}/disputes/{dispute}', [DisputeController::class, 'show'])->name('matches.disputes.show');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/evidence', [DisputeController::class, 'addEvidence'])->name('matches.disputes.evidence.store');
    Route::get('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/evidence/{evidence}', [DisputeController::class, 'evidence'])->name('matches.disputes.evidence.show');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/cancel', [DisputeController::class, 'cancel'])->name('matches.disputes.cancel');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/review', [DisputeController::class, 'review'])->name('matches.disputes.review');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/assign', [DisputeController::class, 'assign'])->name('matches.disputes.assign');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/resolve', [DisputeController::class, 'resolve'])->name('matches.disputes.resolve');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/reject', [DisputeController::class, 'reject'])->name('matches.disputes.reject');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/evidence/{evidence}/remove', [DisputeController::class, 'removeEvidence'])->name('matches.disputes.evidence.remove');

    // Moderation queue (staff)
    Route::get('/moderation', [ModerationController::class, 'index'])->name('moderation.index');

    // Moderation security review (admin + moderator only, Phase 10)
    Route::get('/moderation/security', [ModerationController::class, 'security'])->name('moderation.security');

    // Security — anti-cheat incidents + identity request (policy-guarded)
    Route::get('/security/incidents', [SecurityController::class, 'incidents'])->name('security.incidents.index');
    Route::post('/security/incidents', [SecurityController::class, 'openIncident'])->name('security.incidents.open');
    Route::post('/security/incidents/{incident}/review', [SecurityController::class, 'reviewIncident'])->name('security.incidents.review');
    Route::post('/security/incidents/{incident}/resolve', [SecurityController::class, 'resolveIncident'])->name('security.incidents.resolve');
    Route::post('/security/identity/request', [SecurityController::class, 'requestVerification'])->name('security.identity.request');

    // Wallet (authenticated user)
    Route::get('/wallet', [WalletController::class, 'index'])->name('wallet.index');

    // Notifications (Phase 11 — always the authenticated user's own inbox)
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.readAll');
    Route::get('/notifications/unread', [LiveController::class, 'unreadCount'])->name('notifications.unread');

    // Support (Phase 13 — users manage only their own tickets)
    Route::get('/support', [SupportController::class, 'index'])->name('support.index');
    Route::get('/support/create', [SupportController::class, 'create'])->name('support.create');
    Route::post('/support', [SupportController::class, 'store'])->name('support.store');
    Route::get('/support/{ticket}', [SupportController::class, 'show'])->name('support.tickets.show');
    Route::post('/support/{ticket}/reply', [SupportController::class, 'reply'])->name('support.tickets.reply');
    Route::post('/support/{ticket}/close', [SupportController::class, 'close'])->name('support.tickets.close');
    Route::post('/support/{ticket}/reopen', [SupportController::class, 'reopen'])->name('support.tickets.reopen');
    Route::get('/support/{ticket}/messages', [SupportController::class, 'messages'])->name('support.tickets.messages');

    // Email verification (Phase 14 — server-generated signed URLs)
    Route::get('/verify-email', [AuthController::class, 'showVerifyEmail'])->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', [AuthController::class, 'verifyEmail'])->name('verification.verify');
    Route::post('/email/verification-notification', [AuthController::class, 'resendVerification'])->middleware('throttle:verification-resend')->name('verification.resend');

    // Profile (Phase 14)
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/username', [ProfileController::class, 'updateUsername'])->name('profile.username');
    Route::put('/profile/privacy', [ProfileController::class, 'updatePrivacy'])->name('profile.privacy');
    Route::put('/profile/preferences', [ProfileController::class, 'updatePreferences'])->name('profile.preferences');
    Route::post('/settings/password', [ProfileController::class, 'changePassword'])->name('settings.password');

    // Security settings, sessions, login history, connected accounts,
    // lifecycle (Phase 14)
    Route::get('/settings/security', [AccountSecurityController::class, 'security'])->name('settings.security');
    Route::get('/settings/connected-accounts', [AccountSecurityController::class, 'connectedAccounts'])->name('settings.connected-accounts');
    Route::get('/settings/sessions', [AccountSecurityController::class, 'sessions'])->name('settings.sessions');
    Route::post('/settings/sessions/others', [AccountSecurityController::class, 'revokeOtherSessions'])->name('settings.sessions.revokeOthers');
    Route::post('/settings/sessions/all', [AccountSecurityController::class, 'revokeAllSessions'])->name('settings.sessions.revokeAll');
    Route::get('/settings/login-history', [AccountSecurityController::class, 'loginHistory'])->name('settings.login-history');
    Route::get('/settings/google/link', [AccountSecurityController::class, 'linkGoogleRedirect'])->name('settings.google.link');
    Route::post('/settings/google/unlink', [AccountSecurityController::class, 'unlinkGoogle'])->name('settings.google.unlink');
    Route::post('/settings/phone/link', [AccountSecurityController::class, 'linkPhone'])->middleware('throttle:otp-request')->name('settings.phone.link');
    Route::post('/settings/phone/link/verify', [AccountSecurityController::class, 'verifyPhoneLink'])->middleware('throttle:otp-verify')->name('settings.phone.link.verify');
    Route::post('/settings/phone/unlink', [AccountSecurityController::class, 'unlinkPhone'])->name('settings.phone.unlink');
    Route::post('/settings/account/deactivate', [AccountSecurityController::class, 'deactivate'])->name('settings.deactivate');
    Route::post('/settings/account/reactivate', [AccountSecurityController::class, 'reactivate'])->name('settings.reactivate');
    Route::post('/settings/account/delete-request', [AccountSecurityController::class, 'requestDeletion'])->name('settings.deletion.request');
    Route::post('/settings/account/delete-cancel', [AccountSecurityController::class, 'cancelDeletion'])->name('settings.deletion.cancel');

    // Saved payment methods (Phase 14)
    Route::get('/settings/payment-methods', [PaymentMethodsController::class, 'index'])->name('settings.payment-methods');
    Route::post('/settings/payment-methods', [PaymentMethodsController::class, 'store'])->name('settings.payment-methods.store');
    Route::delete('/settings/payment-methods/{method}', [PaymentMethodsController::class, 'destroy'])->name('settings.payment-methods.destroy');
    Route::post('/settings/payment-methods/{method}/default', [PaymentMethodsController::class, 'setDefault'])->name('settings.payment-methods.default');

    // Account realtime feed (Phase 14)
    Route::get('/account/live', [AccountLiveController::class, 'index'])->name('account.live');

    // Per-tournament operational analytics (organizer/admin/moderator)
    Route::get('/tournaments/{tournament}/analytics', [AnalyticsController::class, 'tournament'])->name('tournaments.analytics');

    // Staff (admin + moderator) support queue + staff analytics (Phase 13)
    Route::middleware('staff')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/support', [AdminSupportController::class, 'index'])->name('support.index');
        Route::get('/support/export', [AdminSupportController::class, 'export'])->name('support.export');
        Route::get('/support/{ticket}', [AdminSupportController::class, 'show'])->name('support.show');
        Route::post('/support/{ticket}/assign', [AdminSupportController::class, 'assign'])->name('support.assign');
        Route::post('/support/{ticket}/status', [AdminSupportController::class, 'status'])->name('support.status');
        Route::post('/support/{ticket}/note', [AdminSupportController::class, 'internalNote'])->name('support.note');
        Route::post('/support/{ticket}/reply', [AdminSupportController::class, 'reply'])->name('support.reply');

        Route::get('/analytics/disputes', [AnalyticsController::class, 'disputes'])->name('analytics.disputes');
        Route::get('/analytics/support', [AnalyticsController::class, 'support'])->name('analytics.support');
    });

    // Admin
    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');

        // Account administration (Phase 14)
        Route::get('/accounts', [AdminAccountController::class, 'index'])->name('accounts.index');
        Route::get('/accounts/{user}', [AdminAccountController::class, 'show'])->name('accounts.show');
        Route::post('/accounts/{user}/sessions/revoke', [AdminAccountController::class, 'revokeSessions'])->name('accounts.sessions.revoke');
        Route::post('/accounts/{user}/deactivate', [AdminAccountController::class, 'deactivate'])->name('accounts.deactivate');
        Route::post('/accounts/{user}/reactivate', [AdminAccountController::class, 'reactivate'])->name('accounts.reactivate');
        Route::post('/accounts/{user}/delete', [AdminAccountController::class, 'delete'])->name('accounts.delete');

        // Payments (Phase 08)
        Route::get('/payments', [AdminController::class, 'payments'])->name('payments.index');
        Route::post('/payments/{payment}/verify', [AdminController::class, 'verifyPayment'])->name('payments.verify');
        Route::post('/payments/{payment}/fail', [AdminController::class, 'failPayment'])->name('payments.fail');
        Route::post('/payments/{payment}/refund', [AdminController::class, 'refundPayment'])->name('payments.refund');

        // Wallets + ledger (Phase 08)
        Route::get('/users/{user}/wallet', [AdminController::class, 'wallet'])->name('wallet.show');
        Route::post('/users/{user}/wallet/credit', [AdminController::class, 'creditWallet'])->name('wallet.credit');
        Route::post('/users/{user}/wallet/debit', [AdminController::class, 'debitWallet'])->name('wallet.debit');

        // Prize distribution + payouts + settlement (Phase 09)
        Route::get('/settlements', [SettlementController::class, 'index'])->name('settlements.index');
        Route::get('/tournaments/{tournament}/settlement', [SettlementController::class, 'show'])->name('settlements.show');
        Route::post('/tournaments/{tournament}/settlement/prizes', [SettlementController::class, 'storePrizeTiers'])->name('settlements.prizes');
        Route::post('/tournaments/{tournament}/settlement/calculate', [SettlementController::class, 'calculate'])->name('settlements.calculate');
        Route::post('/tournaments/{tournament}/settlement/approve', [SettlementController::class, 'approve'])->name('settlements.approve');
        Route::post('/tournaments/{tournament}/settlement/process', [SettlementController::class, 'process'])->name('settlements.process');
        Route::post('/tournaments/{tournament}/settlement/cancel', [SettlementController::class, 'cancel'])->name('settlements.cancel');
        Route::post('/tournaments/{tournament}/settlement/adjust', [SettlementController::class, 'adjust'])->name('settlements.adjust');

        Route::get('/payouts', [PayoutController::class, 'index'])->name('payouts.index');
        Route::post('/payouts/{payout}/approve', [PayoutController::class, 'approve'])->name('payouts.approve');
        Route::post('/payouts/{payout}/process', [PayoutController::class, 'process'])->name('payouts.process');
        Route::post('/payouts/{payout}/process-override', [PayoutController::class, 'processOverride'])->name('payouts.processOverride');
        Route::post('/payouts/{payout}/complete', [PayoutController::class, 'complete'])->name('payouts.complete');
        Route::post('/payouts/{payout}/fail', [PayoutController::class, 'fail'])->name('payouts.fail');
        Route::post('/payouts/{payout}/cancel', [PayoutController::class, 'cancel'])->name('payouts.cancel');

        // Anti-fraud security administration (Phase 10)
        Route::get('/security', [SecurityController::class, 'dashboard'])->name('security.dashboard');
        Route::get('/security/users', [SecurityController::class, 'users'])->name('security.users');
        Route::get('/security/users/{user}', [SecurityController::class, 'user'])->name('security.user');
        Route::get('/security/events', [SecurityController::class, 'events'])->name('security.events');
        Route::post('/security/users/{user}/restrict', [SecurityController::class, 'restrict'])->name('security.restrict');
        Route::post('/security/restrictions/{restriction}/lift', [SecurityController::class, 'liftRestriction'])->name('security.lift');
        Route::post('/security/users/{user}/verify', [SecurityController::class, 'verifyIdentity'])->name('security.verify');
        Route::post('/security/users/{user}/reject-identity', [SecurityController::class, 'rejectIdentity'])->name('security.reject');

        // Moderation roles (Phase 07)
        Route::post('/users/moderators', [AdminController::class, 'makeModerator'])->name('users.moderate');
        Route::post('/users/{user}/remove-moderator', [AdminController::class, 'removeModerator'])->name('users.unmoderate');

        // Audit log (Phase 13 — admin only, read-only)
        Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
        Route::get('/audit/export', [AuditController::class, 'export'])->name('audit.export');

        // Analytics (Phase 13 — global/financial/security are admin only)
        Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
        Route::get('/analytics/tournaments', [AnalyticsController::class, 'tournaments'])->name('analytics.tournaments');
        Route::get('/analytics/financial', [AnalyticsController::class, 'financial'])->name('analytics.financial');
        Route::get('/analytics/security', [AnalyticsController::class, 'security'])->name('analytics.security');
        Route::get('/analytics/tournaments/export', [AnalyticsController::class, 'exportTournaments'])->name('analytics.export');

        // Infrastructure operations (Phase 16 — admin only)
        Route::prefix('ops')->name('ops.')->group(function () {
            Route::get('/', [OpsController::class, 'dashboard'])->name('dashboard');
            Route::get('/health', [OpsController::class, 'health'])->name('health');
            Route::get('/failed-jobs', [OpsController::class, 'failedJobs'])->name('failed_jobs');
            Route::post('/failed-jobs/{id}/retry', [OpsController::class, 'retryFailedJob'])->name('failed_jobs.retry');
            Route::post('/failed-jobs/retry-all', [OpsController::class, 'retryAllFailed'])->name('failed_jobs.retry_all');
            Route::post('/failed-jobs/{id}/delete', [OpsController::class, 'deleteFailedJob'])->name('failed_jobs.delete');
            Route::post('/cache/flush', [OpsController::class, 'flushCache'])->name('cache.flush');
            Route::post('/backup', [OpsController::class, 'backup'])->name('backup');
            Route::post('/backup/verify', [OpsController::class, 'verifyBackup'])->name('backup.verify');
        });
    });
});
