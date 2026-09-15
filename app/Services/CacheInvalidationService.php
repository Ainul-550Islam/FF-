<?php

namespace App\Services;

use App\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;

/**
 * Phase 16 — central cache invalidation.
 *
 * Every write that can invalidate a cached public view funnels through here.
 * The methods are exception-safe (invalidation must never break the write it
 * follows) and only ever touch the non-authoritative, safe-to-rebuild keys
 * defined in CacheKeys. Sensitive data (wallet state, risk data, private
 * disputes, sessions) is never cached and therefore never invalidated here.
 */
class CacheInvalidationService
{
    /**
     * A tournament's availability snapshot (slots/status) changed.
     */
    public function invalidateTournamentAvailability(int $tournamentId): void
    {
        $this->forget(CacheKeys::tournamentAvailability($tournamentId));
    }

    /**
     * A tournament's public record changed (publish/start/complete/cancel).
     */
    public function invalidateTournament(int $tournamentId): void
    {
        $this->forget(CacheKeys::tournamentAvailability($tournamentId));
        $this->forget(CacheKeys::leaderboard($tournamentId));
    }

    /**
     * Standings for a tournament changed (score submitted/adjusted).
     */
    public function invalidateLeaderboard(int $tournamentId): void
    {
        $this->forget(CacheKeys::leaderboard($tournamentId));
    }

    /**
     * A match view changed.
     */
    public function invalidateMatch(int $matchId): void
    {
        $this->forget(CacheKeys::match($matchId));
    }

    /**
     * Payment provider statuses changed (config edit or admin flush).
     */
    public function invalidateProviderStatuses(): void
    {
        $this->forget(CacheKeys::PAYMENT_PROVIDER_STATUSES);
    }

    /**
     * Admin cache flush for an approved namespace.
     *
     * `providers` clears the payment-provider status matrix; `public` clears
     * every public read cache key the application currently writes. Any other
     * namespace is rejected (whitelist).
     */
    public function flush(string $namespace): bool
    {
        if (! array_key_exists($namespace, CacheKeys::flushNamespaces())) {
            return false;
        }

        if ($namespace === 'public') {
            $this->invalidateProviderStatuses();

            return true;
        }

        $this->forget(CacheKeys::flushNamespaces()[$namespace]);

        return true;
    }

    protected function forget(string $key): void
    {
        try {
            Cache::forget($key);
        } catch (\Throwable) {
            // Invalidation must never break the originating write.
        }
    }
}
