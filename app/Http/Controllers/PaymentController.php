<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Team;
use App\Models\Tournament;
use App\Services\FraudRiskService;
use App\Services\PaymentService;
use DomainException;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        protected PaymentService $payments,
        protected FraudRiskService $risk,
    ) {
    }

    /**
     * bKash payment page for a team's entry fee.
     */
    public function show(Tournament $tournament, Team $team)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('pay', $team);

        // Reuse the existing pending payment instead of letting the user
        // start a duplicate one.
        $existing = Payment::where('team_id', $team->id)
            ->whereIn('status', Payment::ACTIVE_STATUSES)
            ->first();

        if ($existing !== null) {
            return redirect()->route('payment.pending', [$tournament, $team, $existing]);
        }

        return view('payment.show', compact('tournament', 'team'));
    }

    /**
     * Simulated bKash "Send Money" verification.
     * The server computes the amount from the tournament's entry fee; client
     * amounts are never accepted.
     */
    public function verify(Request $request, Tournament $tournament, Team $team)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('pay', $team);

        $data = $request->validate([
            'bkash_number' => 'required|string|min:11|max:15',
            'trx_id' => 'required|string|max:40',
        ]);

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
                'bkash',
                $data['trx_id'],
            );
        } catch (DomainException $e) {
            // Duplicate active payment → point at the existing one.
            $existing = Payment::where('team_id', $team->id)
                ->whereIn('status', Payment::ACTIVE_STATUSES)
                ->first();

            if ($existing !== null) {
                return redirect()->route('payment.pending', [$tournament, $team, $existing]);
            }

            return back()->with('error', $e->getMessage());
        }

        if ($payment->isSuccessful()) {
            return redirect()
                ->route('tournaments.show', $tournament)
                ->with('success', 'Registration confirmed! Your team is in.');
        }

        return redirect()->route('payment.pending', [$tournament, $team, $payment]);
    }

    public function pending(Tournament $tournament, Team $team, Payment $payment)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        abort_unless($payment->belongsToTournament($tournament) && $payment->belongsToTeam($team), 404);
        $this->authorize('view', $payment);

        return view('payment.pending', compact('tournament', 'team', 'payment'));
    }
}
