<?php

namespace App\Support;

/**
 * Phase 16 — the central cache-key vocabulary.
 *
 * Every cache key the application writes goes through these constants so
 * invalidation stays explicit and greppable. Keys are namespaced by domain
 * and scoped to a single record; only non-authoritative, safe-to-rebuild
 * data may be cached (see CacheInvalidationService).
 */
final class CacheKeys
{
    /** Payment provider status matrix (TTL 60s, invalidated on refresh/flush). */
    public const PAYMENT_PROVIDER_STATUSES = 'ffarena:payments:provider_statuses';

    /** Public tournament availability snapshot for {id}. */
    public static function tournamentAvailability(int $tournamentId): string
    {
        return 'ffarena:tournament:'.$tournamentId.':availability';
    }

    /** Public leaderboard/standings for {id}. */
    public static function leaderboard(int $tournamentId): string
    {
        return 'ffarena:tournament:'.$tournamentId.':leaderboard';
    }

    /** Public match view for {id}. */
    public static function match(int $matchId): string
    {
        return 'ffarena:match:'.$matchId.':view';
    }

    /**
     * All public (safe) cache namespaces, used by the admin cache-flush
     * control. `providers` covers the payment-provider status matrix; `public`
     * covers the tournament/leaderboard/match read caches.
     *
     * @return array<string, string>
     */
    public static function flushNamespaces(): array
    {
        return [
            'providers' => self::PAYMENT_PROVIDER_STATUSES,
            'public' => 'ffarena:tournament:*',
        ];
    }
}
