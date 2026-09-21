<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use App\Models\PaymentMethod;
use App\Policies\PaymentMethodPolicy;
use App\Models\User;
use App\Policies\UserPolicy;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Phase 14 — phone OTP provider. The log-based provider is the
        // default (dev/test); the SMS gateway provider is selected via
        // services.phone_otp.provider = 'sms'. Tests bind their own fake
        // against the interface, which takes precedence over this binding.
        $this->app->bind(\App\Contracts\PhoneOtpProviderInterface::class, function () {
            return config('services.phone_otp.provider', 'log') === 'sms'
                ? new \App\Gateways\SmsGatewayPhoneOtpProvider()
                : new \App\Gateways\LogPhoneOtpProvider();
        });

        // Phase 14 — Google Sign-In provider (Socialite in production;
        // tests bind a fake against the interface).
        $this->app->bind(\App\Contracts\GoogleOAuthProviderInterface::class, \App\Gateways\SocialiteGoogleProvider::class);
    }

    public function boot(): void
    {
        Gate::policy(PaymentMethod::class, PaymentMethodPolicy::class);
        Gate::policy(User::class, UserPolicy::class);

        // Phase 16 — Health & readiness probes rate limiting
        // Must be defined for health.php routes, never leaks secrets
        RateLimiter::for('health', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip())->response(function () {
                return response()->json(['status' => 'rate_limited', 'message' => 'Too many health checks'], 429);
            });
        });

        // Web auth rate limiters — preserve production behavior, CSRF protected
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip().'|'.$request->input('email'))->response(function () {
                return response()->json(['message' => 'Too many login attempts'], 429);
            });
        });

        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(3)->by($request->ip())->response(function () {
                return response()->json(['message' => 'Too many registration attempts'], 429);
            });
        });

        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perMinute(3)->by($request->ip().'|'.$request->input('email'));
        });

        RateLimiter::for('otp-request', function (Request $request) {
            return Limit::perMinute(1)->by($request->ip().'|'.$request->input('phone'));
        });

        RateLimiter::for('otp-verify', function (Request $request) {
            return Limit::perMinutes(5, 5)->by($request->ip().'|'.$request->input('phone'));
        });

        RateLimiter::for('tournament-register', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('support', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('dispute', function (Request $request) {
            return Limit::perMinute(3)->by($request->user()?->id ?: $request->ip());
        });

        // API rate limiters — Phase 15 production hardening
        RateLimiter::for('api', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();
            $limit = config('api.rate_limits.api', 120);
            return Limit::perMinute($limit)->by($key);
        });

        RateLimiter::for('api_anon', function (Request $request) {
            $limit = config('api.rate_limits.api_anon', 60);
            return Limit::perMinute($limit)->by($request->ip());
        });

        RateLimiter::for('api_register', function (Request $request) {
            return Limit::perMinute(3)->by($request->ip());
        });

        RateLimiter::for('api_login', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        RateLimiter::for('api_otp_request', function (Request $request) {
            return Limit::perMinute(1)->by($request->ip());
        });

        RateLimiter::for('api_otp_verify', function (Request $request) {
            return Limit::perMinutes(5, 5)->by($request->ip());
        });

        RateLimiter::for('api_score', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('api_payment', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('api_support', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('api_webhook', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        RateLimiter::for('api_token_issue', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        // Gameberry specific rate limiters
        RateLimiter::for('gameberry', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('gameberry_spin', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('gameberry_private_table', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });
    }
}
