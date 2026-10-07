<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GAP-10 (A1) — production guard for the "numbered simulation" families.
 *
 * The `core`, `final*` and `stats` route families are template-generated
 * simulation endpoints (`/v1/gameberry/core/feature-192/play`,
 * `/gameberry/final9/feature-1286/play`, `/gameberry/stats/stat-21/calculate`,
 * …). Some of them mutate the virtual gold/gem ledgers, so they may never be
 * reachable in a production environment.
 *
 * Before GAP-10 this guard did not exist at all: with APP_ENV=production a
 * numbered POST route happily mutated the caller's gold wallet. This
 * middleware now fails closed for every numbered family, on both the web and
 * the API route groups, regardless of the HTTP method except the safe
 * read-only ones (GET/HEAD/OPTIONS). It is deliberately *not* the only
 * control: `bootstrap/app.php` additionally only registers the numbered route
 * files when `features.gameberry_numbered_simulations` is enabled AND the app
 * runs in `local`/`testing` (GAP-10 A3, Option B).
 *
 * Rules kept intentionally dumb and easy to audit:
 *   - matching is anchored on the segment directly after `gameberry/`, so
 *     safe routes such as `v1/gameberry/gold-wallet/stats` or
 *     `v1/gameberry/economy/spin/stats` are never caught;
 *   - `local` and `testing` environments keep the full behaviour (that is
 *     where the simulations are exercised);
 *   - the response is a 403 with an explicit, non-ambiguous message.
 */
class EnsureNumberedSimulationSafe
{
    /**
     * Numbered families, matched immediately after the `gameberry/` segment.
     *
     * @var list<string>
     */
    protected const NUMBERED_FAMILIES = ['core', 'stats'];

    /**
     * Prefix that always matches a numbered family (`final`, `final2`, …).
     */
    protected const NUMBERED_PREFIX = 'final';

    /**
     * Methods that never mutate state and stay allowed outside local/testing.
     *
     * @var list<string>
     */
    protected const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * The 403 message. Kept stable so tests can assert on it.
     */
    public const DENIED_MESSAGE = 'Numbered gameberry simulations are disabled in this environment.';

    /**
     * Environments where the simulations remain available.
     *
     * @var list<string>
     */
    protected const ALLOWED_ENVIRONMENTS = ['local', 'testing'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! static::isNumberedPath($request->path()) && ! static::isNumberedRouteName($request->route()?->getName())) {
            return $next($request);
        }

        if (static::environmentAllowsSimulations()) {
            return $next($request);
        }

        if (in_array(strtoupper($request->getMethod()), static::SAFE_METHODS, true)) {
            return $next($request);
        }

        abort(403, self::DENIED_MESSAGE);
    }

    /**
     * True when the request path targets a numbered simulation family,
     * e.g. `gameberry/core/...`, `v1/gameberry/final9/...`, `gameberry/stats/...`.
     *
     * The optional `api/` and `v1/` prefixes are tolerated: the shipped routes
     * live under `gameberry/...` (web) and `v1/gameberry/...` (API), but a
     * proxy or a rewrite rule that presents the same endpoint as
     * `api/v1/gameberry/core/...` must not be able to slip past the guard. The
     * family words are what make a path numbered, not the prefix.
     */
    public static function isNumberedPath(string $path): bool
    {
        $path = trim($path, '/');

        return (bool) preg_match(
            '#^(?:api/)?(?:v1/)?gameberry/(?:core|stats|final[0-9]*)(?:/|$)#i',
            $path
        );
    }

    /**
     * Route names use the same family words (`gameberry.core.feature_121.play`,
     * `api.v1.gameberry.final9.feature_1286.play`, …) — those routes are
     * registered inside the same numbered route files, so the name check is a
     * second, independent tripwire.
     */
    public static function isNumberedRouteName(?string $name): bool
    {
        if ($name === null || $name === '') {
            return false;
        }

        return (bool) preg_match(
            '#(?:^|\.)gameberry\.(?:core|stats|final[0-9]*)(?:\.|$)#i',
            $name
        );
    }

    /**
     * The numbered simulations are only meaningful for development and tests.
     */
    public static function environmentAllowsSimulations(): bool
    {
        return app()->environment(static::ALLOWED_ENVIRONMENTS);
    }

    /**
     * Whether the numbered route files should be registered at all (GAP-10 A3).
     */
    public static function routesMayBeRegistered(): bool
    {
        return (bool) config('features.gameberry_numbered_simulations', false)
            && static::environmentAllowsSimulations();
    }
}
