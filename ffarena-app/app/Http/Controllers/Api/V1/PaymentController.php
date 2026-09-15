<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PaymentMethodResource;
use App\Http\Resources\Api\V1\PaymentResource;
use App\Models\LiveEvent;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Team;
use App\Services\AuditLogService;
use App\Services\FraudRiskService;
use App\Services\LiveEventService;
use App\Services\NotificationService;
use App\Services\PaymentGatewayManager;
use App\Services\PaymentMethodService;
use App\Services\PaymentService;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 15 — payment methods + payment initiation.
 *
 * The amount, currency, payer, team and tournament are all server-derived;
 * the client selects a provider only. Success is only ever the result of
 * server-side verification (Phase 08 PaymentService).
 */
class PaymentController extends Controller
{
    public function __construct(
        protected PaymentService $payments,
        protected PaymentGatewayManager $gateways,
        protected PaymentMethodService $methods,
        protected FraudRiskService $risk,
        protected NotificationService $notifications,
        protected LiveEventService $live,
        protected AuditLogService $audit,
    ) {
    }

    /**
     * GET /api/v1/payments/methods — honest per-provider status + saved methods.
     */
    public function methods(Request $request): JsonResponse
    {
        return ApiResponse::data([
            'providers' => $this->gateways->statuses(),
            'saved_methods' => PaymentMethodResource::collection($this->methods->listFor($request->user())),
        ]);
    }

    /**
     * POST /api/v1/payments
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'team_id' => 'required|integer|exists:teams,id',
            'provider' => 'required|in:' . implode(',', $this->gateways->providers()),
        ]);

        $team = Team::find($data['team_id']);
        $tournament = $team?->tournament;

        if ($team === null || $tournament === null || ! $team->belongsToTournament($tournament)) {
            return ApiResponse::error('not_found', 'Team not found.', [], 404);
        }

        $this->authorize('pay', $team);

        $provider = $data['provider'];
        $gateway = $this->gateways->gateway($provider);

        // Phase 10 — fraud/risk gate for payment creation.
        try {
            $this->risk->evaluatePayment($request->user(), $tournament);
        } catch (DomainException $e) {
            return ApiResponse::error('payment_refused', $e->getMessage(), [], 422);
        }

        try {
            $payment = $this->payments->createForTeam(
                $tournament,
                $team,
                $request->user(),
                $provider,
                'PENDING',
                $provider,
                null,
            );
        } catch (DomainException $e) {
            $existing = Payment::where('team_id', $team->id)
                ->whereIn('status', Payment::ACTIVE_STATUSES)
                ->first();

            if ($existing !== null) {
                return ApiResponse::data(new PaymentResource($existing), ['existing' => true]);
            }

            return ApiResponse::error('payment_refused', $e->getMessage(), [], 422);
        }

        // Phase 13/14 — audit + notification + user live event.
        $this->audit->recordQuietly($request->user(), 'payment.initiated', 'payment', $payment->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['provider' => $provider, 'via' => 'api'],
        ]);

        $this->notifications->send(
            $request->user(),
            Notification::TYPE_PAYMENT_INITIATED,
            'Payment started',
            'Your entry fee payment for ' . $tournament->name . ' has been started (' . $gateway->label() . ').',
            NotificationService::link('payment.pending', [$tournament, $team, $payment]),
            ['payment_id' => $payment->id, 'provider' => $provider],
        );

        $this->live->recordForUserQuietly($request->user(), $request->user(), LiveEvent::TYPE_ACCOUNT_PAYMENT_STATUS, [
            'payment_id' => $payment->id,
            'status' => $payment->status,
        ], $tournament);

        $redirectUrl = null;

        if (! $payment->isSuccessful() && ! in_array($provider, ['bkash', 'nagad', 'rocket', 'bank'], true)) {
            try {
                $result = $gateway->createExternalPayment($payment);
                $redirectUrl = $result['redirect_url'] ?? null;
            } catch (DomainException $e) {
                $redirectUrl = null;
            }
        }

        return ApiResponse::created([
            'payment' => new PaymentResource($payment),
            'redirect_url' => $redirectUrl,
        ]);
    }

    /**
     * GET /api/v1/payments/{payment}
     */
    public function show(Request $request, Payment $payment): JsonResponse
    {
        $this->authorize('view', $payment);

        return ApiResponse::data(new PaymentResource($payment));
    }
}
