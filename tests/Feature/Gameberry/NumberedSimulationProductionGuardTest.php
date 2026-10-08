<?php

namespace Tests\Feature\Gameberry;

use App\Http\Middleware\EnsureNumberedSimulationSafe;
use App\Models\GemTransaction;
use App\Models\GoldTransaction;
use App\Models\User;
use App\Services\Gameberry\GemEconomyService;
use App\Services\Gameberry\GoldEconomyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * GAP-10 A1 — the reported production bypass, end to end.
 *
 * The finding (confirmed by execution against the pre-GAP-10 tree):
 *
 *     APP_ENV=production
 *     POST /v1/gameberry/core/feature-192/play
 *     → 200, and the caller's gold wallet mutated via random_int(0, 1)
 *
 * while `final9` was refused. The guard matched `gameberry/final…` only, so the
 * `core` and `stats` families — which mutate the same virtual wallets — were
 * reachable in production. Gold and gems do not connect to the BDT wallet, so
 * this is virtual-economy integrity rather than cash loss; it is still a
 * production write path that must not exist.
 *
 * Two independent controls close it, and this file asserts both, because either
 * one alone is a single point of failure:
 *
 *   1. `bootstrap/app.php` registers the numbered route files only when
 *      `features.gameberry_numbered_simulations` is on AND the environment is
 *      `local`/`testing`. In production the routes are not in the table at all.
 *   2. `EnsureNumberedSimulationSafe` refuses every mutating request under a
 *      numbered family outside `local`/`testing` with 403, regardless of what
 *      the route table contains. It is aliased `numbered.simulation` and sits on
 *      both route groups, so re-registering a numbered route would still hit it.
 *
 * Why control 1 is asserted in a subprocess: the route table is built once, at
 * application boot. Switching the environment inside a booted test process
 * cannot retroactively un-register a route, so the honest way to prove "the
 * routes are not registered in production" is to boot a second process with
 * APP_ENV=production and read its route table — which is what
 * `productionRouteUris()` does. The flag is deliberately passed as **true** to
 * that process: the point is that the environment gate holds even when the
 * feature flag is on, not that the flag happens to default to false.
 *
 * The last test is deliberate too: the SAME request that is refused in
 * production is exercised in `testing`, where it must succeed and settle gold.
 * Without it, a refusal could be caused by a typo in the URIs and this file
 * would pass for the wrong reason.
 *
 * What this file does NOT claim: that the numbered simulations are good product
 * features. They are generator scaffolding that this pass hides (A3, Option B)
 * pending deletion. The point is that they are unreachable, and that the real
 * Gameberry surface is not what is being blocked.
 */
class NumberedSimulationProductionGuardTest extends TestCase
{
    use RefreshDatabase;

    /** The two URIs from the finding, verbatim. */
    protected const CORE_URI = '/v1/gameberry/core/feature-192/play';

    protected const FINAL9_URI = '/v1/gameberry/final9/feature-1286/play';

    /**
     * The reporter's setup: a verified account with both virtual wallets and a
     * token that carries the Gameberry abilities.
     */
    protected function identifiedPlayer(): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        // Initial balances come from the services themselves rather than from
        // hand-built rows, so this test cannot drift from the real economy.
        app(GoldEconomyService::class)->getOrCreateWallet($user->id);
        app(GemEconomyService::class)->getOrCreateWallet($user->id);

        Sanctum::actingAs($user, ['gameberry:read', 'gameberry:play']);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    protected function playPayload(): array
    {
        return ['game_mode' => 'classic', 'bet_amount' => 100];
    }

    protected function goldBalance(User $user): int
    {
        return (int) app(GoldEconomyService::class)->getBalance((int) $user->id);
    }

    protected function gemBalance(User $user): int
    {
        return (int) app(GemEconomyService::class)->getOrCreateWallet((int) $user->id)->gem_balance;
    }

    /**
     * Run a request through the guard middleware alone.
     *
     * The route table is irrelevant here on purpose: this is the control that
     * has to hold even if somebody re-registers a numbered route in production.
     */
    protected function throughGuard(string $uri, string $method): mixed
    {
        $request = Request::create($uri, $method);

        return (new EnsureNumberedSimulationSafe())->handle(
            $request,
            fn () => response('reached the simulation'),
        );
    }

    /**
     * Switch the application environment for one test, and always put it back.
     *
     * @return callable(): void restores the previous environment
     */
    protected function useEnvironment(string $environment): callable
    {
        $original = app()['env'];
        app()['env'] = $environment;

        return function () use ($original): void {
            app()['env'] = $original;
        };
    }

    /**
     * Boot a separate process and return its route table.
     *
     * @param  array<string, string>  $environment
     * @return list<string> every registered URI
     */
    protected function routeUrisIn(array $environment): array
    {
        $process = new Process(
            [PHP_BINARY, 'artisan', 'route:list', '--json'],
            base_path(),
            $environment,
        );

        $process->setTimeout(180);
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            "`artisan route:list` failed with APP_ENV={$environment['APP_ENV']}: ".$process->getErrorOutput()
        );

        $decoded = json_decode($process->getOutput(), true);

        $this->assertIsArray($decoded, 'route:list --json did not return JSON.');

        return array_map(
            static fn (array $route): string => (string) ($route['uri'] ?? ''),
            $decoded,
        );
    }

    /**
     * @param  list<string>  $uris
     * @return list<string>
     */
    protected function numberedUris(array $uris): array
    {
        return array_values(array_filter(
            $uris,
            static fn (string $uri): bool => (bool) preg_match(
                '#(?:^|/)gameberry/(?:core|stats|final[0-9]*)(?:/|$)#i',
                $uri
            ),
        ));
    }

    // -----------------------------------------------------------------------
    // 1. Control 1 — the numbered route files are not registered in production
    // -----------------------------------------------------------------------

    public function test_production_does_not_register_the_numbered_routes_even_with_the_flag_on(): void
    {
        // The flag is passed as TRUE on purpose: the environment gate, not the
        // flag default, is what has to keep these routes out of production.
        $production = $this->routeUrisIn([
            'APP_ENV' => 'production',
            'GAMEBERRY_NUMBERED_SIMULATIONS' => 'true',
        ]);

        $this->assertSame(
            [],
            $this->numberedUris($production),
            "Production registered numbered routes: ".implode(', ', $this->numberedUris($production))
        );

        // Sensitivity: the same flag in the test environment DOES register them,
        // so the assertion above is measuring the environment gate and not a
        // broken route file.
        $testing = $this->routeUrisIn([
            'APP_ENV' => 'testing',
            'GAMEBERRY_NUMBERED_SIMULATIONS' => 'true',
        ]);

        $this->assertNotSame(
            [],
            $this->numberedUris($testing),
            'The numbered routes must be registered in testing, or this test proves nothing.'
        );

        // And in the booted test process, the predicate that drives registration
        // is false for production — the same condition the subprocess observed.
        $restore = $this->useEnvironment('production');

        try {
            $this->assertFalse(
                EnsureNumberedSimulationSafe::routesMayBeRegistered(),
                'In production the numbered route files must not be registerable.'
            );
        } finally {
            $restore();
        }
    }

    // -----------------------------------------------------------------------
    // 2. Control 2 — the guard refuses the reported write, and nothing moves
    // -----------------------------------------------------------------------

    public function test_the_reported_writes_are_refused_and_no_wallet_moves_in_production(): void
    {
        $restore = $this->useEnvironment('production');

        try {
            $user = $this->identifiedPlayer();

            $goldBefore = $this->goldBalance($user);
            $gemBefore = $this->gemBalance($user);

            // End to end through the real HTTP kernel: both URIs from the
            // finding, authenticated and funded, refused with 403.
            $this->postJson(self::CORE_URI, $this->playPayload())
                ->assertForbidden()
                ->assertJsonPath('message', EnsureNumberedSimulationSafe::DENIED_MESSAGE);

            $this->postJson(self::FINAL9_URI, $this->playPayload())
                ->assertForbidden()
                ->assertJsonPath('message', EnsureNumberedSimulationSafe::DENIED_MESSAGE);

            // Every mutating method, through the middleware itself.
            $refused = 0;

            foreach ([self::CORE_URI, self::FINAL9_URI] as $uri) {
                foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
                    try {
                        $this->throughGuard($uri, $method);

                        $this->fail("The guard allowed {$method} {$uri} in production.");
                    } catch (HttpException $e) {
                        $this->assertSame(403, $e->getStatusCode(), "{$method} {$uri} answered the wrong status.");
                        $this->assertSame(
                            EnsureNumberedSimulationSafe::DENIED_MESSAGE,
                            $e->getMessage(),
                            'The refusal must keep its stable, non-ambiguous message.'
                        );
                        $refused++;
                    }
                }
            }

            $this->assertSame(8, $refused, 'Every mutating combination must be refused.');

            // Nothing ran: no transaction rows, no balance movement.
            $this->assertSame(
                0,
                GoldTransaction::where('user_id', $user->id)->count(),
                'A refused production request wrote a gold transaction.'
            );

            $this->assertSame(
                0,
                GemTransaction::where('user_id', $user->id)->count(),
                'A refused production request wrote a gem transaction.'
            );

            $this->assertSame($goldBefore, $this->goldBalance($user), 'The gold wallet moved in production.');
            $this->assertSame($gemBefore, $this->gemBalance($user), 'The gem wallet moved in production.');

            // Read-only requests stay available: the same simulations are used
            // for operator visibility and a GET cannot move a wallet. This is
            // also why the guard alone is not the only control — hiding the
            // routes is what removes them from production entirely.
            $allowed = $this->throughGuard(self::CORE_URI, 'GET');
            $this->assertSame(200, $allowed->getStatusCode());
            $this->assertSame('reached the simulation', $allowed->getContent());

            // A real Gameberry path that merely starts with `gameberry/` is not
            // a numbered family and must never be caught by the guard.
            $safe = $this->throughGuard('/v1/gameberry/gold-wallet/stats', 'POST');
            $this->assertSame(200, $safe->getStatusCode());
            $this->assertSame('reached the simulation', $safe->getContent());
        } finally {
            $restore();
        }
    }

    // -----------------------------------------------------------------------
    // 3. Testing — the same call is live and settles gold
    // -----------------------------------------------------------------------

    public function test_the_same_write_succeeds_and_settles_gold_in_the_test_environment(): void
    {
        $this->assertTrue(
            EnsureNumberedSimulationSafe::environmentAllowsSimulations(),
            'The simulations must stay usable in local/testing — that is where they are exercised.'
        );

        $this->assertContains(
            'v1/gameberry/core/feature-192/play',
            $this->numberedUris($this->routeUrisIn([
                'APP_ENV' => 'testing',
                'GAMEBERRY_NUMBERED_SIMULATIONS' => 'true',
            ])),
            'phpunit.xml pins the flag on; without the route this test proves nothing.'
        );

        $user = $this->identifiedPlayer();
        $gemBefore = $this->gemBalance($user);
        $goldBefore = $this->goldBalance($user);

        $response = $this->postJson(self::CORE_URI, $this->playPayload());

        $response->assertOk();
        // Standard envelope: gameberry.envelope normalizes the legacy
        // {"success":true,"data":...} shape to {data, meta}.
        $response->assertJsonStructure(['data' => ['is_win'], 'meta']);

        // The write really happened: exactly one bet was placed for this user.
        $this->assertSame(
            1,
            GoldTransaction::where('user_id', $user->id)->where('type', 'bet')->count(),
            'The allowed simulation did not place the bet — the test would pass vacuously.'
        );

        // The outcome is a coin flip by design (random_int), so the balance is
        // one of exactly two values: the bet lost, or the bet doubled.
        $goldAfter = $this->goldBalance($user);

        $this->assertContains(
            $goldAfter,
            [$goldBefore - 100, $goldBefore + 100],
            "Unexpected gold balance after the simulation: {$goldAfter}."
        );

        // Gold-only by design: the numbered simulation must not touch gems.
        $this->assertSame(
            0,
            GemTransaction::where('user_id', $user->id)->count(),
            'The numbered simulation wrote a gem transaction.'
        );

        $this->assertSame($gemBefore, $this->gemBalance($user), 'The numbered simulation moved gems.');
    }

    // -----------------------------------------------------------------------
    // 4. Authentication is still the first wall in the test environment
    // -----------------------------------------------------------------------

    public function test_an_unauthenticated_request_cannot_use_the_simulation(): void
    {
        $response = $this->postJson(self::CORE_URI, $this->playPayload());

        $this->assertContains(
            $response->getStatusCode(),
            [401, 403, 302],
            'An anonymous request must not reach the simulation.'
        );

        $this->assertSame(0, GoldTransaction::count(), 'An anonymous request wrote a gold transaction.');
        $this->assertSame(0, GemTransaction::count(), 'An anonymous request wrote a gem transaction.');
    }
}
