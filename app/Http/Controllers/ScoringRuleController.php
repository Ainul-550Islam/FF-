<?php

namespace App\Http\Controllers;

use App\Models\ScoringRule;
use App\Models\Tournament;
use App\Services\ScoringService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ScoringRuleController extends Controller
{
    public function show(Tournament $tournament, ScoringService $scoring): View
    {
        $user = auth()->user();

        if (! $user || (! $user->isAdmin() && $tournament->organizer_id !== $user->id)) {
            abort(403);
        }

        $current = $scoring->currentRuleSet($tournament);
        $rules = $tournament->scoringRules()->orderByDesc('version')->get();

        return view('tournaments.scoring', compact('tournament', 'current', 'rules'));
    }

    public function store(
        Request $request,
        Tournament $tournament,
        ScoringService $scoring,
    ): RedirectResponse {
        $user = auth()->user();

        if (! $user || (! $user->isAdmin() && $tournament->organizer_id !== $user->id)) {
            abort(403);
        }

        $allowedTieBreakers = implode(',', array_keys(ScoringRule::TIE_BREAKER_OPTIONS));

        $data = $request->validate([
            'name' => 'nullable|string|max:120',
            'kill_points' => 'required|integer|min:0|max:1000',
            'placement_points' => 'nullable|array',
            'placement_points.*' => 'integer|min:0|max:1000',
            'tie_breakers' => 'nullable|array',
            'tie_breakers.*' => 'nullable|string|in:'.$allowedTieBreakers,
            'tie_breaker_order' => 'nullable|array',
            'tie_breaker_order.*' => 'nullable|string|in:'.$allowedTieBreakers,
        ]);

        $tieBreakers = $data['tie_breaker_order'] ?? $data['tie_breakers'] ?? [];
        $tieBreakers = array_values(array_filter($tieBreakers));

        try {
            $scoring->createVersion($tournament, [
                'name' => $data['name'] ?? null,
                'kill_points' => (int) $data['kill_points'],
                'placement_points' => $data['placement_points'] ?? ScoringRule::DEFAULT_PLACEMENT_POINTS,
                'tie_breakers' => $tieBreakers,
            ]);

            return back()->with('success', 'Scoring rules version created and activated.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function activate(
        Tournament $tournament,
        ScoringRule $rule,
        ScoringService $scoring,
    ): RedirectResponse {
        $user = auth()->user();

        if (! $user || (! $user->isAdmin() && $tournament->organizer_id !== $user->id)) {
            abort(403);
        }

        if ($rule->tournament_id !== $tournament->id) {
            abort(404);
        }

        try {
            $scoring->activateVersion($tournament, $rule);

            return back()->with('success', "Rule version v{$rule->version} activated.");
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
