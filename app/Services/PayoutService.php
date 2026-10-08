<?php

namespace App\Services;

use App\Exceptions\PayoutReviewRequiredException;
use App\Models\LedgerEntry;
use App\Models\Notification;
use App\Models\Payout;
use App\Models\PayoutEvent;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Payout lifecycle + wallet integration (Phase 09).
 *
 * The single authority for payout state transitions and disbursement.
 * Internal (wallet) payouts credit the recipient's wallet through
 * WalletService inside the same transaction that marks the payout completed,
 * so the payout record and the wallet/ledger can never diverge. External
 * (manual) payouts move to `processing` and are completed by hand — no
 * external success is ever faked.
 *
 * Maker-checker (GAP-10 A4): the administrator who approved a payout
 * through the payout queue cannot also disburse or complete it. The gate
 * lives here in the service — not in the HTTP policy — so web, console,
 * and distribution flows all share the one enforcement. Refusals are
 * recorded in the payout event trail and thrown as 403-coded
 * DomainExceptions, which the admin controller renders as a form error.
 *
 * Idempotency: processing an already-completed payout returns the current
 * state; the unique (distribution_id, rank) and idempotency-key constraints
 * are the race-condition backstops.
 */
class PayoutService
{
    public function __construct(
        protected WalletService $wallets,
        protected PayoutGatewayManager $gateways,
        protected FraudRiskService $risk,
        protected NotificationService $notifications,
    ) {}

    /**
     * Process an approved payout.
     *
     *  - Internal wallet provider: credit the recipient's wallet (with the
     *    matching ledger entry) and mark the payout completed, atomically.
     *  - Manual provider: move to `processing`; an admin completes it by hand
     *    (completeManually).
     *
     * The recipient's fraud risk is evaluated first (Phase 10): a payout that
     * requires review is HELD (PayoutReviewRequiredException) — it is never
     * silently paid to a flagged recipient, and never auto-confiscated. An
     * authorized admin can process it via processWithOverride().
     *
     * The fraud gate runs first (a held payout reports the hold);
     * maker-checker is enforced inside processInternal().
     */
    public function process(Payout $payout, User $actor): Payout
    {
        $action = $this->risk->evaluatePayout($payout);

        if ($action !== FraudRiskService::ACTION_ALLOW) {
            throw new PayoutReviewRequiredException(
                'This payout requires fraud review before it can be processed (recipient risk action: '.$action.').'
            );
        }

        return $this->processInternal($payout, $actor);
    }

    /**
     * Process a payout with an authorized fraud-review override. The override
     * is audited (reason + actor) so the exemption is always explainable.
     *
     * The override excuses fraud review, not the second administrator:
     * maker-checker still applies inside processInternal().
     */
    public function processWithOverride(Payout $payout, User $actor, string $reason): Payout
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('An override reason is required.');
        }

        $this->recordEvent($payout, $actor, PayoutEvent::EVENT_PROCESSING, $payout->amountMinor(), [
            'override' => true,
            'reason' => $reason,
        ]);

        return $this->processInternal($payout, $actor);
    }

    /**
     * The shared disbursement path (fraud gate already applied by process();
     * dual control is enforced below for both entry points).
     */
    protected function processInternal(Payout $payout, User $actor): Payout
    {
        // GAP-10 A4 maker-checker. Covers the internal-credit path AND the
        // external advanceToProcessing() path below, for both process() and
        // processWithOverride(). Runs before the money transaction so a
        // refusal records its violation event without touching money — see
        // assertDualControl() for why this pre-check cannot race.
        $this->assertDualControl($payout, $actor, 'process');

        $gateway = $this->gateways->gateway($payout->provider);

        if (! $gateway->isInternal()) {
            return $this->advanceToProcessing($payout, $actor);
        }

        if ($payout->recipient_user_id === null) {
            throw new DomainException('This payout has no recipient.');
        }

        return DB::transaction(function () use ($payout, $actor) {
            $fresh = Payout::query()->where('id', $payout->id)->lockForUpdate()->firstOrFail();

            // Idempotency: a completed payout is simply reported back.
            if ($fresh->status === Payout::STATUS_COMPLETED) {
                return $fresh;
            }

            if (! in_array($fresh->status, [Payout::STATUS_APPROVED, Payout::STATUS_PROCESSING], true)) {
                throw new DomainException('Only approved payouts can be processed.');
            }

            $recipient = $fresh->recipient;

            if ($recipient === null) {
                throw new DomainException('This payout has no recipient.');
            }

            $fresh->status = Payout::STATUS_PROCESSING;
            $fresh->save();

            // The wallet credit and its ledger entry commit atomically with
            // the payout state below — the two can never diverge.
            $wallet = $this->wallets->walletFor($recipient);

            $this->wallets->credit(
                $wallet,
                $fresh->amountMinor(),
                LedgerEntry::TYPE_PAYOUT,
                'Prize payout — '.($fresh->tournament?->name ?? 'Tournament').' ('.$this->ordinal((int) $fresh->rank).' place)',
                $actor,
                'payout',
                $fresh->id,
            );

            $fresh->status = Payout::STATUS_COMPLETED;
            $fresh->processed_by = $actor->id;
            $fresh->processed_at = now();
            $fresh->save();

            $this->recordEvent($fresh, $actor, PayoutEvent::EVENT_COMPLETED, $fresh->amountMinor());

            // Phase 11 — notify the recipient.
            $this->notifications->send(
                $recipient,
                Notification::TYPE_PAYOUT_PROCESSED,
                'Prize payout received',
                'You received '.$this->moneyLabel($fresh->amountMinor()).' for '.($fresh->tournament?->name ?? 'a tournament').'.',
                NotificationService::link('wallet.index'),
                ['payout_id' => $fresh->id, 'amount_minor' => $fresh->amountMinor()],
            );

            return $fresh;
        });
    }

    /**
     * Manually complete an external (non-internal) payout, recording the
     * provider reference. Internal wallet payouts complete automatically
     * during process() and can never be completed by hand.
     *
     * GAP-10 A4: the queue maker cannot complete (dual control), and the
     * reviewed external reference is required + length-bounded — it is the
     * only proof the money left through the external provider.
     */
    public function completeManually(Payout $payout, User $actor, ?string $reference = null): Payout
    {
        // Dual control BEFORE the money transaction: a refusal must leave
        // the payout untouched while still recording the violation (a throw
        // inside the transaction below would roll the violation row back).
        // The approval-event set is stable once processing starts (approve()
        // only fires on STATUS_PENDING), so this pre-check cannot race.
        $this->assertDualControl($payout, $actor, 'complete');

        $gateway = $this->gateways->gateway($payout->provider);

        if ($gateway->isInternal()) {
            throw new DomainException('Internal wallet payouts complete automatically during processing.');
        }

        $reference = $reference !== null ? trim($reference) : null;

        if ($reference === null || $reference === '') {
            throw new DomainException('A reviewed external reference is required to complete a manual payout.');
        }

        $maxLength = (int) config('payments.payout_reference_max_length', 255);

        if (mb_strlen($reference) > $maxLength) {
            throw new DomainException("The reviewed external reference must be at most {$maxLength} characters.");
        }

        return DB::transaction(function () use ($payout, $actor, $reference) {
            $fresh = Payout::query()->where('id', $payout->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status === Payout::STATUS_COMPLETED) {
                return $fresh;
            }

            if ($fresh->status !== Payout::STATUS_PROCESSING) {
                throw new DomainException('Only processing payouts can be marked completed.');
            }

            $fresh->status = Payout::STATUS_COMPLETED;
            $fresh->processed_by = $actor->id;
            $fresh->processed_at = now();
            $fresh->provider_reference = $reference;
            $fresh->save();

            $this->recordEvent($fresh, $actor, PayoutEvent::EVENT_COMPLETED, $fresh->amountMinor(), [
                'reference' => $reference,
                'maker_user_id' => $this->queueMaker($fresh),
                'checker_user_id' => (int) $actor->id,
            ]);

            return $fresh;
        });
    }

    /**
     * Approve a pending payout.
     */
    public function approve(Payout $payout, User $actor): Payout
    {
        if ($payout->status !== Payout::STATUS_PENDING) {
            throw new DomainException('Only pending payouts can be approved.');
        }

        return DB::transaction(function () use ($payout, $actor) {
            $fresh = Payout::query()->where('id', $payout->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status === Payout::STATUS_APPROVED) {
                return $fresh;
            }

            if ($fresh->status !== Payout::STATUS_PENDING) {
                throw new DomainException('Only pending payouts can be approved.');
            }

            $fresh->status = Payout::STATUS_APPROVED;
            $fresh->approved_by = $actor->id;
            $fresh->save();

            $this->recordEvent($fresh, $actor, PayoutEvent::EVENT_APPROVED, $fresh->amountMinor());

            return $fresh;
        });
    }

    /**
     * Mark a payout failed with a reason.
     */
    public function markFailed(Payout $payout, User $actor, string $reason = ''): Payout
    {
        if (! in_array($payout->status, [Payout::STATUS_PENDING, Payout::STATUS_APPROVED, Payout::STATUS_PROCESSING], true)) {
            throw new DomainException('This payout cannot be failed from its current state.');
        }

        return DB::transaction(function () use ($payout, $actor, $reason) {
            // AUDIT FIX (2026-10-07, FIX-07): markFailed/cancel previously
            // mutated the passed (possibly stale, unlocked) model. A payout
            // that concurrently COMPLETED could be overwritten back to
            // failed/cancelled while the wallet credit stands — ledger and
            // payout diverge. Lock + re-check like every other transition.
            $payout = Payout::query()->where('id', $payout->id)->lockForUpdate()->firstOrFail();

            if (! in_array($payout->status, [Payout::STATUS_PENDING, Payout::STATUS_APPROVED, Payout::STATUS_PROCESSING], true)) {
                throw new DomainException('This payout cannot be failed from its current state.');
            }

            $payout->status = Payout::STATUS_FAILED;
            $payout->failure_reason = $reason !== '' ? $reason : null;
            $payout->save();

            $this->recordEvent($payout, $actor, PayoutEvent::EVENT_FAILED, $payout->amountMinor(), ['reason' => $reason]);

            // Phase 11 — notify the recipient.
            $recipient = $payout->recipient;

            if ($recipient !== null) {
                $this->notifications->send(
                    $recipient,
                    Notification::TYPE_PAYOUT_FAILED,
                    'Prize payout failed',
                    'A prize payout for '.($payout->tournament?->name ?? 'a tournament').' could not be processed.',
                    NotificationService::link('wallet.index'),
                    ['payout_id' => $payout->id],
                );
            }

            return $payout;
        });
    }

    /**
     * Cancel a payout that has not started paying out.
     */
    public function cancel(Payout $payout, User $actor): Payout
    {
        if (! in_array($payout->status, [Payout::STATUS_PENDING, Payout::STATUS_APPROVED], true)) {
            throw new DomainException('This payout cannot be cancelled from its current state.');
        }

        return DB::transaction(function () use ($payout, $actor) {
            // AUDIT FIX-07: lock + re-check (see markFailed).
            $payout = Payout::query()->where('id', $payout->id)->lockForUpdate()->firstOrFail();

            if (! in_array($payout->status, [Payout::STATUS_PENDING, Payout::STATUS_APPROVED], true)) {
                throw new DomainException('This payout cannot be cancelled from its current state.');
            }

            $payout->status = Payout::STATUS_CANCELLED;
            $payout->save();

            $this->recordEvent($payout, $actor, PayoutEvent::EVENT_CANCELLED, $payout->amountMinor());

            return $payout;
        });
    }

    /**
     * Move an external payout into processing (no disbursement yet).
     *
     * Only called from processInternal(), after the dual-control gate.
     */
    protected function advanceToProcessing(Payout $payout, User $actor): Payout
    {
        return DB::transaction(function () use ($payout, $actor) {
            $fresh = Payout::query()->where('id', $payout->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status === Payout::STATUS_COMPLETED) {
                return $fresh;
            }

            if (! in_array($fresh->status, [Payout::STATUS_APPROVED, Payout::STATUS_PROCESSING], true)) {
                throw new DomainException('Only approved payouts can be processed.');
            }

            $fresh->status = Payout::STATUS_PROCESSING;
            $fresh->save();

            $this->recordEvent($fresh, $actor, PayoutEvent::EVENT_PROCESSING, $fresh->amountMinor());

            return $fresh;
        });
    }

    /**
     * GAP-10 A4 — the queue maker of a payout, if it has one.
     *
     * The maker is read from the payout's own queue-approval event (written
     * by approve()), never from the approved_by column: batch payouts
     * created already-approved by PrizeDistributionService carry the
     * distribution admin in approved_by, but their maker is the separately
     * audited distribution approval, not the payout queue. Returns null —
     * exempt from dual control — when there is no approval event at all
     * (legacy / fixture data) or when the latest approval event is marked
     * as a distribution batch (PrizeDistributionService::approve()).
     */
    protected function queueMaker(Payout $payout): ?int
    {
        $approval = PayoutEvent::query()
            ->where('payout_id', $payout->id)
            ->where('event', PayoutEvent::EVENT_APPROVED)
            ->orderByDesc('id')
            ->first();

        if ($approval === null) {
            return null;
        }

        if (($approval->metadata['distribution_batch'] ?? false) === true) {
            return null;
        }

        return $approval->actor_id !== null ? (int) $approval->actor_id : null;
    }

    /**
     * GAP-10 A4 — refuse when the queue maker tries to disburse.
     *
     * Applies when the payout carries a queue maker (see queueMaker()) AND
     * its amount reaches `payments.payout_dual_control_threshold_minor`
     * (default 0: every queued payout is gated). Amounts below the threshold
     * are explicitly relaxed by configuration. A refusal is recorded as a
     * `payout.processing` event with `dual_control_violation` metadata and
     * then thrown as a 403-coded DomainException, which the admin controller
     * renders as a form error (never a stack trace).
     */
    protected function assertDualControl(Payout $payout, User $actor, string $stage): void
    {
        $maker = $this->queueMaker($payout);

        if ($maker === null) {
            return;
        }

        $threshold = (int) config('payments.payout_dual_control_threshold_minor', 0);

        if ($payout->amountMinor() < $threshold) {
            return;
        }

        if ($maker !== (int) $actor->id) {
            return;
        }

        $this->recordEvent($payout, $actor, PayoutEvent::EVENT_PROCESSING, $payout->amountMinor(), [
            'dual_control_violation' => true,
            'maker_user_id' => $maker,
            'checker_user_id' => (int) $actor->id,
            'stage' => $stage,
        ]);

        throw new DomainException(
            'Maker-checker control: the administrator who approved this payout cannot also '.$stage.' it.',
            403
        );
    }

    /**
     * Append a row to the payout audit trail.
     */
    public function recordEvent(Payout $payout, ?User $actor, string $event, int $amountMinor, array $metadata = []): PayoutEvent
    {
        $record = new PayoutEvent();
        $record->payout_id = $payout->id;
        $record->actor_id = $actor?->id;
        $record->event = $event;
        $record->amount_minor = $amountMinor;
        $record->currency = 'BDT';
        $record->metadata = $metadata;
        $record->save();

        return $record;
    }

    /**
     * Display-only minor-units (poisha) formatter. Integer money only — this
     * never participates in money arithmetic.
     */
    protected function moneyLabel(int $amountMinor): string
    {
        return '৳'.number_format($amountMinor / 100, 2);
    }

    /**
     * English ordinal for display ("1st", "2nd", "3rd", "4th", …).
     */
    protected function ordinal(int $rank): string
    {
        $suffixes = ['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'];

        if (($rank % 100) >= 11 && ($rank % 100) <= 13) {
            return $rank.'th';
        }

        return $rank.$suffixes[$rank % 10];
    }
}
