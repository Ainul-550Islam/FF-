<?php

namespace App\Providers;

use App\Contracts\ErrorReporterInterface;
use App\Contracts\GoogleIdTokenVerifierInterface;
use App\Contracts\GoogleOAuthProviderInterface;
use App\Contracts\MetricsInterface;
use App\Contracts\PhoneOtpProviderInterface;
use App\Gateways\GoogleTokenInfoIdVerifier;
use App\Gateways\LogPhoneOtpProvider;
use App\Gateways\SmsGatewayPhoneOtpProvider;
use App\Gateways\SocialiteGoogleProvider;
use App\Models\PersonalAccessToken;
use App\Services\NotificationService;
use App\Support\ErrorReporting\ErrorReporterManager;
use App\Support\Metrics;
use App\Support\Metrics\MetricsManager;
use App\Support\Seo;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Phase 14 — honest provider wiring. The SMS gateway is used only when
        // configured; otherwise the dev/test log provider delivers codes.
        $this->app->singleton(PhoneOtpProviderInterface::class, function ($app) {
            if (! empty(env('SMS_GATEWAY_ENDPOINT')) && ! empty(env('SMS_GATEWAY_API_KEY'))) {
                return new SmsGatewayPhoneOtpProvider;
            }

            return new LogPhoneOtpProvider;
        });

        $this->app->singleton(GoogleOAuthProviderInterface::class, fn ($app) => new SocialiteGoogleProvider);

        // Phase 15 — Google id_token verification for the mobile/API login.
        // Tests bind a deterministic fake; production verifies server-side
        // against Google.
        $this->app->singleton(GoogleIdTokenVerifierInterface::class, fn ($app) => new GoogleTokenInfoIdVerifier);

        // Phase 16 — observability seams. The manager classes resolve the
        // configured backend lazily so a broken metrics/error config can
        // never prevent the application from booting.
        $this->app->singleton(MetricsInterface::class, fn ($app) => (new MetricsManager)->driver());
        $this->app->singleton(ErrorReporterInterface::class, fn ($app) => (new ErrorReporterManager)->driver());

        // Phase 17 — request-scoped SEO metadata manager. Public pages opt in
        // to indexing; everything else stays noindex by default.
        $this->app->singleton(Seo::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Phase 15 — use the app's PersonalAccessToken subclass so the
        // api_client_id link (grouped revocation) is available on tokens.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Expose the authenticated user's unread notification count to the
        // shared layout (Phase 11). Guests always see zero.
        View::composer('layouts.app', function ($view) {
            $user = auth()->user();

            $view->with('unreadNotifications', $user !== null
                ? app(NotificationService::class)->unreadCount($user)
                : 0);
            $view->with('seo', app(Seo::class)->toArray());
        });

        $this->registerQueueObservability();
        $this->registerRateLimiters();
    }

    /**
     * Phase 16 — queue metrics + failure alerting. Queue events fire inside
     * the worker process; the listeners only record safe counters and log a
     * redacted failure line — a failing listener can never affect the job.
     */
    protected function registerQueueObservability(): void
    {
        Event::listen(JobProcessed::class, function (JobProcessed $event) {
            Metrics::increment('queue.jobs_processed', 1, [
                'connection' => (string) ($event->connectionName ?? 'unknown'),
            ]);
        });

        Event::listen(JobFailed::class, function (JobFailed $event) {
            Metrics::increment('queue.jobs_failed', 1, [
                'connection' => (string) ($event->connectionName ?? 'unknown'),
            ]);

            Log::channel('queue')->error('Queue job failed', [
                'job' => $event->job->resolveName() ?? 'unknown',
                'exception' => $event->exception::class,
            ]);
        });
    }

    /**
     * Phase 14 — named rate limiters for auth, OTP, recovery and payments.
     */
    protected function registerRateLimiters(): void
    {
        // Login: 5 attempts per minute per email+IP, then 1 per minute.
        RateLimiter::for('login', function (Request $request) {
            $key = 'login:'.strtolower((string) $request->input('email')).':'.$request->ip();

            return [
                Limit::perMinute(5)->by($key),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        // Registration: 3 per hour per IP.
        RateLimiter::for('register', function (Request $request) {
            return Limit::perHour(3)->by($request->ip());
        });

        // OTP request: hard per-phone + per-IP limits (SMS abuse control).
        RateLimiter::for('otp-request', function (Request $request) {
            $phone = preg_replace('/\D/', '', (string) $request->input('phone')) ?? '';

            return [
                Limit::perMinute(1)->by('otp-request:'.$phone),
                Limit::perHour(5)->by('otp-request:'.$phone),
                Limit::perHour(10)->by('otp-request:ip:'.$request->ip()),
            ];
        });

        // OTP verify: 5 per 5 minutes per phone.
        RateLimiter::for('otp-verify', function (Request $request) {
            $phone = preg_replace('/\D/', '', (string) $request->input('phone')) ?? '';

            return Limit::perMinutes(5, 5)->by('otp-verify:'.$phone);
        });

        // Password reset requests: 3 per hour per email+IP.
        RateLimiter::for('password-reset', function (Request $request) {
            $key = 'password-reset:'.strtolower((string) $request->input('email')).':'.$request->ip();

            return Limit::perHour(3)->by($key);
        });

        // Google callback: generic abuse ceiling per IP.
        RateLimiter::for('google-callback', function (Request $request) {
            return Limit::perMinute(20)->by($request->ip());
        });

        // Account linking (google/phone): 5 per minute per user.
        RateLimiter::for('account-link', function (Request $request) {
            return Limit::perMinute(5)->by('account-link:user:'.($request->user()?->id ?? $request->ip()));
        });

        // Tournament registration: 10 per hour per user (one-shot intent).
        RateLimiter::for('tournament-register', function (Request $request) {
            return Limit::perHour(10)->by('tournament-register:user:'.($request->user()?->id ?? $request->ip()));
        });

        // Dispute filing/replying: 10 per hour per user.
        RateLimiter::for('dispute', function (Request $request) {
            return Limit::perHour(10)->by('dispute:user:'.($request->user()?->id ?? $request->ip()));
        });

        // Support ticket creation/replies: 20 per hour per user.
        RateLimiter::for('support', function (Request $request) {
            return Limit::perHour(20)->by('support:user:'.($request->user()?->id ?? $request->ip()));
        });

        // Payment initiation: 5 per minute per user.
        RateLimiter::for('payment-initiate', function (Request $request) {
            return Limit::perMinute(5)->by('payment-initiate:user:'.($request->user()?->id ?? $request->ip()));
        });

        // Verification email resends: 3 per hour per user.
        RateLimiter::for('verification-resend', function (Request $request) {
            return Limit::perHour(3)->by('verification-resend:user:'.($request->user()?->id ?? $request->ip()));
        });

        $this->registerApiRateLimiters();
    }

    /**
     * Phase 15 — named, route-level API limiters. Keys are user/token-aware
     * wherever a user exists (never IP-only for authenticated operations),
     * with tighter windows for auth, OTP, scoring, payment and support.
     */
    protected function registerApiRateLimiters(): void
    {
        // General authenticated API ceiling: 120/min per token.
        RateLimiter::for('api', function (Request $request) {
            $user = $request->user();

            return Limit::perMinute((int) config('api.rate_limits.api', 120))
                ->by('api:user:'.($user?->id ?? $request->ip()));
        });

        // Anonymous discovery: 60/min per IP.
        RateLimiter::for('api_anon', function (Request $request) {
            return Limit::perMinute((int) config('api.rate_limits.api_anon', 60))
                ->by('api-anon:'.$request->ip());
        });

        // Token issuance: 5/min per user.
        RateLimiter::for('api_token_issue', function (Request $request) {
            return Limit::perMinute(5)
                ->by('api-token-issue:user:'.($request->user()?->id ?? $request->ip()));
        });

        // API login (email/password + google): 5/min per identifier + IP.
        RateLimiter::for('api_login', function (Request $request) {
            $identifier = strtolower((string) ($request->input('email') ?? $request->input('id_token') ?? ''));

            return [
                Limit::perMinute(5)->by('api-login:'.$identifier),
                Limit::perMinute(20)->by('api-login:ip:'.$request->ip()),
            ];
        });

        // API registration: 3/hour per IP (mirrors the web 'register' limiter).
        RateLimiter::for('api_register', function (Request $request) {
            return Limit::perHour(3)->by('api-register:'.$request->ip());
        });

        // API OTP request: 1/min per phone + 5/hour per phone + 10/hour per IP.
        RateLimiter::for('api_otp_request', function (Request $request) {
            $phone = preg_replace('/\D/', '', (string) $request->input('phone')) ?? '';

            return [
                Limit::perMinute(1)->by('api-otp-request:'.$phone),
                Limit::perHour(5)->by('api-otp-request:'.$phone),
                Limit::perHour(10)->by('api-otp-request:ip:'.$request->ip()),
            ];
        });

        // API OTP verify: 5/5min per phone.
        RateLimiter::for('api_otp_verify', function (Request $request) {
            $phone = preg_replace('/\D/', '', (string) $request->input('phone')) ?? '';

            return Limit::perMinutes(5, 5)->by('api-otp-verify:'.$phone);
        });

        // API score submission: 10/min per user.
        RateLimiter::for('api_score', function (Request $request) {
            return Limit::perMinute((int) config('api.rate_limits.api_score', 10))
                ->by('api-score:user:'.($request->user()?->id ?? $request->ip()));
        });

        // API payment creation: 5/min per user.
        RateLimiter::for('api_payment', function (Request $request) {
            return Limit::perMinute((int) config('api.rate_limits.api_payment', 5))
                ->by('api-payment:user:'.($request->user()?->id ?? $request->ip()));
        });

        // API support writes: 10/min per user.
        RateLimiter::for('api_support', function (Request $request) {
            return Limit::perMinute((int) config('api.rate_limits.api_support', 10))
                ->by('api-support:user:'.($request->user()?->id ?? $request->ip()));
        });

        // Inbound provider webhooks: 60/min per IP.
        RateLimiter::for('api_webhook', function (Request $request) {
            return Limit::perMinute((int) config('api.rate_limits.api_webhook', 60))
                ->by('api-webhook:'.$request->ip());
        });

        // Phase 16 — health probes: generous ceiling (300/min per IP) so
        // orchestrators/load balancers can poll freely without being blocked.
        RateLimiter::for('health', function (Request $request) {
            return Limit::perMinute(300)->by('health:'.$request->ip());
        });
    }
}