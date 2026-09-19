<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Gameberry\LeagueService;

class GameberrySeasonEndCommand extends Command
{
    protected $signature = 'gameberry:season-end {season? : Season identifier YW format}';
    protected $description = 'Process Gameberry league season end - Top 20% promotion Top 40 demotion Titan badges';

    public function handle(LeagueService $leagueService): int
    {
        $season = $this->argument('season') ? (int) $this->argument('season') : $leagueService->currentSeason() - 1;

        $this->info("Processing season end for season {$season} - Top 20% promotion, Bottom 40% demotion, Titan badges");

        try {
            $results = $leagueService->processSeasonEnd($season);

            $this->info("Season {$season} processed:");
            $this->info("  Promoted: {$results['promoted']} players (Top 20%)");
            $this->info("  Demoted: {$results['demoted']} players (Bottom 40%)");
            $this->info("  Stayed: {$results['stayed']} players");
            $this->info("  Titan badges awarded for Titan league");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to process season end: ".$e->getMessage());
            return self::FAILURE;
        }
    }
}
