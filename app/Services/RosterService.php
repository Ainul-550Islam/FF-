<?php

namespace App\Services;

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\Tournament;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Roster + competitive team integrity service.
 *
 * Owns all roster rules for Phase 03:
 *   - Free Fire UID normalization (consistent identity)
 *   - roster size enforcement against tournament.team_size
 *   - duplicate-player prevention (within a team)
 *   - cross-team player duplication prevention (within a tournament)
 *   - roster locking (editable only while registration is open)
 *   - member add / remove / team profile update
 *
 * Authorization ("is this user allowed?") lives in policies; this service
 * answers "is this roster change legal right now?" and performs it.
 */
class RosterService
{
    /**
     * Normalize a Free Fire UID for consistent identity comparison and
     * storage. UIDs are trimmed and uppercased.
     */
    public function normalizeUid(?string $uid): string
    {
        return strtoupper(trim((string) $uid));
    }

    /**
     * Whether the team's roster is currently locked.
     * Rosters are editable while the tournament is open for registration.
     * Once registration closes (or the tournament starts/ends), the roster
     * locks. Admins may override (handled explicitly at the controller).
     */
    public function isLocked(Team $team): bool
    {
        return $team->tournament->status !== Tournament::STATUS_OPEN;
    }

    /**
     * Throw unless the roster is editable.
     */
    public function assertEditable(Team $team): void
    {
        if ($this->isLocked($team)) {
            throw new DomainException('This team roster is locked because registration has closed.');
        }
    }

    /**
     * Maximum number of members (excluding the captain) for this team.
     */
    public function maxMembers(Team $team): int
    {
        return max(0, (int) $team->tournament->team_size - 1);
    }

    /**
     * Throw unless $count members can still be added to the team.
     */
    public function assertCanAddMembers(Team $team, int $count = 1, ?int $ignoreMemberId = null): void
    {
        $current = $team->members()
            ->when($ignoreMemberId, fn ($q) => $q->where('id', '!=', $ignoreMemberId))
            ->count();

        if ($current + $count > $this->maxMembers($team)) {
            throw new DomainException("This tournament allows a maximum of {$team->tournament->team_size} players per team (captain + members).");
        }
    }

    /**
     * Ensure the given UID is not already used by any captain or member of
     * another team in this tournament. Optionally ignores one team (both its
     * captain row and its members) and/or one specific member row.
     */
    public function assertUidAvailable(Tournament $tournament, string $uid, ?Team $ignoreTeam = null, ?int $ignoreMemberId = null): void
    {
        $uid = $this->normalizeUid($uid);

        $captainClash = Team::query()
            ->where('tournament_id', $tournament->id)
            ->whereIn('status', Team::COMPETING_STATUSES)
            ->whereRaw('UPPER(TRIM(game_uid)) = ?', [$uid])
            ->when($ignoreTeam, fn ($q) => $q->where('id', '!=', $ignoreTeam->id))
            ->exists();

        $memberClash = TeamMember::query()
            ->whereHas('team', fn ($q) => $q
                ->where('tournament_id', $tournament->id)
                ->whereIn('status', Team::COMPETING_STATUSES))
            ->whereRaw('UPPER(TRIM(game_uid)) = ?', [$uid])
            ->when($ignoreTeam, fn ($q) => $q->where('team_id', '!=', $ignoreTeam->id))
            ->when($ignoreMemberId, fn ($q) => $q->where('id', '!=', $ignoreMemberId))
            ->exists();

        if ($captainClash || $memberClash) {
            throw new DomainException("Player UID {$uid} is already registered to another team in this tournament.");
        }
    }

    /**
     * Validate and normalize a batch of roster rows (from the registration
     * form). Blank rows are skipped. Throws on invalid UID, within-team
     * duplicates, size overflow or cross-team clashes.
     *
     * @return array<int, array{player_name: string, game_uid: string}>
     */
    public function validateNewMembers(Tournament $tournament, Team $team, array $members): array
    {
        $normalized = [];
        $seen = [];

        foreach ($members as $member) {
            $name = trim((string) ($member['player_name'] ?? ''));
            if ($name === '') {
                continue; // blank rows are skipped (matches the register form)
            }

            $uid = $this->normalizeUid($member['game_uid'] ?? '');
            if ($uid === '' || ! preg_match('/^[A-Za-z0-9]{4,30}$/', $uid)) {
                throw new DomainException("Invalid Free Fire UID for member \"{$name}\" — use 4–30 letters or numbers.");
            }

            if ($uid === $this->normalizeUid($team->game_uid)) {
                throw new DomainException("Player UID {$uid} already belongs to the team captain.");
            }

            if (in_array($uid, $seen, true)) {
                throw new DomainException("Player UID {$uid} appears more than once in this team.");
            }

            $seen[] = $uid;
            $normalized[] = ['player_name' => $name, 'game_uid' => $uid];
        }

        if (count($normalized) > 0) {
            $this->assertCanAddMembers($team, count($normalized));
        }

        foreach ($seen as $uid) {
            $this->assertUidAvailable($tournament, $uid, $team);
        }

        return $normalized;
    }

    /**
     * Add a member to a team. $bypassLock is true only for admins.
     */
    public function addMember(Team $team, Tournament $tournament, array $data, bool $bypassLock = false): TeamMember
    {
        if (! $bypassLock) {
            $this->assertEditable($team);
        }

        $name = trim($data['player_name']);
        $uid = $this->normalizeUid($data['game_uid']);

        $member = null;

        DB::transaction(function () use ($team, $tournament, $name, $uid, &$member) {
            // Atomic size claim (SQLite-compatible): only succeeds while a
            // roster slot remains. This UPDATE takes the write lock, so the
            // reads and insert below cannot race with a concurrent add.
            $claimed = DB::table('teams')
                ->where('id', $team->id)
                ->whereRaw(
                    '(SELECT COUNT(*) FROM team_members WHERE team_id = teams.id) < (? - 1)',
                    [(int) $team->tournament->team_size]
                )
                ->update(['updated_at' => now()]);

            if ($claimed !== 1) {
                throw new DomainException("This tournament allows a maximum of {$team->tournament->team_size} players per team (captain + members).");
            }

            // Under the write lock, re-validate identity rules.
            if ($uid === $this->normalizeUid($team->game_uid)) {
                throw new DomainException("Player UID {$uid} already belongs to the team captain.");
            }

            if ($team->hasMemberWithUid($uid)) {
                throw new DomainException("Player UID {$uid} is already in this team.");
            }

            $this->assertUidAvailable($tournament, $uid, $team);

            $member = new TeamMember();
            $member->team_id = $team->id;
            $member->player_name = $name;
            $member->game_uid = $uid;
            $member->save();
        });

        return $member;
    }

    /**
     * Remove a member from a team.
     */
    public function removeMember(Team $team, TeamMember $member, bool $bypassLock = false): void
    {
        if (! $member->belongsToTeam($team)) {
            throw new DomainException('This member does not belong to this team.');
        }

        if (! $bypassLock) {
            $this->assertEditable($team);
        }

        $member->delete();
    }

    /**
     * Update team profile fields. Changing the captain UID re-runs the
     * availability checks.
     */
    public function updateProfile(Team $team, Tournament $tournament, array $data, bool $bypassLock = false): void
    {
        if (! $bypassLock) {
            $this->assertEditable($team);
        }

        $uid = $this->normalizeUid($data['game_uid']);

        if ($uid !== $this->normalizeUid($team->game_uid)) {
            if ($team->hasMemberWithUid($uid)) {
                throw new DomainException("Player UID {$uid} already belongs to a member of this team.");
            }

            $this->assertUidAvailable($tournament, $uid, $team);
        }

        $team->fill([
            'name' => trim($data['name']),
            'captain_name' => trim($data['captain_name']),
            'phone' => trim($data['phone']),
            'game_uid' => $uid,
        ])->save();
    }
}
