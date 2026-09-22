<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Regression guard for the Gameberry hub routes.
 *
 * Before this test existed, five controllers (Dashboard, Level, GameMode,
 * AutoMode, GoldWallet) existed as files but were registered nowhere, and six
 * route names were called from Blade views without being defined. Rendering any
 * of those pages threw RouteNotFoundException:
 *
 *   resources/views/gameberry/layout.blade.php:9            route('gameberry.dashboard.index')
 *   resources/views/gameberry/auto_mode/index.blade.php:12  route('gameberry.auto_mode.enable')
 *   resources/views/gameberry/auto_mode/index.blade.php:14  route('gameberry.auto_mode.disable')
 *   resources/views/gameberry/game_modes/show.blade.php:5   route('gameberry.game_modes.index')
 *   resources/views/gameberry/level/show.blade.php:5        route('gameberry.level.index')
 *   resources/views/auth/verify-email.blade.php:8           route('verification.send')
 *
 * These assertions need no database, so they stay fast and can never be skipped.
 */
class GameberryHubRoutesTest extends TestCase
{
    /**
     * Every route name a Blade view or controller resolves by name must exist.
     *
     * @return array<int, string>
     */
    private function hubRouteNames(): array
    {
        return [
            'gameberry.dashboard.index',
            'gameberry.level.index',
            'gameberry.level.show',
            'gameberry.game_modes.index',
            'gameberry.game_modes.show',
            'gameberry.auto_mode.index',
            'gameberry.auto_mode.enable',
            'gameberry.auto_mode.disable',
            'gameberry.gold_wallet.index',
            'gameberry.gold_wallet.stats',
            'verification.send',
        ];
    }

    public function test_gameberry_hub_route_names_are_registered(): void
    {
        foreach ($this->hubRouteNames() as $name) {
            $this->assertTrue(
                Route::has($name),
                "Route name [{$name}] is not registered - a page that calls route('{$name}') would throw RouteNotFoundException."
            );
        }
    }

    public function test_gameberry_hub_urls_generate(): void
    {
        $this->assertSame(url('/gameberry/dashboard'), route('gameberry.dashboard.index'));
        $this->assertSame(url('/gameberry/level'), route('gameberry.level.index'));
        $this->assertSame(url('/gameberry/level/1'), route('gameberry.level.show', ['userId' => 1]));
        $this->assertSame(url('/gameberry/game-modes'), route('gameberry.game_modes.index'));
        $this->assertSame(url('/gameberry/game-modes/classic'), route('gameberry.game_modes.show', ['mode' => 'classic']));
        $this->assertSame(url('/gameberry/auto-mode'), route('gameberry.auto_mode.index'));
        $this->assertSame(url('/gameberry/auto-mode/enable'), route('gameberry.auto_mode.enable'));
        $this->assertSame(url('/gameberry/auto-mode/disable'), route('gameberry.auto_mode.disable'));
        $this->assertSame(url('/gameberry/gold-wallet'), route('gameberry.gold_wallet.index'));
        $this->assertSame(url('/gameberry/gold-wallet/stats'), route('gameberry.gold_wallet.stats'));
        $this->assertSame(url('/verify-email/resend'), route('verification.send'));
    }

    public function test_previously_unrouted_controllers_exist_and_are_routed(): void
    {
        $controllers = [
            \App\Http\Controllers\Gameberry\DashboardController::class,
            \App\Http\Controllers\Gameberry\LevelController::class,
            \App\Http\Controllers\Gameberry\GameModeController::class,
            \App\Http\Controllers\Gameberry\AutoModeController::class,
            \App\Http\Controllers\Gameberry\GoldWalletController::class,
        ];

        foreach ($controllers as $class) {
            $this->assertTrue(class_exists($class), "Controller [{$class}] file is missing.");
        }

        $registered = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->getAction('controller'))
            ->filter()
            ->implode('|');

        foreach ($controllers as $class) {
            $this->assertStringContainsString(
                $class,
                $registered,
                "Controller [{$class}] exists but no route points at it."
            );
        }
    }

    public function test_verification_resend_keeps_both_names(): void
    {
        // tests/Feature/AccountAuthTest.php:212 posts to verification.resend, while
        // resources/views/auth/verify-email.blade.php posts to verification.send.
        // Both names must resolve to the same endpoint.
        $this->assertTrue(Route::has('verification.resend'));
        $this->assertTrue(Route::has('verification.send'));
        $this->assertSame(route('verification.resend'), route('verification.send'));
    }

    public function test_gameberry_hub_views_exist(): void
    {
        foreach ([
            'gameberry.dashboard.index',
            'gameberry.level.index',
            'gameberry.level.show',
            'gameberry.game_modes.index',
            'gameberry.game_modes.show',
            'gameberry.auto_mode.index',
        ] as $view) {
            $this->assertTrue(
                view()->exists($view),
                "Blade view [{$view}] is missing from resources/views."
            );
        }
    }
}
