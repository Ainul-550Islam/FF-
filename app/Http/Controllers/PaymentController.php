<?php
namespace App\Http\Controllers;
use App\Models\Payment;
use App\Models\Team;
use App\Models\Tournament;
use App\Services\PaymentService;
use DomainException;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        protected PaymentService $payments,
    ) {
        $this->middleware(['auth', 'active']);
    }

    public function methods(Request $request)
    {
        $methods = [['id'=>'bkash','name'=>'bKash','type'=>'mobile'],['id'=>'nagad','name'=>'Nagad','type'=>'mobile'],['id'=>'rocket','name'=>'Rocket','type'=>'mobile'],['id'=>'manual','name'=>'Manual','type'=>'bank']];
        return view('payment.methods', compact('methods'));
    }

    /**
     * Entry-fee payment page for a team (Phase 08 canonical flow).
     *
     * An in-flight payment is reused instead of letting the user start a
     * duplicate one; the amount is always derived server-side by
     * PaymentService from the tournament's entry fee.
     */
    public function show(Tournament $tournament, Team $team)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('pay', $team);

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
     *
     * The server computes the amount from the tournament's entry fee; client
     * amounts are never accepted. A duplicate submission is answered with the
     * already-active payment instead of creating a second one.
     */
    public function verify(Request $request, Tournament $tournament, Team $team)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('pay', $team);

        $data = $request->validate([
            'bkash_number' => 'required|string|min:11|max:15',
            'trx_id' => 'required|string|max:40',
        ]);

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

    /**
     * The pending-payment page. The payment must belong to both the
     * tournament and the team in the URL.
     */
    public function pending(Tournament $tournament, Team $team, Payment $payment)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        abort_unless($payment->belongsToTournament($tournament) && $payment->belongsToTeam($team), 404);
        $this->authorize('view', $payment);

        return view('payment.pending', compact('tournament', 'team', 'payment'));
    }
}
