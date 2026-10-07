<?php

namespace App\Http\Controllers;

use App\Models\LiveEvent;
use App\Models\Notification;
use App\Models\Team;
use App\Models\Tournament;
use App\Services\AuditLogService;
use App\Services\LiveEventService;
use App\Services\NotificationService;
use App\Services\PaymentGatewayManager;
use App\Services\PaymentService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Phase 08 checkout (browser flow) — `payment.methods` → `payment.initiate`.
 *
 * Money rules live in PaymentService: the amount is always derived from the
 * server-side entry fee, the payment starts `pending`, and a free-entry
 * tournament is auto-confirmed by the service itself. This controller owns
 * only the browser-facing decisions:
 *
 *  - which providers a payer may choose (the gateway registry, so an unknown
 *    provider is a validation error instead of a silent fallback),
 *  - whether the gateway can host the checkout (configured provider → redirect
 *    the payer to the provider; unconfigured → the manual TrxID flow on
 *    `payment.pending`),
 *  - the audit row, the payer notification and the account live event, which
 *    every payment entry point must write (the API entry point,
 *    Api\V1\PaymentController::store(), does the same three).
 */
class CheckoutController extends Controller
{
    public function methods(
        Tournament $tournament,
        Team $team,
        PaymentGatewayManager $gateways,
    ): View {
        $amountMinor = $tournament->entryFeeMinor();
        $providers = $gateways->statuses();

        return view('payment.methods', compact('tournament', 'team', 'amountMinor', 'providers'));
    }

    public function initiate(
        Request $request,
        Tournament $tournament,
        Team $team,
        PaymentService $payments,
        PaymentGatewayManager $gateways,
    ): RedirectResponse {
        // The provider must be one the platform actually supports; anything
        // else fails validation on `provider` rather than being coerced.
        $data = $request->validate([
            'provider' => 'required|string|in:'.implode(',', $gateways->providers()),
            'payment_method' => 'nullable|string|max:40',
            'trx_id' => 'nullable|string|max:64',
        ]);

        $provider = (string) $data['provider'];
        $method = (string) ($data['payment_method'] ?? $provider);
        $submittedTrx = trim((string) ($data['trx_id'] ?? ''));
        $trxId = $submittedTrx !== '' ? $submittedTrx : 'PENDING';

        try {
            $payment = $payments->createForTeam(
                $tournament,
                $team,
                $request->user(),
                $method,
                $trxId,
                $provider,
            );
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        // GAP-10 (integration defect found while fixing P0 routing): creating
        // the intent must also write the audit row, the payer notification and
        // the account live event — the API entry point
        // (Api\V1\PaymentController::store) already did all three, the browser
        // flow did none, so the payer's security timeline and notification
        // inbox silently missed every web payment.
        app(AuditLogService::class)->recordQuietly($request->user(), 'payment.initiated', 'payment', $payment->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['provider' => $provider, 'via' => 'web'],
        ]);

        app(NotificationService::class)->send(
            $request->user(),
            Notification::TYPE_PAYMENT_INITIATED,
            'Payment started',
            'Your entry fee payment for '.$tournament->name.' has been started.',
            NotificationService::link('payment.pending', [$tournament, $team, $payment]),
            ['payment_id' => $payment->id, 'provider' => $provider],
        );

        app(LiveEventService::class)->recordForUserQuietly(
            $request->user(),
            $request->user(),
            LiveEvent::TYPE_ACCOUNT_PAYMENT_STATUS,
            ['payment_id' => $payment->id, 'status' => $payment->status],
            $tournament,
        );

        // Free entry: PaymentService already settled the payment and
        // confirmed the team, so there is nothing to pay — send the payer back
        // to the tournament instead of a pending screen that can never
        // complete.
        if ($payment->isSuccessful()) {
            return redirect()
                ->route('tournaments.show', $tournament)
                ->with('success', 'Your team is registered for '.$tournament->name.'.');
        }

        // A configured provider hosts the checkout itself: the payer leaves
        // the platform for the provider page. An unconfigured provider (or a
        // provider without a hosted flow) falls back to the honest manual
        // TrxID flow — never a fabricated redirect.
        $redirectUrl = null;

        try {
            $result = $gateways->gateway($provider)->createExternalPayment($payment);
            $redirectUrl = $result['redirect_url'] ?? null;
        } catch (DomainException) {
            $redirectUrl = null;
        }

        if (is_string($redirectUrl) && $redirectUrl !== '') {
            return redirect()->away($redirectUrl);
        }

        return redirect()->route('payment.pending', [$tournament, $team, $payment]);
    }
}
