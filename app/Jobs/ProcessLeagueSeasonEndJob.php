<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\Gameberry\LeagueService;
use Illuminate\Support\Facades\Log;

class ProcessLeagueSeasonEndJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $season;

    public function __construct(int $season)
    {
        $this->season = $season;
    }

    public function handle(LeagueService $leagueService): void
    {
        Log::info("Processing Gameberry league season end for season {$this->season} - Top 20% promotion Top 40 demotion Titan badges");

        try {
            $results = $leagueService->processSeasonEnd($this->season);

            Log::info("Season {$this->season} processed", $results);
        } catch (\Exception $e) {
            Log::error("Failed to process season {$this->season}: ".$e->getMessage());
            throw $e;
        }
    }
}
