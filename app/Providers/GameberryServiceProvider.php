<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\User;
use App\Observers\UserObserver;
use App\Services\Gameberry\DiceCollectionService;
use App\Services\Gameberry\LeagueService;
use App\Services\Gameberry\GoldEconomyService;
use App\Services\Gameberry\GemEconomyService;
use App\Services\Gameberry\PrivateTableService;
use App\Services\Gameberry\ChatEmojiService;
use App\Services\Gameberry\WeeklyEventService;
use App\Services\Gameberry\ReferralService;
use App\Services\Gameberry\SpinService;
use App\Services\Gameberry\MagicChestService;
use App\Services\Gameberry\VideoAdService;
use App\Services\Gameberry\SocialService;

class GameberryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register Gameberry services as singletons - preserve existing logic, only add
        $this->app->singleton(DiceCollectionService::class, function ($app) {
            return new DiceCollectionService();
        });

        $this->app->singleton(LeagueService::class, function ($app) {
            return new LeagueService();
        });

        $this->app->singleton(GoldEconomyService::class, function ($app) {
            return new GoldEconomyService();
        });

        $this->app->singleton(GemEconomyService::class, function ($app) {
            return new GemEconomyService();
        });

        $this->app->singleton(PrivateTableService::class, function ($app) {
            return new PrivateTableService();
        });

        $this->app->singleton(ChatEmojiService::class, function ($app) {
            return new ChatEmojiService();
        });

        $this->app->singleton(WeeklyEventService::class, function ($app) {
            return new WeeklyEventService();
        });

        $this->app->singleton(ReferralService::class, function ($app) {
            return new ReferralService();
        });

        $this->app->singleton(SpinService::class, function ($app) {
            return new SpinService();
        });

        $this->app->singleton(MagicChestService::class, function ($app) {
            return new MagicChestService();
        });

        $this->app->singleton(VideoAdService::class, function ($app) {
            return new VideoAdService();
        });

        $this->app->singleton(SocialService::class, function ($app) {
            return new SocialService();
        });
    }

    public function boot(): void
    {
        // Register observer for auto-creating wallets, levels, online status - Gameberry features
        User::observe(UserObserver::class);

        // Load routes if not already loaded via bootstrap/app.php
        // Note: routes/gameberry.php and routes/api_gameberry.php should be loaded in bootstrap/app.php or RouteServiceProvider
        // This provider ensures they are loaded as fallback
        if (file_exists(base_path('routes/gameberry.php'))) {
            $this->loadRoutesFrom(base_path('routes/gameberry.php'));
        }
        if (file_exists(base_path('routes/api_gameberry.php'))) {
            $this->loadRoutesFrom(base_path('routes/api_gameberry.php'));
        }

        // Publish config if needed (no-op for now, but keeps provider extensible)
    }
}
