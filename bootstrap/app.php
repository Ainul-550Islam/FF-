<?php

use App\Contracts\ErrorReporterInterface;
use App\Exceptions\ApiExceptionHandler;
use App\Http\Middleware\AssignAuditRequestId;
use App\Http\Middleware\CaptureMarketingAttribution;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureBearerToken;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\EnsureNumberedSimulationSafe;
use App\Http\Middleware\EnsureRecentPasswordConfirmation;
use App\Http\Middleware\EnsureServiceHmac;
use App\Http\Middleware\EnsureTokenIsValid;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsStaff;
use App\Http\Middleware\HttpMetrics;
use App\Http\Middleware\NormalizeGameberryEnvelope;
use App\Http\Middleware\SecurityHeaders;
use App\Support\RequestContext;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Phase 16 — health/readiness probes are loaded without the
            // web/api middleware groups (no session, no CSRF) and stay
            // reachable during maintenance mode.
            require base_path('routes/health.php');

            // Gameberry routes - LudoStar style features - 250+ dice, 6-step league Bronze Titan, private tables code/link, gold at stake etc
            if (file_exists(base_path('routes/gameberry.php'))) {
                require base_path('routes/gameberry.php');
            }
            if (file_exists(base_path('routes/api_gameberry.php'))) {
                require base_path('routes/api_gameberry.php');
            }

            // GAP-10 A3 (Option B) — the numbered simulation families
            // (core / final* / stats) are development-only template clones and
            // are registered ONLY when the feature flag is on AND the app runs
            // in local/testing. In production the route files are never
            // required, so the endpoints do not exist at all; the
            // `numbered.simulation` middleware is the second line of defence.
            if (EnsureNumberedSimulationSafe::routesMayBeRegistered()) {
                if (file_exists(base_path('routes/gameberry_numbered.php'))) {
                    require base_path('routes/gameberry_numbered.php');
                }
                if (file_exists(base_path('routes/api_gameberry_numbered.php'))) {
                    require base_path('routes/api_gameberry_numbered.php');
                }
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // GAP-10 (finding F-25) — trusted proxies are applied by
        // App\Providers\TrustedProxyServiceProvider, NOT here: this callback
        // runs while the HTTP kernel is being resolved, before the
        // configuration is loaded, so `config()` is not available yet (calling
        // it fails the boot). See that provider's docblock for the details.

        // Phase 16 — global hardening middleware (runs for web AND api).
        $middleware->append([
            AssignAuditRequestId::class,
            SecurityHeaders::class,
            HttpMetrics::class,
        ]);

        // Phase 16 — keep health probes available during maintenance mode.
        $middleware->preventRequestsDuringMaintenance(except: [
            '/up',
            '/health',
            '/health/live',
            '/health/ready',
        ]);

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'staff' => EnsureUserIsStaff::class,
            'active' => EnsureActiveAccount::class,
            'feature' => EnsureFeatureEnabled::class,

            // GAP-10 A1 — numbered simulation guard (core / final* / stats).
            'numbered.simulation' => EnsureNumberedSimulationSafe::class,

            // GAP-10 A4 — step-up (recent password confirmation) for admin
            // money actions.
            'password.recent' => EnsureRecentPasswordConfirmation::class,

            // Phase 15 — API middleware.
            'bearer' => EnsureBearerToken::class,
            'api.token' => EnsureTokenIsValid::class,
            'idempotency' => EnsureIdempotency::class,
            // P2 (2026-10-07): dedicated HMAC guard for Go/Rust service
            // callbacks — services never authenticate as users.
            'service.hmac' => EnsureServiceHmac::class,
            // AUDIT FIX-15 — translate the legacy Gameberry success/error
            // envelope to the standard ApiResponse contract at the boundary.
            'gameberry.envelope' => NormalizeGameberryEnvelope::class,
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);

        // P2 (2026-10-07) — the `bearer` middleware MUST run before
        // `auth:sanctum`. Laravel sorts route middleware by its priority
        // list, where the auth contract outranks every custom middleware, so
        // the route order (`bearer` first) was silently flipped: sanctum's
        // guard rejected over-lifetime tokens with the generic
        // `unauthenticated` envelope and the bearer's specific `token_expired`
        // / `invalid_token` shapes were unreachable dead code. The bearer
        // resolves the user itself (see its docblock), so running first is
        // safe — sanctum still re-validates afterwards.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EnsureBearerToken::class);

        // Phase 14 — block deactivated/deleted accounts (with a reactivate
        // escape hatch on the security-settings page). Request correlation
        // now runs globally (see the append() above).
        $middleware->web(append: [
            EnsureActiveAccount::class,

            // Phase 20 — first-party marketing attribution (UTM / click-id /
            // referrer capture). Runs after the request so it can also set
            // the anonymous-visitor cookie on every response.
            CaptureMarketingAttribution::class,
        ]);

        // Phase 20 — the consent cookie is read by the layout JS to gate
        // third-party tags, so it must stay plaintext (it carries no PII —
        // just two booleans + the policy version). The attribution cookie
        // stays encrypted: server-side only, httpOnly.
        $middleware->encryptCookies(except: [
            env('MARKETING_CONSENT_COOKIE', 'ff_consent'),
        ]);

        // Provider payment webhooks are authenticated by HMAC signature, not
        // by a session CSRF token. (Both the legacy Phase 08 endpoint and the
        // new Phase 15 inbound endpoint are signature-authenticated.)
        $middleware->validateCsrfTokens(except: [
            'webhooks/payments/*',
            'api/v1/webhooks/inbound/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Phase 15 — centralized API error rendering (JSON envelope). The
        // renderer only transforms /api/* responses; web routes keep
        // Laravel's default handling.
        $exceptions->render(function (Throwable $e, $request) {
            return ApiExceptionHandler::render($e, $request);
        });

        // Phase 16 — provider-neutral error reporting. The reporter receives
        // the safe request-correlation snapshot and never the request's
        // credentials (which the logging redaction layer scrubs anyway).
        $exceptions->report(function (Throwable $e) {
            try {
                app(ErrorReporterInterface::class)->report($e, RequestContext::snapshot());
            } catch (Throwable) {
                // Reporting must never break the request pipeline.
            }
        });
    })->create();
