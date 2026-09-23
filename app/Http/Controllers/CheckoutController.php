<?php

namespace App\Http\Controllers;

use App\Models\LiveEvent;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Team;
use App\Models\Tournament;
use App\Services\AuditLogService;
use App\Services\FraudRiskService;
use App\Services\LiveEventService;
use App\Services\NotificationService;
use App\Services\PaymentGatewayManager;
use App\Services\PaymentService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Provider selection + checkout initiation (Phase 14).
 *
 * The legacy manual bKash form (PaymentController::verify) is preserved;
 * this controller adds a provider grid with honest per-provider status and a
 * single initiation endpoint that routes through the Phase 08 PaymentService.
 * A payment is only ever "successful" after server-side verification —
 * never from the frontend.
 */
class CheckoutController extends Controller
{
    public function __construct(
        protected PaymentService $payments,
        protected PaymentGatewayManager $gateways,
        protected FraudRiskService $risk,
        protected NotificationService $notifications,
        protected LiveEventService $live,
        protected AuditLogService $audit,
    ) {}

    /**
     * Choose a payment method for a team's entry fee.
     */
    public function methods(Tournament $tournament, Team $team)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('pay', $team);

        $existing = Payment::where('team_id', $team->id)
            ->whereIn('status', Payment::ACTIVE_STATUSES)
            ->first();

        if ($existing !== null) {
            return redirect()->route('payment.pending', [$tournament, $team, $existing]);
        }

        return view('payment.methods', [
            'tournament' => $tournament,
            'team' => $team,
            'providers' => $this->gateways->enabledProviders(),
            'amountMinor' => $tournament->entryFeeMinor(),
        ]);
    }

    /**
     * Start a payment with the chosen provider.
     */
    public function initiate(Request $request, Tournament $tournament, Team $team)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('pay', $team);

        $data = $request->validate([
            'provider' => 'required|in:'.implode(',', $this->gateways->providers()),
            'trx_id' => 'nullable|string|max:40',
        ]);

        $provider = $data['provider'];
        $gateway = $this->gateways->gateway($provider);
        $trxId = trim((string) ($data['trx_id'] ?? ''));

        // Phase 10 — fraud/risk gate for payment creation.
        try {
            $this->risk->evaluatePayment($request->user(), $tournament);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        try {
            $payment = $this->payments->createForTeam(
                $tournament,
                $team,
                $request->user(),
                $provider,
                $trxId !== '' ? $trxId : 'PENDING',
                $provider,
                $trxId !== '' ? $trxId : null,
            );
        } catch (DomainException $e) {
            $existing = Payment::where('team_id', $team->id)
                ->whereIn('status', Payment::ACTIVE_STATUSES)
                ->first();

            if ($existing !== null) {
                return redirect()->route('payment.pending', [$tournament, $team, $existing]);
            }

            return back()->with('error', $e->getMessage());
        }

        // Phase 13/14 — audit + notification + user live event.
        $this->audit->recordQuietly($request->user(), 'payment.initiated', 'payment', $payment->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['provider' => $provider],
        ]);

        $this->notifications->send(
            $request->user(),
            Notification::TYPE_PAYMENT_INITIATED,
            'Payment started',
            'Your entry fee payment for '.$tournament->name.' has been started ('.$gateway->label().').',
            NotificationService::link('payment.pending', [$tournament, $team, $payment]),
            ['payment_id' => $payment->id, 'provider' => $provider],
        );

        $this->live->recordForUserQuietly($request->user(), $request->user(), LiveEvent::TYPE_ACCOUNT_PAYMENT_STATUS, [
            'payment_id' => $payment->id,
            'status' => $payment->status,
        ], $tournament);

        // Free entry → already confirmed.
        if ($payment->isSuccessful()) {
            return redirect()
                ->route('tournaments.show', $tournament)
                ->with('success', 'Registration confirmed! Your team is in.');
        }

        // Hosted/redirect providers: ask the gateway for a checkout session.
        // A gateway that is not configured (or has no hosted checkout) throws
        // a DomainException — the payer then continues on the manual pending
        // screen instead of being sent to a dead provider page.
        try {
            $result = $gateway->createExternalPayment($payment);

            if (! empty($result['redirect_url'])) {
                return redirect()->away($result['redirect_url']);
            }
        } catch (DomainException $e) {
            return redirect()
                ->route('payment.pending', [$tournament, $team, $payment])
                ->with('error', $e->getMessage());
        }

        return redirect()->route('payment.pending', [$tournament, $team, $payment]);
    }
}
