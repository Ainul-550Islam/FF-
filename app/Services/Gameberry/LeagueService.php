<?php

namespace App\Services\Gameberry;

use App\Models\League;
use App\Models\LeagueHistory;
use App\Models\TitanBadge;
use App\Models\UserLeague;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LeagueService
{
    const TOP_PERCENT_PROMOTION = 20; // Gameberry top 20% promoted

    const TOP_40_PROMOTION = 40; // Top 40 promotion text from FAQ

    const BOTTOM_PERCENT_DEMOTION = 40; // Bottom 40% demoted

    const MIN_GAMES_FOR_PROMOTION = 5;

    public function getUserLeague(int $userId): ?UserLeague
    {
        $season = $this->currentSeason();
        // Try with season column, fallback to season_start_at logic
        try {
            return UserLeague::with('league')->where('user_id', $userId)->where('season', $season)->first();
        } catch (\Exception $e) {
            return UserLeague::with('league')->where('user_id', $userId)->orderByDesc('created_at')->first();
        }
    }

    public function getLeaderboard(string $leagueSlug, ?int $season = null, int $limit = 100): Collection
    {
        $season = $season ?? $this->currentSeason();
        $league = League::where('slug', $leagueSlug)->firstOrFail();

        $query = UserLeague::with('user')
            ->where('league_id', $league->id)
            ->orderByDesc('trophies')
            ->limit($limit);

        // If season column exists, filter by it
        try {
            if (Schema::hasColumn('user_leagues', 'season')) {
                $query->where('season', $season);
            }
        } catch (\Exception $e) {
        }

        return $query->get();
    }

    public function addTrophies(int $userId, int $trophies, bool $isWin = true): UserLeague
    {
        return DB::transaction(function () use ($userId, $trophies, $isWin) {
            $userLeague = $this->getOrCreateUserLeague($userId);
            $userLeague->trophies = ($userLeague->trophies ?? 0) + $trophies;
            if ($isWin) {
                $userLeague->wins = ($userLeague->wins ?? 0) + 1;
            } else {
                $userLeague->losses = ($userLeague->losses ?? 0) + 1;
            }
            // Handle games_played - may be accessor or column
            try {
                $currentGames = $userLeague->getAttributes()['games_played'] ?? ($userLeague->wins + $userLeague->losses - 1);
                $userLeague->setAttribute('games_played', $currentGames + 1);
            } catch (\Exception $e) {
                $userLeague->games_played = ($userLeague->games_played ?? 0) + 1;
            }
            $userLeague->save();
            $userLeague->refresh();

            // Check if user is in top 20%
            $this->updateRankAndPromotionStatus($userLeague);

            return $userLeague;
        });
    }

    private function getOrCreateUserLeague(int $userId): UserLeague
    {
        $season = $this->currentSeason();
        $userLeague = null;

        try {
            $userLeague = UserLeague::where('user_id', $userId)->where('season', $season)->first();
        } catch (\Exception $e) {
            $userLeague = UserLeague::where('user_id', $userId)->orderByDesc('created_at')->first();
        }

        if (! $userLeague) {
            $bronze = League::where('slug', 'bronze')->first();
            if (! $bronze) {
                $bronze = League::first();
            }
            if (! $bronze) {
                // Create bronze if not exists
                $bronze = League::create([
                    'name' => 'Bronze',
                    'slug' => 'bronze',
                    'level' => 1,
                    'min_trophies' => 0,
                    'max_trophies' => 499,
                    'min_level_required' => 4,
                    'color' => '#cd7f32',
                ]);
            }

            $data = [
                'user_id' => $userId,
                'league_id' => $bronze->id,
                'trophies' => 0,
                'wins' => 0,
                'losses' => 0,
                'games_played' => 0,
            ];

            // Add season if column exists
            try {
                if (Schema::hasColumn('user_leagues', 'season')) {
                    $data['season'] = $season;
                }
                if (Schema::hasColumn('user_leagues', 'rank')) {
                    $data['rank'] = null;
                }
                if (Schema::hasColumn('user_leagues', 'rank_in_league')) {
                    $data['rank_in_league'] = null;
                }
            } catch (\Exception $e) {
            }

            $userLeague = UserLeague::create($data);
        }

        return $userLeague;
    }

    private function updateRankAndPromotionStatus(UserLeague $userLeague): void
    {
        $leagueId = $userLeague->league_id;
        $season = $userLeague->season ?? $this->currentSeason();

        $query = UserLeague::where('league_id', $leagueId);
        try {
            if (Schema::hasColumn('user_leagues', 'season')) {
                $query->where('season', $season);
            }
        } catch (\Exception $e) {
        }

        $totalPlayers = $query->count();
        $higherQuery = UserLeague::where('league_id', $leagueId)->where('trophies', '>', $userLeague->trophies);
        try {
            if (Schema::hasColumn('user_leagues', 'season')) {
                $higherQuery->where('season', $season);
            }
        } catch (\Exception $e) {
        }
        $higherTrophies = $higherQuery->count();

        $rank = $higherTrophies + 1;

        // Set both rank and rank_in_league for compatibility
        try {
            if (Schema::hasColumn('user_leagues', 'rank')) {
                $userLeague->rank = $rank;
            }
            if (Schema::hasColumn('user_leagues', 'rank_in_league')) {
                $userLeague->rank_in_league = $rank;
            }
        } catch (\Exception $e) {
            $userLeague->rank = $rank;
        }

        $userLeague->save();
    }

    public function processSeasonEnd(int $season): array
    {
        $results = ['promoted' => 0, 'demoted' => 0, 'stayed' => 0];
        $leagues = League::ordered()->get();

        foreach ($leagues as $league) {
            $query = UserLeague::where('league_id', $league->id)->orderByDesc('trophies');
            try {
                if (Schema::hasColumn('user_leagues', 'season')) {
                    $query->where('season', $season);
                }
            } catch (\Exception $e) {
            }
            $userLeagues = $query->get();
            $total = $userLeagues->count();
            if ($total === 0) {
                continue;
            }

            $top20Count = (int) ceil($total * 0.2);
            $bottom40Count = (int) ceil($total * 0.4);

            foreach ($userLeagues as $index => $ul) {
                $rank = $index + 1;
                $isTop20 = $rank <= $top20Count;
                $isBottom40 = $rank > ($total - $bottom40Count);

                $wasPromoted = false;
                $wasDemoted = false;
                $newLeagueId = $league->id;

                $nextLeague = $league->nextLeague ?? League::where('level', $league->level + 1)->first();
                $prevLeague = $league->previousLeague ?? League::where('level', $league->level - 1)->first();

                if ($isTop20 && $nextLeague) {
                    $newLeagueId = $nextLeague->id;
                    $wasPromoted = true;
                    $results['promoted']++;

                    if ($nextLeague->slug === 'titan' || $league->slug === 'titan') {
                        $this->awardTitanBadge($ul->user_id, $season, $rank);
                    }
                } elseif ($isBottom40 && $prevLeague) {
                    $newLeagueId = $prevLeague->id;
                    $wasDemoted = true;
                    $results['demoted']++;
                } else {
                    $results['stayed']++;
                }

                try {
                    if (Schema::hasTable('league_history')) {
                        LeagueHistory::create([
                            'user_id' => $ul->user_id,
                            'from_league_id' => $league->id,
                            'to_league_id' => $newLeagueId,
                            'type' => $wasPromoted ? 'promotion' : ($wasDemoted ? 'demotion' : 'season_reset'),
                            'trophies_at_time' => $ul->trophies,
                            'rank_at_time' => $rank,
                        ]);
                    }
                } catch (\Exception $e) {
                }

                if ($wasPromoted || $wasDemoted) {
                    try {
                        UserLeague::firstOrCreate(
                            ['user_id' => $ul->user_id, 'season' => $season + 1],
                            [
                                'league_id' => $newLeagueId,
                                'trophies' => 0,
                                'wins' => 0,
                                'losses' => 0,
                                'games_played' => 0,
                            ]
                        );
                    } catch (\Exception $e) {
                        // Fallback without season
                        UserLeague::firstOrCreate(
                            ['user_id' => $ul->user_id, 'league_id' => $newLeagueId],
                            [
                                'trophies' => 0,
                                'wins' => 0,
                                'losses' => 0,
                            ]
                        );
                    }
                }
            }
        }

        return $results;
    }

    private function awardTitanBadge(int $userId, int $season, int $rank): TitanBadge
    {
        $week = (int) date('W');
        $year = (int) date('Y');
        $titanLeague = League::where('slug', 'titan')->first();
        $leagueId = $titanLeague?->id ?? 6;

        return TitanBadge::create([
            'user_id' => $userId,
            'league_id' => $leagueId,
            'season' => $season,
            'week' => $week,
            'week_number' => $week,
            'year' => $year,
            'rank' => $rank,
            'rank_at_end' => $rank,
            'badge_type' => 'weekly_titan',
        ]);
    }

    public function currentSeason(): int
    {
        return (int) date('YW');
    }

    public function getLeagueProgression(): array
    {
        return League::ordered()->get()->map(function ($league) {
            return [
                'slug' => $league->slug,
                'name' => $league->name,
                'level' => $league->level,
                'min_trophies' => $league->min_trophies,
                'max_trophies' => $league->max_trophies,
                'color' => $league->color ?? $league->color_code ?? '#cd7f32',
                'next' => $league->nextLeague?->name ?? League::where('level', $league->level + 1)->first()?->name,
                'prev' => $league->previousLeague?->name ?? League::where('level', $league->level - 1)->first()?->name,
            ];
        })->toArray();
    }

    public function canAccessLeague(int $userLevel, string $leagueSlug): bool
    {
        $requirements = [
            'bronze' => 4,
            'silver' => 4,
            'gold' => 6,
            'platinum' => 8,
            'diamond' => 10,
            'titan' => 12,
        ];

        return $userLevel >= ($requirements[$leagueSlug] ?? 4);
    }
}
