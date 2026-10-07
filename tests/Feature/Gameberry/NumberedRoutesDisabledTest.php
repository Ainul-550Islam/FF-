<?php

namespace Tests\Feature\Gameberry;

use App\Http\Middleware\EnsureNumberedSimulationSafe;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * GAP-10 A3 (Option B) — the numbered simulation families are hidden, not
 * merely unlinked.
 *
 * `core`, `final*` and `stats` are template-generated clones (1,375 files).
 * They are development scaffolding: near-duplicates, several of them moving the
 * virtual gold/gem ledgers. Option B keeps the files in the tree for the
 * simulations that are still exercised in development, but makes the endpoints
 * unreachable everywhere else through two independent controls:
 *
 *   1. `bootstrap/app.php` only requires `routes/gameberry_numbered.php` and
 *      `routes/api_gameberry_numbered.php` when
 *      `features.gameberry_numbered_simulations` is on AND the environment is
 *      local/testing — in production the routes do not exist at all;
 *   2. every numbered route additionally carries the `numbered.simulation`
 *      middleware, which refuses mutating requests outside local/testing.
 *
 * This test pins both controls: the route-table half (every numbered route is
 * behind the guard, and the guard's own enablement rule), and the middleware
 * half (it recognises every numbered family and refuses them in production,
 * while leaving look-alike safe paths alone).
 *
 * The flag is pinned ON in phpunit.xml (A7) so the routes are registered during
 * the suite; the production half of the behaviour is exercised by flipping the
 * environment, which is what the middleware actually reads.
 */
class NumberedRoutesDisabledTest extends TestCase
{
    /**
     * Every route name that belongs to a numbered family, straight from the
     * router — the same list the guard works from.
     *
     * @return array<int, RoutingRoute>
     */
    protected function numberedRoutes(): array
    {
        $matches = [];

        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            if (EnsureNumberedSimulationSafe::isNumberedRouteName($route->getName())) {
                $matches[] = $route;
            }
        }

        return $matches;
    }

    /**
     * Run a request through the guard middleware alone and return the response
     * or the HTTP exception it aborted with.
     */
    protected function throughGuard(string $uri, string $method, ?string $routeName = null): TestResponse
    {
        $request = Request::create($uri, $method);

        if ($routeName !== null) {
            $route = new RoutingRoute(['GET', 'POST'], $uri, ['as' => $routeName]);
            $request->setRouteResolver(fn () => $route);
        }

        $response = (new EnsureNumberedSimulationSafe())->handle(
            $request,
            fn () => response('reached the simulation'),
        );

        return TestResponse::fromBaseResponse($response);
    }

    // ------------------------------------------------------------------
    // The route table
    // ------------------------------------------------------------------

    public function test_the_numbered_families_are_registered_while_the_flag_is_on(): void
    {
        // phpunit.xml pins GAMEBERRY_NUMBERED_SIMULATIONS=true precisely so the
        // development simulations stay testable. If this fails, the flag or the
        // bootstrap guard broke and the whole family silently disappeared.
        $this->assertTrue(
            EnsureNumberedSimulationSafe::routesMayBeRegistered(),
            'The numbered families must be registerable while the flag is on in testing.'
        );

        $routes = $this->numberedRoutes();

        $this->assertGreaterThan(
            0,
            count($routes),
            'No numbered route is registered even though the flag is on and the environment is testing.'
        );

        $families = ['core' => false, 'stats' => false, 'final' => false];

        foreach ($routes as $route) {
            $name = (string) $route->getName();

            foreach (array_keys($families) as $family) {
                if (preg_match('#(?:^|\.)gameberry\.'.$family.'[0-9]*(?:\.|$)#i', $name)) {
                    $families[$family] = true;
                }
            }
        }

        foreach ($families as $family => $seen) {
            $this->assertTrue(
                $seen,
                "The {$family} family has no registered route: the guard is not family-wide (GAP-10 A1)."
            );
        }
    }

    public function test_every_numbered_route_is_behind_the_guard_middleware(): void
    {
        $routes = $this->numberedRoutes();

        $this->assertGreaterThan(0, count($routes));

        $unguarded = [];

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();

            $guarded = in_array('numbered.simulation', $middleware, true)
                || in_array(EnsureNumberedSimulationSafe::class, $middleware, true);

            if (! $guarded) {
                $unguarded[] = (string) $route->getName().' ['.implode(', ', $middleware).']';
            }
        }

        $this->assertSame(
            [],
            $unguarded,
            "These numbered routes are not behind the numbered.simulation guard:\n".implode("\n", $unguarded)
        );
    }

    public function test_the_routes_may_not_be_registered_when_the_flag_is_off(): void
    {
        $original = config('features.gameberry_numbered_simulations');

        config()->set('features.gameberry_numbered_simulations', false);

        $this->assertFalse(
            EnsureNumberedSimulationSafe::routesMayBeRegistered(),
            'With the flag off bootstrap/app.php must not require the numbered route files.'
        );

        config()->set('features.gameberry_numbered_simulations', $original);
    }

    // ------------------------------------------------------------------
    // The middleware
    // ------------------------------------------------------------------

    public function test_the_guard_recognises_every_numbered_family(): void
    {
        $numbered = [
            'gameberry/core/feature-192/play',
            'gameberry/final/feature-1/play',
            'gameberry/final9/feature-1286/play',
            'gameberry/final12/feature-99/calculate',
            'gameberry/stats/stat-21/calculate',
            'v1/gameberry/core/feature-192/play',
            'api/v1/gameberry/final2/feature-4/play',
        ];

        foreach ($numbered as $path) {
            $this->assertTrue(
                EnsureNumberedSimulationSafe::isNumberedPath($path),
                "The guard missed the numbered path [{$path}]."
            );
        }

        // Route names are a second, independent tripwire.
        foreach ([
            'gameberry.core.feature_121.play',
            'gameberry.final9.feature_1286.play',
            'api.v1.gameberry.final12.feature_9.calculate',
            'api.v1.gameberry.stats.stat_21.calculate',
            'gameberry.final.feature_1.play',
        ] as $name) {
            $this->assertTrue(
                EnsureNumberedSimulationSafe::isNumberedRouteName($name),
                "The guard missed the numbered route name [{$name}]."
            );
        }
    }

    public function test_look_alike_safe_paths_are_not_caught_by_the_guard(): void
    {
        $safe = [
            'v1/gameberry/gold-wallet/stats',
            'v1/gameberry/economy/spin/stats',
            'api/v1/gameberry/social/stats',
            'gameberry/tournaments',
            'gameberry/finale-room',
            'admin/gameberry/core-report',
        ];

        foreach ($safe as $path) {
            $this->assertFalse(
                EnsureNumberedSimulationSafe::isNumberedPath($path),
                "The guard wrongly matched the safe path [{$path}] — a real endpoint would break."
            );
        }

        foreach (['api.v1.gameberry.gold_wallet.stats', 'gameberry.tournaments.index'] as $name) {
            $this->assertFalse(
                EnsureNumberedSimulationSafe::isNumberedRouteName($name),
                "The guard wrongly matched the safe route name [{$name}]."
            );
        }
    }

    public function test_mutating_numbered_requests_are_refused_in_production(): void
    {
        $original = app()['env'];
        app()['env'] = 'production';

        try {
            $this->assertFalse(EnsureNumberedSimulationSafe::environmentAllowsSimulations());
            $this->assertFalse(
                EnsureNumberedSimulationSafe::routesMayBeRegistered(),
                'In production the numbered route files must not be registered.'
            );

            $denied = 0;

            foreach ([
                ['/v1/gameberry/core/feature-192/play', 'POST'],
                ['/gameberry/final9/feature-1286/play', 'POST'],
                ['/gameberry/stats/stat-21/calculate', 'POST'],
                ['/v1/gameberry/final/feature-1/play', 'PUT'],
                ['/v1/gameberry/core/feature-3/play', 'DELETE'],
            ] as [$uri, $method]) {
                try {
                    $this->throughGuard($uri, $method);

                    $this->fail("The guard allowed {$method} {$uri} in production.");
                } catch (HttpException $e) {
                    $this->assertSame(403, $e->getStatusCode());
                    $this->assertSame(EnsureNumberedSimulationSafe::DENIED_MESSAGE, $e->getMessage());
                    $denied++;
                }
            }

            $this->assertSame(5, $denied);

            // An unnamed route that merely *looks* numbered is still caught.
            try {
                $this->throughGuard('/v1/gameberry/final12/feature-9/play', 'POST');

                $this->fail('The guard allowed a numbered path without a route name in production.');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }

            // Read-only requests stay available: the simulations are also used
            // for operator visibility, and GET never mutates a wallet.
            $this->throughGuard('/v1/gameberry/core/feature-192/play', 'GET')
                ->assertOk()
                ->assertSee('reached the simulation');

            // A safe path is untouched, even in production.
            $this->throughGuard('/v1/gameberry/gold-wallet/stats', 'POST')
                ->assertOk()
                ->assertSee('reached the simulation');
        } finally {
            app()['env'] = $original;
        }
    }

    public function test_numbered_requests_are_allowed_in_the_test_environment(): void
    {
        $this->assertTrue(
            EnsureNumberedSimulationSafe::environmentAllowsSimulations(),
            'The simulations must stay usable in local/testing — that is where they are exercised.'
        );

        $this->throughGuard('/v1/gameberry/final9/feature-1286/play', 'POST')
            ->assertOk()
            ->assertSee('reached the simulation');
    }

    public function test_the_guard_is_a_named_alias_on_the_router(): void
    {
        // The routes reference `numbered.simulation`; if the alias is dropped
        // every numbered route would fail to resolve and 500 in testing.
        $middleware = app('router')->getMiddleware();

        $this->assertArrayHasKey('numbered.simulation', $middleware);
        $this->assertSame(EnsureNumberedSimulationSafe::class, $middleware['numbered.simulation']);
    }
}
