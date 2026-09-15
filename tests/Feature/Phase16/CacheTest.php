<?php

namespace Tests\Feature\Phase16;

use App\Services\CacheInvalidationService;
use App\Services\PaymentGatewayManager;
use App\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;

/**
 * Phase 16 — cache architecture, invalidation and safety.
 */
class CacheTest extends Phase16TestCase
{
    public function test_cache_hit_and_miss(): void
    {
        $key = 'ffarena:test:probe';

        $this->assertNull(Cache::get($key));

        Cache::put($key, 'value', 60);

        $this->assertSame('value', Cache::get($key));

        Cache::forget($key);

        $this->assertNull(Cache::get($key));
    }

    public function test_invalidation_targets_the_right_keys(): void
    {
        $invalidator = app(CacheInvalidationService::class);

        Cache::put(CacheKeys::tournamentAvailability(42), ['slots' => 1], 60);
        Cache::put(CacheKeys::leaderboard(42), ['rows' => []], 60);
        Cache::put(CacheKeys::match(7), ['view' => true], 60);

        $invalidator->invalidateTournamentAvailability(42);
        $this->assertNull(Cache::get(CacheKeys::tournamentAvailability(42)));
        $this->assertNotNull(Cache::get(CacheKeys::leaderboard(42)));

        $invalidator->invalidateLeaderboard(42);
        $this->assertNull(Cache::get(CacheKeys::leaderboard(42)));

        $invalidator->invalidateMatch(7);
        $this->assertNull(Cache::get(CacheKeys::match(7)));
    }

    public function test_provider_statuses_are_cached_and_invalidated(): void
    {
        $manager = app(PaymentGatewayManager::class);

        $first = $manager->statuses();
        $this->assertNotEmpty($first);
        $this->assertNotNull(Cache::get(CacheKeys::PAYMENT_PROVIDER_STATUSES));

        // Second call within the TTL reads the cache.
        $second = $manager->statuses();
        $this->assertSame($first, $second);

        app(CacheInvalidationService::class)->invalidateProviderStatuses();
        $this->assertNull(Cache::get(CacheKeys::PAYMENT_PROVIDER_STATUSES));
    }

    public function test_cache_flush_namespace_whitelist(): void
    {
        $invalidator = app(CacheInvalidationService::class);

        $this->assertTrue($invalidator->flush('providers'));
        $this->assertTrue($invalidator->flush('public'));
        $this->assertFalse($invalidator->flush('arbitrary-namespace'));
    }

    public function test_no_private_data_is_cached_under_public_keys(): void
    {
        // The public cache vocabulary must never include wallet/session/risk
        // keys. Guard the vocabulary itself.
        $keys = [
            CacheKeys::PAYMENT_PROVIDER_STATUSES,
            CacheKeys::tournamentAvailability(1),
            CacheKeys::leaderboard(1),
            CacheKeys::match(1),
        ];

        foreach ($keys as $key) {
            $this->assertStringNotContainsString('wallet', $key);
            $this->assertStringNotContainsString('session', $key);
            $this->assertStringNotContainsString('risk', $key);
        }
    }
}
