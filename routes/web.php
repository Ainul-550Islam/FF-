<?php

use App\Http\Controllers\AccountLiveController;
use App\Http\Controllers\AccountSecurityController;
use App\Http\Controllers\AdminAccountController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminSupportController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PhoneAuthController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AvatarController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\DisputeController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\LiveController;
use App\Http\Controllers\MarketingAffiliateController;
use App\Http\Controllers\MarketingArticleController;
use App\Http\Controllers\MarketingAutomationController;
use App\Http\Controllers\MarketingCampaignController;
use App\Http\Controllers\MarketingExperimentController;
use App\Http\Controllers\MarketingLeadController;
use App\Http\Controllers\MarketingPageController;
use App\Http\Controllers\MarketingPromoCodeController;
use App\Http\Controllers\MarketingPushController;
use App\Http\Controllers\MarketingTrackingController;
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
use App\Models\Dispute;
use App\Models\GameMatch;
use App\Models\Payment;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

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
        DB::connection()->getPdo();
        $db = true;
    } catch (Throwable $e) {
        $db = false;
    }
    $status = $db ? 200 : 503;

    return response()->json(['status' => $db ? 'ok' : 'degraded', 'checks' => ['database' => $db, 'cache' => true, 'storage' => true]], $status);
})->name('health.ready');

// SEO
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/robots.txt', [SitemapController::class, 'robots'])->name('robots');

// Home & Public Pages
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/tournaments', [TournamentController::class, 'index'])->name('tournaments.index');
Route::get('/tournaments/{tournament:slug}', [TournamentController::class, 'show'])->name('tournaments.show');
Route::get('/leaderboard/{tournament}', [LeaderboardController::class, 'show'])->name('leaderboard.show');

// --- Phase 20: public marketing / trust surfaces (P0 launch links) ---
Route::get('/privacy', [MarketingPageController::class, 'privacy'])->name('marketing.privacy');
Route::get('/terms', [MarketingPageController::class, 'terms'])->name('marketing.terms');
Route::get('/faq', [MarketingPageController::class, 'faq'])->name('marketing.faq');
Route::get('/contact', [MarketingPageController::class, 'contact'])->name('marketing.contact');

// --- Phase 20: lead capture (newsletter / contact), rate-limited ---
Route::post('/newsletter', [MarketingLeadController::class, 'store'])
    ->middleware('throttle:20,1')->name('marketing.leads.store');
Route::post('/contact', [MarketingLeadController::class, 'store'])
    ->middleware('throttle:10,1')->name('marketing.contact.store');
Route::get('/marketing/unsubscribe/{token}', [MarketingLeadController::class, 'unsubscribe'])
    ->name('marketing.unsubscribe');

// --- Phase 20: consent + first-party conversion events (§40 taxonomy) ---
Route::post('/marketing/event', [MarketingTrackingController::class, 'event'])
    ->middleware('throttle:60,1')->name('marketing.event');
Route::post('/marketing/consent', [MarketingTrackingController::class, 'consent'])
    ->middleware('throttle:30,1')->name('marketing.consent');

// --- Phase 20: campaign landing pages ---
Route::get('/campaign/{slug}', [MarketingCampaignController::class, 'show'])
    ->name('marketing.campaigns.show');

// --- Phase 21: affiliate program (public referral redirect + auth'd dashboard) ---
Route::get('/r/{code}', [MarketingAffiliateController::class, 'click'])
    ->middleware('throttle:60,1')->name('marketing.referral.click');
Route::get('/affiliates/dashboard', [MarketingAffiliateController::class, 'dashboard'])
    ->middleware('auth')->name('marketing.affiliate.dashboard');
Route::post('/affiliates', [MarketingAffiliateController::class, 'store'])
    ->middleware(['auth', 'throttle:5,1'])->name('marketing.affiliate.store');

// --- Phase 21: promo codes (server-computed discounts only) ---
Route::post('/marketing/promo/apply', [MarketingPromoCodeController::class, 'apply'])
    ->middleware(['auth', 'throttle:10,1'])->name('marketing.promo.apply');

// --- Phase 21: blog / SEO content engine ---
Route::get('/blog', [MarketingArticleController::class, 'index'])->name('marketing.articles.index');
Route::get('/blog/{slug}', [MarketingArticleController::class, 'show'])
    ->middleware('throttle:60,1')->name('marketing.articles.show');

// --- Phase 21: A/B experiments (deterministic assignment for the current visitor) ---
Route::get('/marketing/experiments/{key}/assign', [MarketingExperimentController::class, 'assign'])
    ->middleware('throttle:60,1')->name('marketing.experiments.assign');
Route::post('/marketing/experiments/{key}/convert', [MarketingExperimentController::class, 'convert'])
    ->middleware('throttle:60,1')->name('marketing.experiments.convert');

// --- Phase 21: push re-engagement subscriptions ---
Route::post('/marketing/push/subscribe', [MarketingPushController::class, 'subscribe'])
    ->middleware('throttle:20,1')->name('marketing.push.subscribe');
Route::post('/marketing/push/unsubscribe', [MarketingPushController::class, 'unsubscribe'])
    ->middleware('throttle:20,1')->name('marketing.push.unsubscribe');

// --- Phase 21: admin marketing management (experiments + lifecycle automations) ---
Route::middleware(['auth', 'active', 'admin'])->prefix('admin/marketing')->name('admin.marketing.')->group(function () {
    Route::get('/experiments', [MarketingExperimentController::class, 'adminIndex'])->name('experiments.index');
    Route::post('/experiments', [MarketingExperimentController::class, 'adminStore'])->name('experiments.store');
    Route::get('/automations', [MarketingAutomationController::class, 'index'])->name('automations.index');
    Route::post('/automations/{automation}/toggle', [MarketingAutomationController::class, 'toggle'])->name('automations.toggle');
});
// Matches are tournament-scoped: the canonical path carries the tournament
// so every match is validated against its own event.
Route::get('/tournaments/{tournament}/matches/{match}', [MatchController::class, 'show'])->name('matches.show');

// Legacy single-match URL stays alive: it resolves the match's own
// tournament and continues to the canonical page.
Route::get('/matches/{match}', function (GameMatch $match) {
    abort_if($match->tournament === null, 404);

    return redirect()->route('matches.show', [$match->tournament, $match]);
})->name('matches.detail');

// Auth - Guest only
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    // The POST endpoints are served by AuthController (the canonical
    // implementation: risk signals, audit trail, login history). The legacy
    // Auth\LoginController keeps the view endpoints. Nothing is lost: the
    // legacy flash messages and login-event columns are part of the union
    // these controllers write.
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::get('/register', [RegisterController::class, 'showRegistrationForm'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');

    Route::get('/forgot-password', [LoginController::class, 'showForgotForm'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('password.email')->middleware('throttle:password-reset');
    Route::get('/reset-password/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.update');

    // Google OAuth is served by the canonical AuthController implementation
    // (state + link-intent handling); Auth\GoogleAuthController keeps the
    // provider plumbing it exposes.

    Route::get('/auth/phone', [PhoneAuthController::class, 'showPhoneForm'])->name('auth.phone');
    Route::post('/auth/phone/request', [PhoneAuthController::class, 'requestOtp'])->name('auth.phone.request')->middleware('throttle:otp-request');
    Route::get('/auth/phone/verify', [PhoneAuthController::class, 'showVerifyForm'])->name('auth.phone.verify.form');
    Route::post('/auth/phone/verify', [PhoneAuthController::class, 'verifyOtp'])->name('auth.phone.verify')->middleware('throttle:otp-verify');
});

// Authenticated - Active Account Required
// Public profile: `/profile` (own, signed-in) and `/profile/{user}` (public
// view) are the same named route — the target is optional so both
// generations resolve and a guest can read a public profile. This route is
// deliberately outside the authenticated group below.
Route::get('/profile/{user?}', [ProfileController::class, 'show'])
    ->where('user', '^(?!edit$).*$')
    ->name('profile.show');

// --- Public live polling (guests included; LiveEventService filters events ---
// --- per viewer, so nothing private reaches anonymous visitors) --------------
Route::get('/tournaments/{tournament}/live', [LiveController::class, 'tournamentLive'])->name('tournaments.live');
Route::get('/tournaments/{tournament}/stream', [LiveController::class, 'stream'])->name('tournaments.stream');

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // Avatar - Private serving
    Route::get('/avatar/{user}', [AvatarController::class, 'show'])->name('avatar.show');
    Route::get('/avatar/{user}/thumb', [AvatarController::class, 'thumbnail'])->name('avatar.thumb');

    // Profile — settings screens (the public profile view is registered
    // outside this authenticated group, see `profile.show` above).
    Route::get('/profile/edit', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/username', [ProfileController::class, 'updateUsername'])->name('profile.username');
    Route::put('/profile/privacy', [ProfileController::class, 'updatePrivacy'])->name('profile.privacy');
    Route::put('/profile/preferences', [ProfileController::class, 'updatePreferences'])->name('profile.preferences');
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
        // One endpoint, both verbs: the legacy screens DELETE the collection,
        // the canonical screens POST to it.
        Route::match(['post', 'delete'], '/sessions', [AccountSecurityController::class, 'revokeAllSessions'])->name('sessions.revokeAll');
        Route::post('/sessions/others', [AccountSecurityController::class, 'revokeOtherSessions'])->name('sessions.revokeOthers');
        Route::post('/google/link', [AccountSecurityController::class, 'linkGoogleRedirect'])->name('google.link');
        Route::post('/google/unlink', [AccountSecurityController::class, 'unlinkGoogle'])->name('google.unlink');
        Route::post('/phone/link', [AccountSecurityController::class, 'linkPhone'])->name('phone.link')->middleware('throttle:otp-request');
        Route::post('/phone/link/verify', [AccountSecurityController::class, 'verifyPhoneLink'])->name('phone.link.verify')->middleware('throttle:otp-verify');
        Route::post('/phone/unlink', [AccountSecurityController::class, 'unlinkPhone'])->name('phone.unlink');
        Route::post('/account/deactivate', [AccountSecurityController::class, 'deactivate'])->name('account.deactivate');
        Route::post('/account/reactivate', [AccountSecurityController::class, 'reactivate'])->name('account.reactivate');
        Route::post('/account/delete-request', [AccountSecurityController::class, 'requestDeletion'])->name('account.deletion.request');
        Route::post('/account/delete-cancel', [AccountSecurityController::class, 'cancelDeletion'])->name('account.deletion.cancel');
        Route::post('/payment-methods', [PaymentMethodsController::class, 'store'])->name('payment-methods.store');

        // Login History
        Route::get('/login-history', [AccountSecurityController::class, 'loginHistory'])->name('login-history');

        // Connected Accounts
        Route::get('/connected-accounts', [AccountSecurityController::class, 'connectedAccounts'])->name('connected-accounts');
        Route::delete('/connected-accounts/{provider}', [AccountSecurityController::class, 'disconnect'])->name('connected-accounts.disconnect');
        Route::post('/phone/verify/request', [AccountSecurityController::class, 'requestPhoneVerification'])->name('phone.verify.request');

        // Payment Methods
        // Canonical removal first (soft-remove + default promotion through
        // PaymentMethodService); the legacy literal below keeps its name for
        // URL generation but is shadowed for requests, so one DELETE endpoint
        // serves both screens with the same, non-destructive behaviour.
        Route::delete('/payment-methods/{method}', [PaymentMethodsController::class, 'destroy'])->name('payment-methods.destroy');
        Route::get('/payment-methods', [AccountSecurityController::class, 'paymentMethods'])->name('payment-methods');
        // One endpoint, both verbs: the canonical screens POST, the legacy
        // screens PUT.
        Route::match(['post', 'put'], '/payment-methods/{paymentMethod}/default', [AccountSecurityController::class, 'setDefaultPaymentMethod'])->name('payment-methods.default');
        Route::delete('/payment-methods/{paymentMethod}', [AccountSecurityController::class, 'destroyPaymentMethod'])->name('payment-methods.legacy-destroy');
    });

    // Wallet & Payments
    Route::get('/wallet', [WalletController::class, 'index'])->name('wallet.index');
    Route::get('/wallet/ledger', [WalletController::class, 'ledger'])->name('wallet.ledger');
    Route::get('/payments/methods', [PaymentController::class, 'methods'])->name('payment.methods.legacy');
    // Payment lifecycle (Phase 08): the entry-fee page is tournament/team keyed.
    Route::get('/tournaments/{tournament}/teams/{team}/pay', [PaymentController::class, 'show'])->name('payment.show');
    Route::post('/tournaments/{tournament}/teams/{team}/pay', [PaymentController::class, 'verify'])->name('payment.verify');
    Route::get('/tournaments/{tournament}/teams/{team}/pay/{payment}/pending', [PaymentController::class, 'pending'])->name('payment.pending');
    // Canonical provider-choice page (Phase 14/17) — owns `payment.methods`.
    Route::get('/tournaments/{tournament}/teams/{team}/pay/methods', [CheckoutController::class, 'methods'])->name('payment.methods');
    Route::post('/tournaments/{tournament}/teams/{team}/pay/initiate', [CheckoutController::class, 'initiate'])->middleware('throttle:payment-initiate')->name('payment.initiate.canonical');

    // Legacy single-payment URLs stay alive: they resolve the payment's own
    // tournament/team and continue to the canonical pending page.
    Route::get('/payments/{payment}', function (Payment $payment) {
        abort_if($payment->tournament === null || $payment->team === null, 404);

        return redirect()->route('payment.pending', [$payment->tournament, $payment->team, $payment]);
    })->name('payment.detail');
    Route::get('/payments/{payment}/pending', function (Payment $payment) {
        abort_if($payment->tournament === null || $payment->team === null, 404);

        return redirect()->route('payment.pending', [$payment->tournament, $payment->team, $payment]);
    })->name('payment.detail.pending');

    // Matches — disputes (Phase 07): nested so every record is validated
    // against its parents; authorization never relies on route binding alone.
    Route::get('/tournaments/{tournament}/matches/{match}/disputes/create', [DisputeController::class, 'create'])->name('matches.disputes.create');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes', [DisputeController::class, 'store'])->name('matches.disputes.store');
    Route::get('/tournaments/{tournament}/matches/{match}/disputes/{dispute}', [DisputeController::class, 'show'])->name('matches.disputes.show');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/evidence', [DisputeController::class, 'addEvidence'])->name('matches.disputes.evidence.store');
    Route::get('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/evidence/{evidence}', [DisputeController::class, 'evidence'])->name('matches.disputes.evidence.show');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/cancel', [DisputeController::class, 'cancel'])->name('matches.disputes.cancel');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/assign', [DisputeController::class, 'assign'])->name('matches.disputes.assign');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/resolve', [DisputeController::class, 'resolve'])->name('matches.disputes.resolve');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/review', [DisputeController::class, 'review'])->name('matches.disputes.review');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/reject', [DisputeController::class, 'reject'])->name('matches.disputes.reject');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/evidence/{evidence}/remove', [DisputeController::class, 'removeEvidence'])->name('matches.disputes.evidence.remove');

    // Match lifecycle (Phase 05/06): room, scores, adjustments and results.
    // Scores and adjustments feed the standings the prize distribution is
    // calculated from, so every write is authorized and recalculated
    // server-side — the client only ever submits raw inputs.
    Route::post('/tournaments/{tournament}/matches/{match}/room', [MatchController::class, 'setRoom'])->name('matches.room');
    Route::post('/tournaments/{tournament}/matches/{match}/score', [MatchController::class, 'submitScore'])->name('matches.score');
    Route::post('/tournaments/{tournament}/matches/{match}/adjustment', [MatchController::class, 'addAdjustment'])->name('matches.adjustment');
    Route::post('/tournaments/{tournament}/matches/{match}/winner', [MatchController::class, 'setWinner'])->name('matches.winner');
    Route::post('/tournaments/{tournament}/matches/{match}/dispute', [MatchController::class, 'dispute'])->name('matches.dispute');
    Route::post('/tournaments/{tournament}/matches/{match}/resolve', [MatchController::class, 'resolve'])->name('matches.resolve');

    // Tournaments - Auth actions
    Route::get('/tournaments/{tournament:slug}/register', [TeamController::class, 'showRegisterForm'])->name('teams.register.form');
    Route::get('/tournaments/{tournament}/register', [TeamController::class, 'showRegistration'])->name('teams.register');
    // Team registration. Both names exist and each keeps its own URL: the
    // legacy slug URL and the canonical id-bound URL resolve the same
    // controller action (two routes may never share one method+URI, or the
    // later one would shadow the earlier name).
    Route::post('/tournaments/{tournament:slug}/register', [TeamController::class, 'register'])->name('teams.register')->middleware('throttle:tournament-register');
    Route::post('/tournaments/{tournament}/register', [TeamController::class, 'register'])->name('teams.store')->middleware('throttle:tournament-register');

    // Team roster + participation (Phase 03/04): every record is resolved
    // against its tournament so a stray id can never mutate another event.
    Route::get('/tournaments/{tournament}/teams/{team}', [TeamController::class, 'show'])->name('teams.show');
    Route::put('/tournaments/{tournament}/teams/{team}/profile', [TeamController::class, 'updateProfile'])->name('teams.update');
    Route::post('/tournaments/{tournament}/teams/{team}/members', [TeamController::class, 'addMember'])->name('teams.members.store');
    Route::post('/tournaments/{tournament}/teams/{team}/members/{member}/remove', [TeamController::class, 'removeMember'])->name('teams.members.remove');
    Route::post('/tournaments/{tournament}/teams/{team}/withdraw', [TeamController::class, 'withdraw'])->name('teams.withdraw');
    Route::post('/tournaments/{tournament}/teams/{team}/check-in', [TeamController::class, 'checkIn'])->name('teams.checkin');

    // Legacy team URL stays alive: it resolves the team's own tournament and
    // continues to the canonical page.
    Route::get('/teams/{team}', function (Team $team) {
        abort_if($team->tournament === null, 404);

        return redirect()->route('teams.show', [$team->tournament, $team]);
    })->name('teams.detail');

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.readAll');

    // Support & Disputes
    Route::get('/support', [SupportController::class, 'index'])->name('support.index');
    Route::get('/support/create', [SupportController::class, 'create'])->name('support.create');
    Route::post('/support', [SupportController::class, 'store'])->name('support.store')->middleware('throttle:support');
    Route::get('/support/{ticket}', [SupportController::class, 'show'])->name('support.show');

    // Legacy dispute URLs stay alive as resolvers onto the canonical,
    // match-scoped routes (a dispute can only exist against a match).
    Route::get('/disputes/create/{match}', function (GameMatch $match) {
        abort_if($match->tournament === null, 404);

        return redirect()->route('matches.disputes.create', [$match->tournament, $match]);
    })->name('disputes.create');
    Route::post('/disputes', function () {
        return back()->with('error', 'Open a dispute from the match page so it is linked to the match.');
    })->name('disputes.store')->middleware('throttle:dispute');
    Route::get('/disputes/{dispute}', function (Dispute $dispute) {
        return redirect()->route('matches.disputes.show', [$dispute->tournament_id, $dispute->match_id, $dispute->id]);
    })->name('disputes.show');

    // Live Poll (for live tournament updates)
    Route::get('/live/poll', [LiveController::class, 'poll'])->name('live.poll');
});

// --- Staff analytics (admin/moderator only; literal URIs registered before ---
// --- the admin group so they win route matching) ----------------------------
Route::middleware(['auth', 'active', 'staff'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/analytics/disputes', [AnalyticsController::class, 'disputes'])->name('analytics.disputes');
    Route::get('/analytics/support', [AnalyticsController::class, 'support'])->name('analytics.support');
});

// --- Staff support queue (admins + moderators; organizers stay scoped) ---
Route::middleware(['auth', 'active', 'staff'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/support/tickets', [AdminSupportController::class, 'index'])->name('support.index');
    Route::get('/support/tickets/export', [AdminSupportController::class, 'export'])->name('support.export');
    Route::get('/support/tickets/{ticket}', [AdminSupportController::class, 'show'])->name('support.show');
    Route::post('/support/{ticket}/assign', [AdminSupportController::class, 'assign'])->name('support.assign');
    Route::post('/support/{ticket}/status', [AdminSupportController::class, 'status'])->name('support.status');
    Route::post('/support/{ticket}/note', [AdminSupportController::class, 'internalNote'])->name('support.note');
    Route::post('/support/{ticket}/reply', [AdminSupportController::class, 'reply'])->name('support.reply');
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
    Route::get('/tournaments/{tournament}/scoring', [ScoringRuleController::class, 'show'])->name('tournaments.scoring');

    // Accounts
    Route::get('/accounts', [AdminAccountController::class, 'index'])->name('accounts.index');
    Route::get('/accounts/{user}', [AdminAccountController::class, 'show'])->name('accounts.show');
    Route::post('/accounts/{user}/sessions/revoke', [AdminAccountController::class, 'revokeSessions'])->name('accounts.sessions.revoke');
    Route::post('/accounts/{user}/deactivate', [AdminAccountController::class, 'deactivate'])->name('accounts.deactivate');
    Route::post('/accounts/{user}/reactivate', [AdminAccountController::class, 'reactivate'])->name('accounts.reactivate');
    Route::post('/accounts/{user}/delete', [AdminAccountController::class, 'delete'])->name('accounts.delete');

    // Financial
    Route::get('/wallet', [AdminController::class, 'wallet'])->name('wallet');
    Route::get('/payouts', [AdminController::class, 'payouts'])->name('payouts');
    Route::get('/settlements', [SettlementController::class, 'index'])->name('settlements');
    Route::get('/settlements/{tournament}', [SettlementController::class, 'show'])->name('settlements.show');
    Route::get('/tournaments/{tournament}/settlement', [SettlementController::class, 'showByTournament'])->name('tournaments.settlement');

    // Support & Moderation
    Route::get('/support', [AdminSupportController::class, 'index'])->name('support');
    Route::get('/support/{ticket}', [AdminSupportController::class, 'show'])->name('support.ticket');
    Route::get('/moderation', [ModerationController::class, 'index'])->name('moderation');
    Route::get('/security', [SecurityController::class, 'dashboard'])->name('security.dashboard');
    Route::get('/security/events', [SecurityController::class, 'events'])->name('security.events');
    Route::get('/security/incidents', [SecurityController::class, 'incidents'])->name('security.incidents');
    Route::get('/security/users', [SecurityController::class, 'users'])->name('security.users');
    Route::get('/security/users/{user}', [SecurityController::class, 'user'])->name('security.user');

    // Analytics
    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
    Route::get('/analytics/tournaments', [AnalyticsController::class, 'tournaments'])->name('analytics.tournaments');
    Route::get('/analytics/tournament', [AnalyticsController::class, 'tournament'])->name('analytics.tournament');
    Route::get('/analytics/financial', [AnalyticsController::class, 'financial'])->name('analytics.financial');
    Route::get('/analytics/security', [AnalyticsController::class, 'security'])->name('analytics.security');

    // Audit & Ops
    Route::get('/audit', [AuditController::class, 'index'])->name('audit');
    Route::get('/ops', [OpsController::class, 'dashboard'])->name('ops.dashboard');
});

/*
|--------------------------------------------------------------------------
| Phase 13-16 Named Routes (registered against existing controller actions)
|--------------------------------------------------------------------------
| The tests and app code reference these named routes. Each one below is
| wired to a controller method that already exists in this codebase; no new
| business logic is introduced here — only the route registration.
*/

// --- Guest: OAuth (Google), phone-login OTP, and the signed verify link ---
Route::middleware('guest')->group(function () {

    Route::get('/login/phone', [AuthController::class, 'showPhoneLogin'])->name('phone.login');
    Route::get('/phone/verify', [AuthController::class, 'showPhoneVerify'])->name('phone.verify');
    Route::post('/phone/request', [AuthController::class, 'requestPhoneOtp'])->name('phone.request')->middleware('throttle:otp-request');
    Route::post('/phone/verify', [AuthController::class, 'verifyPhoneLogin'])->name('phone.login.verify')->middleware('throttle:otp-verify');
});

// Signed email-verification link (works for guest and authenticated).
Route::get('/verify-email/{id}/{hash}', [AuthController::class, 'verifyEmail'])->name('verification.verify');

// Payment-provider webhooks (signature verified inside the handler).
Route::post('/webhooks/payments/{provider}', [WebhookController::class, 'handle'])->name('webhooks.payments');
// Hosted gateway payer-return (bKash/Nagad redirect with GET, SSLCommerz posts back).
// The signed `state` token — not the query string — authenticates the return.
Route::match(['get', 'post'], '/payments/callback/{provider}', [PaymentGatewayCallbackController::class, 'confirm'])->name('payments.callback');

// --- Authenticated: account, security, moderation, live, tournament actions ---
Route::middleware(['auth', 'active'])->group(function () {
    // Email verification (notice + resend)
    Route::get('/verify-email', [AuthController::class, 'showVerifyEmail'])->name('verification.notice');
    // The resend endpoint carries two names (both used by existing callers):
    // `verification.resend` is the POST that actually resends the link, and
    // `verification.send` is the same URL for the verify-email form. Two
    // routes may never share one method + URI (the later one would replace
    // the earlier), so the send name is registered on the same URI as a GET
    // that simply returns to the notice page.
    Route::post('/verify-email/resend', [AuthController::class, 'resendVerification'])->name('verification.resend');
    Route::get('/verify-email/resend', function () {
        return redirect()->route('verification.notice');
    })->name('verification.send');

    // Account live feed + notification badge
    Route::get('/account/live', [AccountLiveController::class, 'index'])->name('account.live');
    Route::get('/notifications/unread', [LiveController::class, 'unreadCount'])->name('notifications.unread');

    // Moderation
    Route::get('/moderation', [ModerationController::class, 'index'])->name('moderation.index');
    Route::get('/moderation/security', [ModerationController::class, 'security'])->name('moderation.security');

    // Security (anti-cheat incidents + identity verification)
    Route::post('/security/identity', [SecurityController::class, 'requestVerification'])->name('security.identity.request');
    Route::get('/security/incidents', [SecurityController::class, 'incidents'])->name('security.incidents.index');
    Route::post('/security/incidents', [SecurityController::class, 'openIncident'])->name('security.incidents.open');
    Route::post('/security/incidents/{incident}/resolve', [SecurityController::class, 'resolveIncident'])->name('security.incidents.resolve');
    Route::post('/security/incidents/{incident}/review', [SecurityController::class, 'reviewIncident'])->name('security.incidents.review');

    // Password change — one endpoint, two names: `settings.password` is the
    // canonical Phase-14 action (ProfileController::changePassword) and the
    // pre-Phase14 screens post the same URL to AccountSecurityController.
    Route::post('/settings/password', [ProfileController::class, 'changePassword'])->name('settings.password');
    Route::post('/settings/password/legacy', [AccountSecurityController::class, 'updatePassword'])->name('settings.password.legacy');

    // Settings — security settings, sessions, connected accounts, lifecycle
    // (Phase 14 canonical names, same controller the settings screens use).
    Route::post('/settings/sessions/others', [AccountSecurityController::class, 'revokeOtherSessions'])->name('settings.sessions.revokeOthers');
    Route::match(['post', 'delete'], '/settings/sessions', [AccountSecurityController::class, 'revokeAllSessions'])->name('settings.sessions.revokeAll');
    Route::get('/settings/google/link', [AccountSecurityController::class, 'linkGoogleRedirect'])->name('settings.google.link');
    Route::post('/settings/google/unlink', [AccountSecurityController::class, 'unlinkGoogle'])->name('settings.google.unlink');
    Route::post('/settings/phone/link', [AccountSecurityController::class, 'linkPhone'])->name('settings.phone.link')->middleware('throttle:otp-request');
    Route::post('/settings/phone/link/verify', [AccountSecurityController::class, 'verifyPhoneLink'])->name('settings.phone.link.verify')->middleware('throttle:otp-verify');
    Route::post('/settings/phone/unlink', [AccountSecurityController::class, 'unlinkPhone'])->name('settings.phone.unlink');
    Route::post('/settings/account/deactivate', [AccountSecurityController::class, 'deactivate'])->name('settings.deactivate');
    Route::post('/settings/account/reactivate', [AccountSecurityController::class, 'reactivate'])->name('settings.reactivate');
    Route::post('/settings/account/delete-request', [AccountSecurityController::class, 'requestDeletion'])->name('settings.deletion.request');
    Route::post('/settings/account/delete-cancel', [AccountSecurityController::class, 'cancelDeletion'])->name('settings.deletion.cancel');
    Route::post('/settings/payment-methods', [PaymentMethodsController::class, 'store'])->name('settings.payment-methods.store');
    Route::match(['post', 'put'], '/settings/payment-methods/{method}/default', [PaymentMethodsController::class, 'setDefault'])->name('settings.payment-methods.default');

    // Support tickets (owner-scoped; internal notes never exposed)
    Route::get('/support/tickets/{ticket}', [SupportController::class, 'show'])->name('support.tickets.show');
    Route::get('/support/tickets/{ticket}/messages', [SupportController::class, 'messages'])->name('support.tickets.messages');
    Route::post('/support/{ticket}/reply', [SupportController::class, 'reply'])->name('support.tickets.reply');
    Route::post('/support/{ticket}/close', [SupportController::class, 'close'])->name('support.tickets.close');
    Route::post('/support/{ticket}/reopen', [SupportController::class, 'reopen'])->name('support.tickets.reopen');

    // Tournament organizer actions + live/analytics/scoring
    Route::get('/organizer/tournaments/create', [TournamentController::class, 'create'])->name('tournaments.create');
    Route::post('/organizer/tournaments', [TournamentController::class, 'store'])->name('organizer.tournaments.store');
    Route::post('/tournaments', [TournamentController::class, 'store'])->name('tournaments.store');
    Route::get('/tournaments/{tournament}/edit', [TournamentController::class, 'edit'])->name('tournaments.edit');
    Route::put('/tournaments/{tournament}', [TournamentController::class, 'update'])->name('tournaments.update');

    // Tournament lifecycle (Phase 02/04/05): every state change runs through
    // TournamentLifecycleService, which asserts the legal transition and
    // records the audit trail. No status is ever set from request input.
    Route::post('/tournaments/{tournament}/publish', [TournamentController::class, 'publish'])->name('tournaments.publish');
    Route::post('/tournaments/{tournament}/close', [TournamentController::class, 'closeRegistration'])->name('tournaments.close');
    Route::post('/tournaments/{tournament}/bracket', [TournamentController::class, 'start'])->name('tournaments.bracket');
    Route::post('/tournaments/{tournament}/complete', [TournamentController::class, 'complete'])->name('tournaments.complete');
    Route::post('/tournaments/{tournament}/cancel', [TournamentController::class, 'cancel'])->name('tournaments.cancel');
    Route::post('/tournaments/{tournament}/no-shows', [TournamentController::class, 'markNoShows'])->name('tournaments.noshows');
    Route::post('/tournaments/{tournament}/waitlist/promote', [TournamentController::class, 'promoteWaitlisted'])->name('tournaments.waitlist.promote');
    Route::get('/tournaments/{tournament}/analytics', [AnalyticsController::class, 'tournament'])->name('tournaments.analytics');
    Route::get('/tournaments/{tournament}/scoring', [ScoringRuleController::class, 'show'])->name('tournaments.scoring.show');
    Route::post('/tournaments/{tournament}/scoring', [ScoringRuleController::class, 'store'])->name('tournaments.scoring.store');
    Route::post('/tournaments/{tournament}/scoring/{rule}/activate', [ScoringRuleController::class, 'activate'])->name('tournaments.scoring.activate');

    // Checkout / payment initiation
    Route::post('/checkout/{tournament}/{team}', [CheckoutController::class, 'initiate'])->name('payment.initiate');
});

// --- Admin: named routes backed by existing admin controller methods ---
Route::middleware(['auth', 'active', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    // Audit
    Route::get('/audit-index', [AuditController::class, 'index'])->name('audit.index');
    Route::get('/audit/export', [AuditController::class, 'export'])->name('audit.export');

    // Infrastructure operations (Phase 16 — admin only)
    Route::get('/ops', [OpsController::class, 'dashboard'])->name('ops.dashboard');
    Route::get('/ops/health', [OpsController::class, 'health'])->name('ops.health');
    Route::get('/ops/failed-jobs', [OpsController::class, 'failedJobs'])->name('ops.failed_jobs');
    Route::post('/ops/failed-jobs/{id}/retry', [OpsController::class, 'retryFailedJob'])->name('ops.failed_jobs.retry');
    Route::post('/ops/failed-jobs/retry-all', [OpsController::class, 'retryAllFailed'])->name('ops.failed_jobs.retry_all');
    Route::post('/ops/failed-jobs/{id}/delete', [OpsController::class, 'deleteFailedJob'])->name('ops.failed_jobs.delete');
    Route::post('/ops/cache/flush', [OpsController::class, 'flushCache'])->name('ops.cache.flush');
    Route::post('/ops/backup', [OpsController::class, 'backup'])->name('ops.backup');
    Route::post('/ops/backup/verify', [OpsController::class, 'verifyBackup'])->name('ops.backup.verify');

    // Analytics CSV export (admin-only)
    Route::get('/analytics/export', [AnalyticsController::class, 'exportTournaments'])->name('analytics.export');

    // Payments / payouts (index aliases + payout lifecycle actions)
    Route::get('/payments', [AdminController::class, 'payments'])->name('payments.index');
    Route::get('/payouts', [PayoutController::class, 'index'])->name('payouts.index');
    Route::post('/payouts/{payout}/approve', [PayoutController::class, 'approve'])->name('payouts.approve');
    Route::post('/payouts/{payout}/process', [PayoutController::class, 'process'])->name('payouts.process');
    Route::post('/payouts/{payout}/process-override', [PayoutController::class, 'processOverride'])->name('payouts.process_override');
    Route::post('/payouts/{payout}/complete', [PayoutController::class, 'complete'])->name('payouts.complete');
    Route::post('/payouts/{payout}/fail', [PayoutController::class, 'fail'])->name('payouts.fail');
    Route::post('/payouts/{payout}/cancel', [PayoutController::class, 'cancel'])->name('payouts.cancel');

    // Settlements index alias
    Route::get('/settlements', [SettlementController::class, 'index'])->name('settlements.index');

    // Payments — manual verification / failure / refund (Phase 08 lifecycle)
    Route::post('/payments/{payment}/verify', [AdminController::class, 'verifyPayment'])->name('payments.verify');
    Route::post('/payments/{payment}/fail', [AdminController::class, 'failPayment'])->name('payments.fail');
    Route::post('/payments/{payment}/refund', [AdminController::class, 'refundPayment'])->name('payments.refund');

    // Wallets + ledger — per-user wallet page and manual adjustments
    Route::get('/users/{user}/wallet', [AdminController::class, 'wallet'])->name('wallet.show');
    Route::post('/users/{user}/wallet/credit', [AdminController::class, 'creditWallet'])->name('wallet.credit');
    Route::post('/users/{user}/wallet/debit', [AdminController::class, 'debitWallet'])->name('wallet.debit');

    // Moderation roles (Phase 07)
    Route::post('/users/moderators', [AdminController::class, 'makeModerator'])->name('users.moderate');
    Route::post('/users/{user}/remove-moderator', [AdminController::class, 'removeModerator'])->name('users.unmoderate');

    // Prize distribution lifecycle (Phase 09) — object-level authorization in
    // the controller via PrizeDistributionPolicy.
    Route::post('/tournaments/{tournament}/settlement/prizes', [SettlementController::class, 'storePrizeTiers'])->name('settlements.prizes');
    Route::post('/tournaments/{tournament}/settlement/calculate', [SettlementController::class, 'calculate'])->name('settlements.calculate');
    Route::post('/tournaments/{tournament}/settlement/approve', [SettlementController::class, 'approve'])->name('settlements.approve');
    Route::post('/tournaments/{tournament}/settlement/process', [SettlementController::class, 'process'])->name('settlements.process');
    Route::post('/tournaments/{tournament}/settlement/cancel', [SettlementController::class, 'cancel'])->name('settlements.cancel');
    Route::post('/tournaments/{tournament}/settlement/adjust', [SettlementController::class, 'adjust'])->name('settlements.adjust');

    // Support tickets (admin view)

    // Security enforcement (restrict / lift / verify identity)
    Route::post('/security/restrict/{user}', [SecurityController::class, 'restrict'])->name('security.restrict');
    Route::post('/security/lift/{restriction}', [SecurityController::class, 'liftRestriction'])->name('security.lift');
    Route::post('/security/verify/{user}', [SecurityController::class, 'verifyIdentity'])->name('security.verify');
    Route::post('/security/reject/{user}', [SecurityController::class, 'rejectIdentity'])->name('security.reject');
});

// --- Google OAuth entry points (guest sign-in *and* signed-in linking) ---
Route::middleware('web')->group(function () {
    Route::get('/auth/google', [AuthController::class, 'redirectToGoogle'])->name('auth.google.redirect');
    Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback'])->name('auth.google.callback');
    Route::get('/oauth/google', [AuthController::class, 'redirectToGoogle'])->name('google.redirect');
    Route::get('/oauth/google/callback', [AuthController::class, 'handleGoogleCallback'])->name('google.callback');
});

// Fallback - 404
Route::fallback(function () {
    return response()->view('errors.404', [], 404);
});
