<?php

namespace App\Http\Controllers;

use App\Models\ScoringRule;
use App\Models\Tournament;
use App\Services\ScoringService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Organizer/admin scoring-rule configuration (Phase 06).
 *
 * Only the owning organizer or an admin may view or change a tournament's
 * scoring rules. Rule edits always create a new immutable version; they never
 * rewrite historical scores.
 */
class ScoringRuleController extends Controller
{
    public function __construct(
        protected ScoringService $scoring,
    ) {}

    public function show(Tournament $tournament)
    {
        $this->authorize('manageScoring', $tournament);

        $rules = $tournament->scoringRules()->orderByDesc('version')->get();
        $current = $this->scoring->currentRuleSet($tournament);

        return view('tournaments.scoring', compact('tournament', 'rules', 'current'));
    }

    public function store(Request $request, Tournament $tournament)
    {
        $this->authorize('manageScoring', $tournament);

        $data = $request->validate([
            'name' => 'nullable|string|max:255',
            'kill_points' => 'required|integer|min:0|max:1000',
            'placement_points' => 'required|array',
            'placement_points.*' => 'required|integer|min:0|max:1000',
            'tie_breakers' => 'nullable|array',
            'tie_breakers.*' => 'nullable|string|in:points,placement_points,kill_points,kills,best_placement',
        ]);

        try {
            $rule = $this->scoring->createVersion($tournament, $data);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Scoring rules version {$rule->version} created and activated.");
    }

    public function activate(Request $request, Tournament $tournament, ScoringRule $rule)
    {
        $this->authorize('manageScoring', $tournament);

        try {
            $this->scoring->activateVersion($tournament, $rule);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Scoring rules version {$rule->version} is now active.");
    }
}
