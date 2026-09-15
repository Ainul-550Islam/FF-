<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\Team;
use App\Models\Tournament;

/**
 * Tournament bracket engine (Phase 05).
 *
 * Generates deterministic, idempotent brackets and advances winners/losers
 * through an explicit dependency graph (next_match_id / next_slot /
 * loser_next_match_id / loser_slot) rather than fragile arithmetic
 * (`ceil(match_no / 2)`), so the structure is auditable and extensible.
 *
 * Implemented formats:
 *   - Single elimination — any field of 2..N teams, using a power-of-two
 *     bracket with byes for non-power-of-two field sizes.
 *   - Double elimination — winners + losers brackets + a single grand final;
 *     requires a power-of-two field (4, 8, 16, 32) so every losers-bracket
 *     round is well formed.
 *
 * Seeding policy (deterministic, no RNG): teams are ranked by `seed` then
 * `id`, and `seed` is normalised to the team's rank (1..n) so seeding is
 * reproducible. Round one pairs ranks sequentially (1v2, 3v4, …) and any
 * remaining teams receive byes at the bottom of the draw — a bye match is
 * auto-completed and its team advances. This keeps every round-1 match
 * non-empty for any field size.
 */
class BracketService
{
    /**
     * Generate (or regenerate) the bracket for a tournament.
     *
     * Idempotent: any previously generated matches are removed first, so
     * calling this twice never duplicates matches. Returns the number of
     * matches created, or 0 when the field cannot form a bracket.
     */
    public function generate(Tournament $tournament): int
    {
        GameMatch::where('tournament_id', $tournament->id)->delete();

        if ($tournament->isDoubleElim()) {
            return $this->generateDoubleElim($tournament);
        }

        return $this->generateSingleElim($tournament);
    }

    /**
     * Advance a completed (or bye) match: move the winner into its winner
     * destination and — for double elimination — the loser into its loser
     * destination.
     *
     * Filling is idempotent: a slot that is already occupied is never
     * overwritten unless $staleWinnerId matches it (privileged result
     * correction).
     */
    public function advance(GameMatch $match, ?int $staleWinnerId = null): void
    {
        if ($match->winner_team_id === null) {
            return;
        }

        $winnerId = $match->winner_team_id;
        $loserId = $match->loserTeamId();

        if ($match->next_match_id !== null) {
            $next = GameMatch::find($match->next_match_id);
            if ($next !== null) {
                $current = $next->teamIdInSlot($match->next_slot);
                if ($current === null || ($staleWinnerId !== null && $current === $staleWinnerId)) {
                    $next->setTeamSlot($match->next_slot, $winnerId);
                    $this->markReadyIfComplete($next);
                    $next->save();
                }
            }
        }

        if ($match->loser_next_match_id !== null && $loserId !== null) {
            $ln = GameMatch::find($match->loser_next_match_id);
            if ($ln !== null) {
                $staleLoser = $staleWinnerId !== null ? $this->opponentOf($staleWinnerId, $match) : null;
                $current = $ln->teamIdInSlot($match->loser_slot);
                if ($current === null || ($staleLoser !== null && $current === $staleLoser)) {
                    $ln->setTeamSlot($match->loser_slot, $loserId);
                    $this->markReadyIfComplete($ln);
                    $ln->save();
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Single elimination
    // ------------------------------------------------------------------

    protected function generateSingleElim(Tournament $tournament): int
    {
        $teams = $this->rankedTeams($tournament);
        $n = $teams->count();

        if ($n < 2) {
            return 0;
        }

        $B = $this->nextPowerOfTwo($n);
        $R = (int) log($B, 2);
        $teamByRank = $teams->values()->all();

        $matches = [];

        // Create every round (pending placeholders), rounds 1..R.
        for ($r = 1; $r <= $R; $r++) {
            $count = intdiv($B, 2 ** $r);
            for ($m = 1; $m <= $count; $m++) {
                $matches[$r][$m] = $this->createMatch($tournament, GameMatch::BRACKET_WINNERS, $r, $m);
            }
        }

        // Explicit winner links: round r match m → round r+1 match ceil(m/2).
        for ($r = 1; $r < $R; $r++) {
            $count = intdiv($B, 2 ** $r);
            for ($m = 1; $m <= $count; $m++) {
                $src = $matches[$r][$m];
                $src->next_match_id = $matches[$r + 1][(int) ceil($m / 2)]->id;
                $src->next_slot = ($m % 2 === 1) ? 1 : 2;
                $src->save();
            }
        }

        // Fill round 1: sequential pairs (1v2, 3v4, …) then byes at the
        // bottom of the draw. Every round-1 match ends up with ≥ 1 team.
        $roundOneMatches = intdiv($B, 2);
        $full = $n - $roundOneMatches; // matches that get two teams (≥ 1)

        for ($i = 0; $i < $full; $i++) {
            $match = $matches[1][$i + 1];
            $match->team1_id = $teamByRank[2 * $i]->id;
            $match->team2_id = $teamByRank[2 * $i + 1]->id;
            $match->status = GameMatch::STATUS_READY;
            $match->save();
        }

        for ($j = 0; $j < $n - 2 * $full; $j++) {
            $match = $matches[1][$full + $j + 1];
            $team = $teamByRank[2 * $full + $j];
            $match->team1_id = $team->id;
            $match->winner_team_id = $team->id;
            $match->status = GameMatch::STATUS_BYE;
            $match->save();
            $this->advance($match);
        }

        $tournament->bracket_size = $B;
        $tournament->save();

        return GameMatch::where('tournament_id', $tournament->id)->count();
    }

    // ------------------------------------------------------------------
    // Double elimination
    // ------------------------------------------------------------------

    protected function generateDoubleElim(Tournament $tournament): int
    {
        $teams = $this->rankedTeams($tournament);
        $n = $teams->count();

        // Power-of-two only: byes would produce ill-formed losers rounds.
        if ($n < 4 || ($n & ($n - 1)) !== 0) {
            return 0;
        }

        $R = (int) log($n, 2);
        $teamByRank = $teams->values()->all();

        $wb = [];
        $lb = [];

        // Winners bracket: rounds 1..R.
        for ($r = 1; $r <= $R; $r++) {
            $count = intdiv($n, 2 ** $r);
            for ($m = 1; $m <= $count; $m++) {
                $wb[$r][$m] = $this->createMatch($tournament, GameMatch::BRACKET_WINNERS, $r, $m);
            }
        }

        // Winners-bracket winner links.
        for ($r = 1; $r < $R; $r++) {
            $count = intdiv($n, 2 ** $r);
            for ($m = 1; $m <= $count; $m++) {
                $src = $wb[$r][$m];
                $src->next_match_id = $wb[$r + 1][(int) ceil($m / 2)]->id;
                $src->next_slot = ($m % 2 === 1) ? 1 : 2;
                $src->save();
            }
        }

        // Losers bracket: 2R-2 rounds.
        $lbRounds = 2 * $R - 2;
        for ($r = 1; $r <= $lbRounds; $r++) {
            $count = intdiv($n, 2 ** ((int) ceil($r / 2) + 1));
            for ($m = 1; $m <= $count; $m++) {
                $lb[$r][$m] = $this->createMatch($tournament, GameMatch::BRACKET_LOSERS, $r, $m);
            }
        }

        // Winners-bracket round-1 losers drop into losers-bracket round 1.
        for ($m = 1; $m <= intdiv($n, 2); $m++) {
            $src = $wb[1][$m];
            $src->loser_next_match_id = $lb[1][(int) ceil($m / 2)]->id;
            $src->loser_slot = ($m % 2 === 1) ? 1 : 2;
            $src->save();
        }

        // Link the rest of the losers bracket.
        for ($r = 1; $r <= $lbRounds; $r++) {
            $count = intdiv($n, 2 ** ((int) ceil($r / 2) + 1));

            if ($r % 2 === 1) {
                // Odd LB round: winners of the previous LB round pair up.
                if ($r > 1) {
                    for ($m = 1; $m <= $count; $m++) {
                        $a = $lb[$r - 1][2 * $m - 1];
                        $b = $lb[$r - 1][2 * $m];
                        $a->next_match_id = $lb[$r][$m]->id;
                        $a->next_slot = 1;
                        $a->save();
                        $b->next_match_id = $lb[$r][$m]->id;
                        $b->next_slot = 2;
                        $b->save();
                    }
                }
            } else {
                // Even LB round: previous LB round winner (slot 1) plus the
                // corresponding winners-bracket loser (slot 2).
                $wbRound = intdiv($r, 2) + 1;
                for ($m = 1; $m <= $count; $m++) {
                    $prev = $lb[$r - 1][$m];
                    $prev->next_match_id = $lb[$r][$m]->id;
                    $prev->next_slot = 1;
                    $prev->save();

                    $wbSrc = $wb[$wbRound][$m];
                    $wbSrc->loser_next_match_id = $lb[$r][$m]->id;
                    $wbSrc->loser_slot = 2;
                    $wbSrc->save();
                }
            }
        }

        // Grand final: winners-bracket final winner vs losers-bracket final
        // winner. (Single final — a "modified" double elimination.)
        $gf = $this->createMatch($tournament, GameMatch::BRACKET_GRAND_FINAL, $R + 1, 1);
        $wbFinal = $wb[$R][1];
        $wbFinal->next_match_id = $gf->id;
        $wbFinal->next_slot = 1;
        $wbFinal->save();
        $lbFinal = $lb[$lbRounds][1];
        $lbFinal->next_match_id = $gf->id;
        $lbFinal->next_slot = 2;
        $lbFinal->save();

        // Fill winners-bracket round 1 (power of two → no byes).
        for ($i = 0; $i < $n; $i += 2) {
            $match = $wb[1][intdiv($i, 2) + 1];
            $match->team1_id = $teamByRank[$i]->id;
            $match->team2_id = $teamByRank[$i + 1]->id;
            $match->status = GameMatch::STATUS_READY;
            $match->save();
        }

        $tournament->bracket_size = $n;
        $tournament->save();

        return GameMatch::where('tournament_id', $tournament->id)->count();
    }

    // ------------------------------------------------------------------
    // Seeding + helpers
    // ------------------------------------------------------------------

    /**
     * Eligible teams in deterministic seed order. Each team's `seed` is
     * (re)assigned to its rank (1..n) so the bracket is reproducible and
     * auditable. Ordering: seed ascending, then id ascending.
     */
    protected function rankedTeams(Tournament $tournament)
    {
        $teams = $tournament->bracketEligibleTeams()
            ->orderBy('seed')
            ->orderBy('id')
            ->get();

        $rank = 0;
        foreach ($teams as $team) {
            $rank++;
            if ((int) $team->seed !== $rank) {
                $team->seed = $rank;
                $team->save();
            }
        }

        return $teams;
    }

    protected function nextPowerOfTwo(int $n): int
    {
        $p = 1;
        while ($p < $n) {
            $p <<= 1;
        }

        return $p;
    }

    protected function createMatch(Tournament $tournament, string $bracket, int $round, int $matchNo): GameMatch
    {
        $match = new GameMatch();
        $match->tournament_id = $tournament->id;
        $match->bracket = $bracket;
        $match->round = $round;
        $match->match_no = $matchNo;
        $match->status = GameMatch::STATUS_PENDING;
        $match->save();

        return $match;
    }

    /**
     * The id of the team playing against $teamId in this match, if any.
     */
    protected function opponentOf(int $teamId, GameMatch $match): ?int
    {
        if ($match->team1_id === $teamId) {
            return $match->team2_id;
        }
        if ($match->team2_id === $teamId) {
            return $match->team1_id;
        }

        return null;
    }

    /**
     * Promote a placeholder match to READY once both slots are filled.
     */
    protected function markReadyIfComplete(GameMatch $match): void
    {
        if ($match->hasBothTeams() && $match->status === GameMatch::STATUS_PENDING) {
            $match->status = GameMatch::STATUS_READY;
        }
    }
}
