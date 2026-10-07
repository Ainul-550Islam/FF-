<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * GAP-10 A8 (tracker row 039) — a tournament settlement finished.
 *
 * Dispatched by App\Services\Finance\AtomicSettlementService once the
 * settlement transaction has COMMITTED. The event implements
 * ShouldDispatchAfterCommit so that guarantee holds even if some future
 * caller dispatches it from inside an open transaction: Laravel defers the
 * dispatch until the commit succeeds, and drops it entirely if the
 * transaction rolls back.
 *
 * The payload is intentionally identifiers only (settlement, tournament,
 * actor). Listeners re-read the settled state from the database rather than
 * trusting a snapshot carried through the queue — a payload that can drift
 * from the ledger is precisely the kind of "second source of truth" the money
 * core avoids.
 *
 * Delivery to users happens through the existing outbox: the listener writes
 * Notification rows via App\Services\NotificationService, which the queue then
 * delivers. No notification is attempted from inside the settlement
 * transaction.
 */
class SettlementCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $settlementId,
        public readonly int $tournamentId,
        public readonly ?int $actorId = null,
    ) {}

    /**
     * A loggable, serialisable representation (no money amounts, no personal
     * data).
     *
     * @return array<string, int|null>
     */
    public function toArray(): array
    {
        return [
            'settlement_id' => $this->settlementId,
            'tournament_id' => $this->tournamentId,
            'actor_id' => $this->actorId,
        ];
    }
}
