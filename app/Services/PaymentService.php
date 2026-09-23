<?php

namespace App\Services;

use App\Models\LedgerEntry;
use App\Models\MarketingAutomation;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Refund;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Payment lifecycle + financial integrity (Phase 08).
 *
 * Single authority for payment intents, state transitions, manual/admin
 * verification, refunds and provider callbacks. Amounts are integer minor
 * units (poisha), always derived from the tournament — never from clients.
 */
class PaymentService
{
    public function __construct(
        protected PaymentGatewayManager $gateways,
        protected WalletService $wallets,
        protected NotificationService $notifications,
    ) {}

    /**
     * Create a payment intent for a team's entry fee.
     *
     * Idempotent per team: if the team already has an active (pending /
     * processing / paid / verified) payment, a DomainException is thrown so
     * the caller can redirect to the existing one.
     */
    public function createForTeam(
        Tournament $tournament,
        Team $team,
        User $payer,
        string $method,
        string $trxId,
        ?string $provider = null,
        ?string $providerReference = null,
    ): Payment {
        if (! $team->belongsToTournament($tournament)) {
            throw new DomainException('This team does not belong to this tournament.');
        }

        if (! $tournament->acceptsRegistration()) {
            throw new DomainException('Payment is no longer accepted for this tournament.');
        }

        if ($team->status !== Team::STATUS_PENDING) {
            throw new DomainException('This team is not awaiting payment.');
        }

        $existing = Payment::where('team_id', $team->id)
            ->whereIn('status', Payment::ACTIVE_STATUSES)
            ->first();

        if ($existing !== null) {
            throw new DomainException('This team already has an active payment.');
        }

        // The amount is always derived from the server-side entry fee.
        $minor = $tournament->entryFeeMinor();
        $provider = $provider ?? $this->gateways->defaultProvider();
        $this->gateways->gateway($provider); // throws on unknown provider
        $idempotencyKey = Str::uuid();

        return DB::transaction(function () use ($tournament, $team, $payer, $method, $trxId, $minor, $provider, $providerReference, $idempotencyKey) {
            $payment = new Payment();
            $payment->tournament_id = $tournament->id;
            $payment->team_id = $team->id;
            $payment->payer_user_id = $payer->id;
            $payment->amount_minor = $minor;
            $payment->amount = Money::toDecimal($minor);
            $payment->currency = 'BDT';
            $payment->method = $method;
            $payment->trx_id = strtoupper(trim($trxId));
            $payment->provider = $provider;
            // The legacy demo flow used the submitted trx id as the provider
            // reference until a real gateway issued one. The column is unique
            // (one row per provider reference), so that fallback is only used
            // while the value is still free — a placeholder trx id such as
            // "PENDING" must never collide with another team's row.
            $payment->provider_reference = $providerReference !== null
                ? strtoupper(trim($providerReference))
                : $this->freeProviderReference(strtoupper(trim($trxId)));
            $payment->idempotency_key = $idempotencyKey;
            $payment->status = Payment::STATUS_PENDING;
            $payment->save();

            $this->recordEvent($payment, $payer, PaymentEvent::EVENT_CREATED, $minor);

            // Free-entry tournaments are auto-confirmed (Phase 01–07 demo
            // behaviour preserved).
            if ($minor <= 0) {
                $this->settleSuccess($payment, Payment::STATUS_VERIFIED, $payer);
            }

            // Phase 15 — outbound webhook (best-effort).
            app(WebhookDispatcher::class)->dispatchQuietly('payment.created', [
                'payment_id' => $payment->id,
                'tournament_id' => $tournament->id,
                'team_id' => $team->id,
                'amount_minor' => $minor,
                'currency' => 'BDT',
                'provider' => $provider,
                'status' => $payment->status,
            ]);

            return $payment;
        });
    }

    /**
     * The legacy trx-id fallback for provider_reference, applied only while
     * the value is still free (the column is unique).
     */
    protected function freeProviderReference(string $reference): ?string
    {
        if ($reference === '') {
            return null;
        }

        return Payment::where('provider_reference', $reference)->exists() ? null : $reference;
    }

    /**
     * Admin/manual verification of a pending payment (demo bKash flow).
     */
    public function verifyManually(Payment $payment, User $admin): Payment
    {
        if (! in_array($payment->status, [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING], true)) {
            throw new DomainException('Only pending payments can be verified.');
        }

        return DB::transaction(function () use ($payment, $admin) {
            $this->settleSuccess($payment, Payment::STATUS_VERIFIED, $admin);

            return $payment;
        });
    }

    /**
     * Mark a pending/processing payment failed.
     */
    public function markFailed(Payment $payment, User $actor, string $reason = ''): Payment
    {
        if (! in_array($payment->status, [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING], true)) {
            throw new DomainException('This payment cannot be failed from its current state.');
        }

        return DB::transaction(function () use ($payment, $actor, $reason) {
            $payment->status = Payment::STATUS_FAILED;
            $payment->save();

            $this->recordEvent($payment, $actor, PaymentEvent::EVENT_FAILED, $payment->amountMinor(), ['reason' => $reason]);

            // Phase 11 — notify the payer.
            $payer = $payment->payer ?? $payment->team?->captain;

            if ($payer !== null) {
                $this->notifications->send(
                    $payer,
                    Notification::TYPE_PAYMENT_FAILED,
                    'Payment failed',
                    'Your entry fee payment for '.($payment->tournament?->name ?? 'a tournament').' was marked failed.',
                    NotificationService::link('teams.show', [$payment->tournament, $payment->team]),
                    ['payment_id' => $payment->id],
                );
            }

            // Phase 20/21 — marketing funnel measurement (quiet by contract).
            $this->recordMarketingConversion('payment_failed', $payment);

            return $payment;
        });
    }

    /**
     * Cancel a pending/processing payment.
     */
    public function cancel(Payment $payment, User $actor): Payment
    {
        if (! in_array($payment->status, [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING], true)) {
            throw new DomainException('This payment cannot be cancelled from its current state.');
        }

        return DB::transaction(function () use ($payment, $actor) {
            $payment->status = Payment::STATUS_CANCELLED;
            $payment->save();

            $this->recordEvent($payment, $actor, PaymentEvent::EVENT_CANCELLED, $payment->amountMinor());

            return $payment;
        });
    }

    /**
     * Refund a settled payment (full amount only), credit the payer's wallet,
     * and record the refund + ledger + audit trail. Idempotent: a second
     * refund of the same payment is rejected.
     */
    public function refund(Payment $payment, User $admin, string $reason): Refund
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('A refund reason is required.');
        }

        if (! $payment->isRefundable()) {
            throw new DomainException('Only settled payments can be refunded.');
        }

        $minor = $payment->amountMinor();

        return DB::transaction(function () use ($payment, $admin, $reason, $minor) {
            if (Refund::where('payment_id', $payment->id)->exists()) {
                throw new DomainException('This payment has already been refunded.');
            }

            $payment->status = Payment::STATUS_REFUNDED;
            $payment->refunded_at = now();
            $payment->save();

            $refund = new Refund();
            $refund->payment_id = $payment->id;
            $refund->amount_minor = $minor;
            $refund->currency = 'BDT';
            $refund->reason = $reason;
            $refund->processed_by = $admin->id;
            $refund->save();

            $this->recordEvent($payment, $admin, PaymentEvent::EVENT_REFUNDED, $minor, ['reason' => $reason]);

            // Credit the payer's wallet (when a payer account exists). This is
            // the platform-side representation of the refund; external gateway
            // refunds are NOT simulated.
            $payer = $payment->payer ?? $payment->team?->captain;

            if ($payer !== null) {
                $wallet = $this->wallets->walletFor($payer);
                $this->wallets->credit(
                    $wallet,
                    $minor,
                    LedgerEntry::TYPE_REFUND,
                    'Refund for tournament entry fee',
                    $admin,
                    'refund',
                    $refund->id,
                );

                // Phase 11 — notify the payer of the refund.
                $this->notifications->send(
                    $payer,
                    Notification::TYPE_PAYMENT_REFUNDED,
                    'Entry fee refunded',
                    'Your entry fee for '.($payment->tournament?->name ?? 'a tournament').' was refunded.',
                    NotificationService::link('wallet.index'),
                    ['payment_id' => $payment->id, 'refund_id' => $refund->id],
                );
            }

            return $refund;
        });
    }

    /**
     * Process a provider callback/webhook. Signature is verified against the
     * configured secret, then amount/currency/payment are validated and the
     * state transition applied. Fully idempotent: repeated or replayed
     * callbacks return the current state without double effects.
     */
    public function handleProviderCallback(string $provider, array $payload, string $signature, string $rawBody): Payment
    {
        if (! $this->verifySignature($rawBody, $signature)) {
            throw new DomainException('Invalid webhook signature.');
        }

        $paymentId = (int) ($payload['payment_id'] ?? 0);
        $reference = (string) ($payload['provider_reference'] ?? '');
        $amountMinor = (int) ($payload['amount_minor'] ?? 0);
        $currency = (string) ($payload['currency'] ?? 'BDT');
        $status = (string) ($payload['status'] ?? '');

        $payment = Payment::find($paymentId);

        if ($payment === null) {
            throw new DomainException('Unknown payment.', 404);
        }

        if ($payment->provider !== $provider) {
            throw new DomainException('Provider mismatch.', 404);
        }

        if ($reference !== '' && $payment->provider_reference !== null && $payment->provider_reference !== $reference) {
            throw new DomainException('Provider reference mismatch.', 400);
        }

        if ($payment->currency !== $currency) {
            throw new DomainException('Currency mismatch.', 400);
        }

        if ($payment->amountMinor() !== $amountMinor) {
            throw new DomainException('Amount mismatch.', 400);
        }

        if (! in_array($status, [Payment::STATUS_PAID, Payment::STATUS_FAILED], true)) {
            throw new DomainException('Invalid callback status.', 400);
        }

        // Idempotency: an already-settled payment simply reports its state.
        if ($payment->status === Payment::STATUS_PAID || $payment->status === Payment::STATUS_VERIFIED) {
            $this->recordEvent($payment, null, PaymentEvent::EVENT_CALLBACK, $amountMinor, ['duplicate' => true, 'reference' => $reference]);

            return $payment;
        }

        if (! in_array($payment->status, [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING], true)) {
            throw new DomainException('This payment is no longer actionable.', 400);
        }

        return DB::transaction(function () use ($payment, $status, $amountMinor, $reference) {
            if ($status === Payment::STATUS_PAID) {
                $this->settleSuccess($payment, Payment::STATUS_PAID, null);
            } else {
                $payment->status = Payment::STATUS_FAILED;
                $payment->save();

                $this->recordMarketingConversion('payment_failed', $payment);
            }

            $this->recordEvent($payment, null, PaymentEvent::EVENT_CALLBACK, $amountMinor, ['status' => $status, 'reference' => $reference]);

            return $payment;
        });
    }

    /**
     * Confirm a payment using a provider's authoritative server-side status
     * (Phase 20/G2). The caller re-queries the provider via
     * PaymentStatusQueryable and passes the normalized result here.
     *
     * Financial invariants are re-checked inside this method, never trusted
     * from the caller:
     *   - `status` must be 'completed';
     *   - currency must match the payment;
     *   - amount (when the provider reports one) must match the server-side
     *     amount to the paisa.
     *
     * Fully idempotent: repeated confirmations for an already-settled payment
     * record a duplicate event and return the current state without any
     * second effect.
     */
    public function confirmProviderPayment(Payment $payment, array $gatewayResult): Payment
    {
        if (($gatewayResult['status'] ?? '') !== 'completed') {
            throw new DomainException('The provider has not confirmed this payment.');
        }

        $currency = strtoupper((string) ($gatewayResult['currency'] ?? 'BDT'));

        if ($currency !== $payment->currency) {
            throw new DomainException('Currency mismatch.');
        }

        $reportedAmount = $gatewayResult['amount'] ?? null;

        if ($reportedAmount !== null && $reportedAmount !== '') {
            if ((string) $reportedAmount !== Money::toDecimal($payment->amountMinor())) {
                throw new DomainException('Amount mismatch.');
            }
        }

        $reference = (string) ($gatewayResult['reference'] ?? ($payment->provider_reference ?? ''));
        $gatewayTransactionId = isset($gatewayResult['gateway_transaction_id']) && $gatewayResult['gateway_transaction_id'] !== null
            ? strtoupper(trim((string) $gatewayResult['gateway_transaction_id']))
            : null;

        // Idempotency — an already-settled payment simply reports its state.
        if (in_array($payment->status, Payment::SUCCESS_STATUSES, true)) {
            $this->recordEvent($payment, null, PaymentEvent::EVENT_GATEWAY_CONFIRMED, $payment->amountMinor(), [
                'duplicate' => true,
                'reference' => $reference,
            ]);

            return $payment;
        }

        if (! in_array($payment->status, [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING], true)) {
            throw new DomainException('This payment is no longer actionable.');
        }

        return DB::transaction(function () use ($payment, $reference, $gatewayTransactionId) {
            if ($gatewayTransactionId !== null && $gatewayTransactionId !== '') {
                $payment->trx_id = $gatewayTransactionId;
            }

            if ($reference !== '') {
                $payment->provider_reference = $reference;
            }

            $payment->save();

            $this->settleSuccess($payment, Payment::STATUS_PAID, null);

            $this->recordEvent($payment, null, PaymentEvent::EVENT_GATEWAY_CONFIRMED, $payment->amountMinor(), [
                'reference' => $reference,
                'gateway_transaction_id' => $gatewayTransactionId,
            ]);

            return $payment;
        });
    }

    /**
     * Mark a pending/processing payment failed from a provider's authoritative
     * status (Phase 20/G2) — e.g. the payer abandoned the checkout or the
     * gateway reported a terminal failure.
     */
    public function markGatewayFailed(Payment $payment, string $reference = '', string $reason = ''): Payment
    {
        if (! in_array($payment->status, [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING], true)) {
            throw new DomainException('This payment cannot be failed from its current state.');
        }

        return DB::transaction(function () use ($payment, $reference, $reason) {
            $payment->status = Payment::STATUS_FAILED;

            if ($reference !== '') {
                $payment->provider_reference = $reference;
            }

            $payment->save();

            $this->recordEvent($payment, null, PaymentEvent::EVENT_GATEWAY_FAILED, $payment->amountMinor(), [
                'reference' => $reference,
                'reason' => $reason,
            ]);

            $payer = $payment->payer ?? $payment->team?->captain;

            if ($payer !== null) {
                $this->notifications->send(
                    $payer,
                    Notification::TYPE_PAYMENT_FAILED,
                    'Payment failed',
                    'Your entry fee payment for '.($payment->tournament?->name ?? 'a tournament').' was declined by the provider.',
                    NotificationService::link('teams.show', [$payment->tournament, $payment->team]),
                    ['payment_id' => $payment->id],
                );
            }

            $this->recordMarketingConversion('payment_failed', $payment);

            return $payment;
        });
    }

    /**
     * Verify an HMAC-SHA256 webhook signature against the configured secret.
     */
    public function verifySignature(string $rawBody, string $signature): bool
    {
        $secret = (string) config('services.payments.webhook_secret', '');

        if ($secret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Move a payment into a success state, timestamp it, confirm the team
     * (if still pending) and write the audit event.
     */
    protected function settleSuccess(Payment $payment, string $targetStatus, ?User $actor): void
    {
        $payment->status = $targetStatus;
        $payment->paid_at = now();
        $payment->save();

        $team = $payment->team;
        if ($team !== null && $team->status === Team::STATUS_PENDING) {
            $team->status = Team::STATUS_CONFIRMED;
            $team->save();
        }

        $event = $targetStatus === Payment::STATUS_PAID
            ? PaymentEvent::EVENT_PAID
            : PaymentEvent::EVENT_VERIFIED;

        $this->recordEvent($payment, $actor, $event, $payment->amountMinor());

        // Phase 11 — notify the payer that the payment was accepted.
        $payer = $payment->payer ?? $payment->team?->captain;

        if ($payer !== null) {
            $this->notifications->send(
                $payer,
                Notification::TYPE_PAYMENT_VERIFIED,
                'Payment verified',
                'Your entry fee payment for '.($payment->tournament?->name ?? 'a tournament').' was verified.',
                NotificationService::link('teams.show', [$payment->tournament, $payment->team]),
                ['payment_id' => $payment->id],
            );
        }

        // Phase 20/21 — mirror the revenue moment into the marketing funnel
        // (quiet by contract, never part of the payment transaction).
        $this->recordMarketingConversion('payment_success', $payment);
    }

    protected function recordEvent(Payment $payment, ?User $actor, string $event, int $amountMinor, array $metadata = []): void
    {
        $record = new PaymentEvent();
        $record->payment_id = $payment->id;
        $record->actor_id = $actor?->id;
        $record->event = $event;
        $record->amount_minor = $amountMinor;
        $record->currency = 'BDT';
        $record->reference = $payment->provider_reference;
        $record->metadata = $metadata;
        $record->save();
    }

    /**
     * Phase 20/21 — mirror the payment outcome into marketing_events so
     * attribution rows connect ad clicks to revenue moments, and let the
     * lifecycle automation engine react to failures (recovery nudges).
     *
     * Quiet by contract: a measurement failure must never fail (or roll
     * back) a payment, hence rescue() with reporting disabled and the
     * tracking service's own quiet-fail record().
     */
    protected function recordMarketingConversion(string $event, Payment $payment): void
    {
        rescue(function () use ($event, $payment): void {
            $payer = $payment->payer ?? $payment->team?->captain;

            app(MarketingTrackingService::class)->recordConversion($event, $payer, [
                'payment_id' => $payment->id,
                'tournament_id' => $payment->tournament_id,
                'amount_minor' => $payment->amountMinor(),
                'currency' => 'BDT',
            ]);

            // Phase 21 — lifecycle automation: a failed payment can fire a
            // recovery sequence (cooldown-ledgered). Never touches the
            // payment state machine — this whole closure is rescue-wrapped.
            if ($event === 'payment_failed') {
                app(MarketingAutomationService::class)->evaluate(
                    MarketingAutomation::TRIGGER_PAYMENT_FAILED,
                    $payer instanceof User ? $payer : null,
                    ['payment_id' => $payment->id]
                );
            }
        }, report: false);
    }
}
