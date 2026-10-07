<?php

namespace App\Providers;

use App\Support\TrustedProxyConfig;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\ServiceProvider;

/**
 * GAP-10 (finding F-25) — make the deployment's reverse proxy visible to the
 * application.
 *
 * Why this is a provider and not a line inside `bootstrap/app.php`: Laravel
 * resolves the HTTP kernel — which is when `withMiddleware()`'s callback runs —
 * *before* the bootstrappers load the configuration, so `config()` is not
 * available there and calling it fails the application at boot
 * (`ReflectionException: Class "config" does not exist` during
 * `php artisan package:discover`). `register()` runs after
 * `LoadConfiguration`, and `TrustProxies::at()`/`withHeaders()` are the same
 * static switch Laravel's own `Middleware::trustProxies()` flips, read by the
 * `TrustProxies` middleware when the first request is handled.
 *
 * The value is read once here and applied for the process; `TRUSTED_PROXIES`
 * and `TRUSTED_PROXY_HEADERS` are deployment settings, not per-request state.
 *
 * Fail-closed: with no `TRUSTED_PROXIES` value nothing is registered at all,
 * so the middleware keeps Laravel's default (no proxy is trusted) and a client
 * cannot forge `X-Forwarded-*` into a spoofed client IP or scheme.
 */
class TrustedProxyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $proxies = TrustedProxyConfig::proxies();

        if ($proxies === null) {
            return;
        }

        TrustProxies::at($proxies);
        TrustProxies::withHeaders(TrustedProxyConfig::headerMask());
    }
}
