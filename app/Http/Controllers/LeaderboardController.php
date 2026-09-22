<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Services\ScoringService;
use App\Support\Seo;

class LeaderboardController extends Controller
{
    public function __construct(
        protected ScoringService $scoring,
    ) {}

    public function show(Tournament $tournament)
    {
        // Standings are computed exclusively by the scoring engine so the
        // leaderboard, match results and any future standings API all agree.
        $leaderboard = $this->scoring->standings($tournament);

        $tieBreakers = $this->scoring->currentRuleSet($tournament)->tieBreakers();

        app(Seo::class)
            ->title('Leaderboard — '.$tournament->name.' — '.(string) config('app.name', 'FF Arena'))
            ->description('Live standings for '.$tournament->name.' — ranks, kills, placement points and totals.')
            ->canonical(route('leaderboard.show', $tournament))
            ->indexable();

        return view('leaderboard.show', compact('tournament', 'leaderboard', 'tieBreakers'));
    }
}
