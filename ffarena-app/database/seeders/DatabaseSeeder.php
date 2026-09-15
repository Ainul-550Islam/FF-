<?php

namespace Database\Seeders;

use App\Models\GameMatch;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\ScoringService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Admin — role set explicitly (not mass-assignable)
        $admin = new User();
        $admin->name = 'FF Arena Admin';
        $admin->username = 'admin';
        $admin->email = 'admin@ffarena.test';
        $admin->password = Hash::make('password');
        $admin->role = 'admin';
        $admin->save();

        // Organizer
        $organizer = new User();
        $organizer->name = 'Rafsan Esports';
        $organizer->username = 'rafsan';
        $organizer->email = 'organizer@ffarena.test';
        $organizer->password = Hash::make('password');
        $organizer->role = 'organizer';
        $organizer->phone = '01700000000';
        $organizer->save();

        // Demo tournament (open for registration)
        $tournament = new Tournament();
        $tournament->organizer_id = $organizer->id;
        $tournament->name = 'Squad Showdown 32 Teams';
        $tournament->slug = 'squad-showdown-32-teams';
        $tournament->game_mode = 'squad';
        $tournament->map = 'Bermuda';
        $tournament->entry_fee = 100;
        $tournament->prize_pool = 5000;
        $tournament->team_slots = 8;
        $tournament->team_size = 4;
        $tournament->rules = "1. No hacks/cheats — instant ban.\n2. Screenshot of result is mandatory.\n3. Room entry within 5 minutes of schedule.";
        $tournament->starts_at = now()->addDays(2);
        $tournament->check_in_starts_at = now()->addDays(2)->subHours(3);
        $tournament->check_in_ends_at = now()->addDays(2)->subHour();
        $tournament->format = 'single_elim';
        $tournament->status = 'open';
        $tournament->save();

        $teamNames = ['BD Titans', 'Dhaka Wolves', 'RapidFire', 'Night Owls', 'Sylhet Strikers', 'Cox Cobras', 'Rajshahi Reapers', 'Chittagong Kings'];
        $seededTeams = [];

        foreach ($teamNames as $i => $name) {
            $team = new Team();
            $team->tournament_id = $tournament->id;
            $team->captain_id = null;
            $team->name = $name;
            $team->captain_name = 'Captain ' . ($i + 1);
            $team->phone = '017' . str_pad((string) (10000000 + $i), 8, '0', STR_PAD_LEFT);
            $team->game_uid = 'UID' . (900000000 + $i);
            $team->status = 'confirmed';
            $team->seed = $i + 1;
            $team->save();
            $seededTeams[] = $team;
        }

        // Second tournament (finished) with a bracket + results
        $done = new Tournament();
        $done->organizer_id = $organizer->id;
        $done->name = 'Duo Battle Royale';
        $done->slug = 'duo-battle-royale-' . rand(1000, 9999);
        $done->game_mode = 'duo';
        $done->map = 'Purgatory';
        $done->entry_fee = 50;
        $done->prize_pool = 2000;
        $done->team_slots = 8;
        $done->team_size = 2;
        $done->rules = 'Standard duo rules.';
        $done->starts_at = now()->subDays(1);
        $done->format = 'single_elim';
        $done->status = 'finished';
        $done->save();

        $dTeams = [];
        for ($i = 1; $i <= 8; $i++) {
            $t = new Team();
            $t->tournament_id = $done->id;
            $t->captain_id = null;
            $t->name = 'Duo Team ' . $i;
            $t->captain_name = 'Cap ' . $i;
            $t->phone = '017' . str_pad((string) (20000000 + $i), 8, '0', STR_PAD_LEFT);
            $t->game_uid = 'UID' . (800000000 + $i);
            $t->status = 'confirmed';
            $t->seed = $i;
            $t->save();
            $dTeams[] = $t;
        }

        $this->makeMatch($done, 1, 1, $dTeams[0], $dTeams[1], $dTeams[0]);
        $this->makeMatch($done, 1, 2, $dTeams[2], $dTeams[3], $dTeams[2]);
        $this->makeMatch($done, 1, 3, $dTeams[4], $dTeams[5], $dTeams[5]);
        $this->makeMatch($done, 1, 4, $dTeams[6], $dTeams[7], $dTeams[6]);

        $this->makeMatch($done, 2, 1, $dTeams[0], $dTeams[2], $dTeams[0]);
        $this->makeMatch($done, 2, 2, $dTeams[5], $dTeams[6], $dTeams[5]);

        $this->makeMatch($done, 3, 1, $dTeams[0], $dTeams[5], $dTeams[0]);

        // Phase 06 — establish a default scoring rule set for each demo
        // tournament (lazy creation in ScoringService also covers any
        // tournament without rules).
        app(ScoringService::class)->currentRuleSet($tournament);
        app(ScoringService::class)->currentRuleSet($done);
    }

    private function makeMatch(Tournament $tournament, int $round, int $matchNo, Team $t1, Team $t2, Team $winner): void
    {
        $match = new GameMatch();
        $match->tournament_id = $tournament->id;
        $match->round = $round;
        $match->match_no = $matchNo;
        $match->team1_id = $t1->id;
        $match->team2_id = $t2->id;
        $match->winner_team_id = $winner->id;
        $match->status = 'completed';
        $match->save();
    }
}
