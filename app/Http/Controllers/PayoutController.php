<?php

namespace App\Http\Controllers;

use App\Models\Payout;
use App\Models\Tournament;
use App\Services\PayoutService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PayoutController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->input('status');
        $tournamentId = $request->input('tournament_id') ? (int) $request->input('tournament_id') : null;

        $statuses = ['pending', 'processing', 'completed', 'failed', 'cancelled', 'held'];
        $tournaments = Tournament::query()->orderBy('name')->get();

        $payouts = Payout::query()
            ->with(['recipient', 'tournament', 'team'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($tournamentId, fn ($q) => $q->where('tournament_id', $tournamentId))
            ->latest()
            ->paginate(25);

        return view('admin.payouts', compact('payouts', 'statuses', 'tournaments', 'status', 'tournamentId'));
    }

    public function approve(Payout $payout, PayoutService $service): RedirectResponse
    {
        try {
            $service->approve($payout, auth()->user());

            return back()->with('success', 'Payout approved.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function process(Payout $payout, PayoutService $service): RedirectResponse
    {
        try {
            $service->process($payout, auth()->user());

            return back()->with('success', 'Payout processed.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function processOverride(Request $request, Payout $payout, PayoutService $service): RedirectResponse
    {
        $request->validate(['reason' => 'required|string|min:3|max:500']);

        try {
            $service->processWithOverride($payout, auth()->user(), (string) $request->input('reason'));

            return back()->with('success', 'Payout processed with override.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function complete(Request $request, Payout $payout, PayoutService $service): RedirectResponse
    {
        $reference = $request->input('reference') ? (string) $request->input('reference') : null;

        try {
            $service->completeManually($payout, auth()->user(), $reference);

            return back()->with('success', 'Payout marked as completed.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function fail(Request $request, Payout $payout, PayoutService $service): RedirectResponse
    {
        $reason = (string) $request->input('reason', 'Marked failed by administrator');

        try {
            $service->markFailed($payout, auth()->user(), $reason);

            return back()->with('success', 'Payout marked as failed.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(Payout $payout, PayoutService $service): RedirectResponse
    {
        try {
            $service->cancel($payout, auth()->user());

            return back()->with('success', 'Payout cancelled.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
