<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\Tournament;
use App\Services\PaymentGatewayManager;
use App\Services\PaymentService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

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
    ): RedirectResponse {
        $provider = (string) $request->input('provider', 'bkash');
        $method = (string) $request->input('payment_method', $provider);
        $trxId = (string) ($request->input('trx_id') ?: Str::uuid());

        try {
            $payment = $payments->createForTeam(
                $tournament,
                $team,
                $request->user(),
                $method,
                $trxId,
                $provider,
            );

            return redirect()->route('payment.pending', [$tournament, $team, $payment]);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
