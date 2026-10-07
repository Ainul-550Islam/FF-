<?php

namespace App\Listeners;

use App\Events\SettlementCompleted;
use App\Models\FinancialSettlement;
use App\Models\Notification;
use App\Models\Payout;
use App\Models\User;
use App\Services\NotificationService;
use Throwable;

/**
 * GAP-10 A8 (tracker row 039) — settlement-completed notifications.
 *
 * Runs after the settlement transaction has committed (the event itself is
 * ShouldDispatchAfterCommit). It re-reads the settled state from the database
 * rather than trusting the event payload, then writes Notification rows
 * through the existing NotificationService outbox.
 *
 * Handled synchronously but only ever AFTER the commit, because the event it
 * listens for implements ShouldDispatchAfterCommit: the dispatch is deferred
 * (and dropped if the transaction rolls back), so the handler can safely write
 * outbox rows. Laravel injects the NotificationService through the container,
 * and the outbox rows it writes are themselves delivered asynchronously by the
 * queue — that is the existing Phase 11 pattern.
 *
 * A failure here is reported and re-raised: the settlement has already
 * committed and must not be affected, but a broken notification path must be
 * visible rather than silent.
 *
 * Note: the notification service is injected through the constructor because
 * the event dispatcher resolves a class listener from the container and passes
 * only the event to handle() — method injection is not applied to listeners.
 * The listener is deliberately not queued for that reason; writing outbox rows
 * is a fast database write, and the queue delivers them from there.
 */
class SendSettlementCompletedNotifications
{
    public function __construct(
        protected NotificationService $notifications,
    ) {}

    public function handle(SettlementCompleted $event): void
    {
        $settlement = FinancialSettlement::query()->with('tournament')->find($event->settlementId);
        $tournament = $settlement?->tournament;

        if ($settlement === null || $tournament === null) {
            // The settlement was deleted (or the event is stale). Nothing to
            // notify; do not retry forever.
            return;
        }

        $recipients = [];

        $payouts = Payout::query()
            ->where('tournament_id', $tournament->id)
            ->with('recipient')
            ->get();

        foreach ($payouts as $payout) {
            if ($payout->recipient instanceof User) {
                $recipients[$payout->recipient->id] = $payout->recipient;
            }
        }

        if ($tournament->organizer instanceof User) {
            $recipients[$tournament->organizer->id] = $tournament->organizer;
        }

        if ($recipients === []) {
            return;
        }

        try {
            $this->notifications->sendToMany(
                array_values($recipients),
                Notification::TYPE_SETTLEMENT_COMPLETED,
                'Prize settlement completed',
                'Prize settlement for '.$tournament->name.' has completed.',
                NotificationService::link('tournaments.show', [$tournament]),
                [
                    'tournament_id' => $tournament->id,
                    'settlement_id' => $settlement->id,
                    'reconciliation_status' => $settlement->reconciliation_status,
                ],
            );
        } catch (Throwable $e) {
            report($e);

            throw $e;
        }
    }
}
