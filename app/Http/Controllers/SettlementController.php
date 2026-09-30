<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Services\PrizeDistributionService;
use App\Services\ReconciliationService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettlementController extends Controller
{
    public function index(ReconciliationService $reconciliation): View
    {
        $tournaments = Tournament::query()
            ->with(['organizer', 'financialSettlement'])
            ->latest()
            ->paginate(20);

        $summaries = [];
        foreach ($tournaments as $t) {
            $summaries[$t->id] = $reconciliation->summary($t);
        }

        return view('admin.settlements', compact('tournaments', 'summaries'));
    }

    public function show(
        Tournament $tournament,
        ReconciliationService $reconciliation,
        PrizeDistributionService $prizeService,
    ): View {
        $summary = $reconciliation->summary($tournament);
        $tiers = $prizeService->tiers($tournament);
        $distribution = $prizeService->latestDistribution($tournament);
        $adjustments = $tournament->settlementAdjustments()->latest()->get();

        return view('admin.settlement', compact('tournament', 'summary', 'tiers', 'distribution', 'adjustments'));
    }

    public function showByTournament(
        Tournament $tournament,
        ReconciliationService $reconciliation,
        PrizeDistributionService $prizeService,
    ): View {
        return $this->show($tournament, $reconciliation, $prizeService);
    }

    public function storePrizeTiers(
        Request $request,
        Tournament $tournament,
        PrizeDistributionService $prizeService,
    ): RedirectResponse {
        $user = auth()->user();

        if (! $user || ! $user->isAdmin()) {
            abort(403);
        }

        $rows = $request->input('tiers', []);

        try {
            $prizeService->saveTiers($tournament, is_array($rows) ? $rows : [], $user);

            return back()->with('success', 'Prize tiers updated.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function calculate(
        Tournament $tournament,
        PrizeDistributionService $prizeService,
    ): RedirectResponse {
        $user = auth()->user();

        if (! $user || ! $user->isAdmin()) {
            abort(403);
        }

        try {
            $prizeService->calculate($tournament, $user);

            return back()->with('success', 'Prize distribution calculated.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function approve(
        Tournament $tournament,
        PrizeDistributionService $prizeService,
    ): RedirectResponse {
        $user = auth()->user();

        if (! $user || ! $user->isAdmin()) {
            abort(403);
        }

        try {
            $prizeService->approve($tournament, $user);

            return back()->with('success', 'Prize distribution approved.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function process(
        Tournament $tournament,
        PrizeDistributionService $prizeService,
    ): RedirectResponse {
        $user = auth()->user();

        if (! $user || ! $user->isAdmin()) {
            abort(403);
        }

        try {
            $prizeService->process($tournament, $user);

            return back()->with('success', 'Prize distribution processed into payouts.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(
        Tournament $tournament,
        PrizeDistributionService $prizeService,
    ): RedirectResponse {
        $user = auth()->user();

        if (! $user || ! $user->isAdmin()) {
            abort(403);
        }

        try {
            $prizeService->cancel($tournament, $user);

            return back()->with('success', 'Prize distribution cancelled.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function adjust(
        Request $request,
        Tournament $tournament,
        ReconciliationService $reconciliation,
    ): RedirectResponse {
        $user = auth()->user();

        if (! $user || ! $user->isAdmin()) {
            abort(403);
        }

        $request->validate([
            'amount_minor' => 'nullable|integer',
            'amount' => 'nullable|numeric',
            'type' => 'required|string',
            'reason' => 'required|string|max:255',
        ]);

        $amountMinor = $request->input('amount_minor') !== null
            ? (int) $request->input('amount_minor')
            : (int) round(((float) $request->input('amount')) * 100);

        try {
            $reconciliation->addAdjustment(
                $tournament,
                $amountMinor,
                (string) $request->input('type'),
                (string) $request->input('reason'),
                $user,
            );

            return back()->with('success', 'Settlement adjustment recorded.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
