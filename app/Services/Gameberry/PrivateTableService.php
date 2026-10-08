<?php

namespace App\Services\Gameberry;

use App\Models\AutoModeLog;
use App\Models\Challenge;
use App\Models\PrivateTable;
use App\Models\PrivateTableParticipant;
use App\Models\UserOnlineStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PrivateTableService
{
    const MAX_PARTICIPANTS_CLASSIC = 4;

    const MAX_PARTICIPANTS_QUICK = 2;

    const CODE_LENGTH = 6;

    const EXPIRY_HOURS = 2;

    public function createTable(int $hostId, array $options = []): PrivateTable
    {
        // AUDIT FIX-16: service-level validation (defense in depth — the
        // controllers validate too, but the service must never trust its
        // callers with money-adjacent values).
        $gameMode = $options['game_mode'] ?? $options['mode'] ?? 'classic'; // classic, master, quick, team_up

        if (! in_array($gameMode, ['classic', 'master', 'quick', 'team_up'], true)) {
            throw new \InvalidArgumentException('Unknown game mode.');
        }

        $maxPlayers = $this->getMaxPlayersForMode($gameMode);
        $betAmount = (int) ($options['bet_amount'] ?? $options['bet_amount_minor'] ?? 100);

        if ($betAmount < 100 || $betAmount > 100000) {
            throw new \InvalidArgumentException('Bet amount must be between 100 and 100000 gold.');
        }

        $isTeamUp = (bool) ($options['is_team_up'] ?? ($gameMode === 'team_up'));
        $variation = $options['variation'] ?? $options['game_variation'] ?? $gameMode;

        // Normalize variation to classic/master/quick
        $gameVariation = in_array($variation, ['classic', 'master', 'quick', 'team_up']) ? $variation : 'classic';
        $mode = $isTeamUp ? 'team_up' : ($maxPlayers == 2 ? '1vs1' : '4_player');

        return DB::transaction(function () use ($hostId, $gameMode, $gameVariation, $mode, $betAmount, $maxPlayers, $isTeamUp, $options) {
            $code = $this->generateUniqueCode();
            $link = url('/private-tables/join/'.$code);

            $table = PrivateTable::create([
                'creator_id' => $hostId,
                'code' => $code,
                'link' => $link,
                'game_variation' => $gameVariation,
                'mode' => $mode,
                'bet_amount_minor' => $betAmount,
                'max_players' => $maxPlayers,
                'is_team_up' => $isTeamUp,
                'status' => 'waiting',
                'expires_at' => now()->addHours(self::EXPIRY_HOURS),
                'settings' => [
                    'game_mode' => $gameMode,
                    'is_private' => $options['is_private'] ?? true,
                    'allow_spectators' => $options['allow_spectators'] ?? true,
                    'bet_amount' => $betAmount,
                ],
            ]);

            // Add host as participant (the host stakes when the game starts,
            // not at creation — see leaveTable: only staked seats refund).
            PrivateTableParticipant::create([
                'private_table_id' => $table->id,
                'user_id' => $hostId,
                'role' => 'creator',
                'status' => 'joined',
                'is_ready' => false,
                'team' => $isTeamUp ? 'team_a' : null,
            ]);

            return $table;
        });
    }

    public function joinTable(int $userId, string $code): PrivateTableParticipant
    {
        // AUDIT FIX-16: the old flow checked capacity/expiry/duplicates on
        // UNLOCKED rows (TOCTOU overfill), then SWALLOWED gold-deduction
        // failures — a broke player still got a seat, and leaveTable later
        // refunded a bet that was never paid (gold duplication). Now: lock
        // the table row, re-check everything inside the transaction, and let
        // a failed stake roll the seat back.
        return DB::transaction(function () use ($userId, $code) {
            $table = PrivateTable::query()
                ->where('code', strtoupper($code))
                ->lockForUpdate()
                ->first();

            if (! $table || $table->status !== 'waiting') {
                throw new \Exception('Table not found or not available');
            }

            if ($table->isExpired()) {
                throw new \Exception('Table expired');
            }

            $existing = PrivateTableParticipant::query()
                ->where('private_table_id', $table->id)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $count = $table->participants()->lockForUpdate()->count();

            if ($count >= $table->max_players) {
                throw new \Exception('Table is full');
            }

            $participant = PrivateTableParticipant::create([
                'private_table_id' => $table->id,
                'user_id' => $userId,
                'role' => 'player',
                'status' => 'joined',
                'is_ready' => false,
                'team' => $table->is_team_up ? $this->assignTeam($table) : null,
            ]);

            // Stake the gold. placeBet re-checks affordability under the
            // wallet row lock; an \Exception here rolls the seat back, so a
            // seat NEVER exists without its stake.
            $stake = app(GoldEconomyService::class)->placeBet($userId, $table->bet_amount_minor, $table->code);

            $participant->metadata = array_merge($participant->metadata ?? [], [
                'staked_minor' => $table->bet_amount_minor,
                'stake_txn_id' => $stake->id,
            ]);
            $participant->save();

            return $participant;
        });
    }

    public function leaveTable(int $userId, string $code): void
    {
        // AUDIT FIX-16: the old code refunded the FULL bet to ANYONE who left
        // a waiting table — including the host (who never staked at creation)
        // and players whose stake had failed. Only the actually-staked amount
        // recorded on the seat is refunded, and the seat row is locked so two
        // concurrent leaves cannot double-refund.
        DB::transaction(function () use ($userId, $code) {
            $table = PrivateTable::query()
                ->where('code', strtoupper($code))
                ->lockForUpdate()
                ->firstOrFail();

            $participant = PrivateTableParticipant::query()
                ->where('private_table_id', $table->id)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->firstOrFail();

            $staked = (int) ($participant->metadata['staked_minor'] ?? 0);

            if ($table->status === 'waiting' && $staked > 0) {
                app(GoldEconomyService::class)->refundBet($userId, $staked, $table->code, 'left table');
            }

            $participant->delete();

            if ($table->participants()->count() === 0) {
                $table->status = 'expired';
                $table->save();
            }
        });
    }

    public function setReady(int $userId, string $code, bool $ready = true): PrivateTableParticipant
    {
        $table = PrivateTable::where('code', strtoupper($code))->firstOrFail();
        $participant = PrivateTableParticipant::where('private_table_id', $table->id)->where('user_id', $userId)->firstOrFail();
        $participant->is_ready = $ready;
        $participant->status = $ready ? 'ready' : 'joined';
        $participant->save();

        return $participant;
    }

    public function startGame(int $hostId, string $code): PrivateTable
    {
        $table = PrivateTable::where('code', strtoupper($code))
            ->where(function ($q) use ($hostId) {
                $q->where('creator_id', $hostId);
            })->firstOrFail();

        if ($table->participants()->count() < 2) {
            throw new \Exception('Need at least 2 players to start');
        }

        $table->status = 'playing';
        $table->started_at = now();
        $table->save();

        return $table;
    }

    public function setAutoMode(int $userId, string $code, bool $autoOn, string $reason = 'disconnect'): void
    {
        $table = PrivateTable::where('code', strtoupper($code))->firstOrFail();
        $participant = PrivateTableParticipant::where('private_table_id', $table->id)->where('user_id', $userId)->firstOrFail();
        $participant->is_in_auto_mode = $autoOn;
        $participant->status = $autoOn ? 'auto_mode' : $participant->status;
        if ($autoOn) {
            $participant->auto_mode_on_at = now();
            $participant->auto_mode_off_at = null;
        } else {
            $participant->auto_mode_off_at = now();
        }
        $participant->save();

        // Log auto mode - create if table exists
        try {
            if (Schema::hasTable('auto_mode_logs')) {
                AutoModeLog::create([
                    'user_id' => $userId,
                    'private_table_id' => $table->id,
                    'reason' => $reason,
                    'is_auto_on' => $autoOn,
                    'auto_on_at' => $autoOn ? now() : null,
                    'auto_off_at' => ! $autoOn ? now() : null,
                ]);
            }
        } catch (\Exception $e) {
            // Ignore if table missing
        }

        // Also update user_online_status if exists
        try {
            if (Schema::hasTable('user_online_statuses')) {
                $status = UserOnlineStatus::firstOrCreate(['user_id' => $userId]);
                $status->is_in_auto_mode = $autoOn;
                $status->save();
            }
        } catch (\Exception $e) {
        }
    }

    public function challengeFriend(int $challengerId, int $challengedId, string $type = 'private_table', int $betAmount = 100): Challenge
    {
        // Check if challenged is online and not hiding status
        try {
            if (Schema::hasTable('user_online_statuses')) {
                $status = UserOnlineStatus::where('user_id', $challengedId)->first();
                if ($status && $status->hide_online_status) {
                    // For test, still allow but log
                }
            }
        } catch (\Exception $e) {
        }

        return Challenge::create([
            'challenger_id' => $challengerId,
            'challenged_id' => $challengedId,
            'type' => $type,
            'status' => 'pending',
            'bet_amount_minor' => $betAmount,
            'expires_at' => now()->addMinutes(5),
        ]);
    }

    public function getTableByCode(string $code): ?PrivateTable
    {
        return PrivateTable::with(['creator', 'participants.user'])->where('code', strtoupper($code))->first();
    }

    public function getTableByLink(string $link): ?PrivateTable
    {
        // Link contains code
        $parts = explode('/', $link);
        $code = end($parts);

        return $this->getTableByCode($code);
    }

    private function generateUniqueCode(): string
    {
        do {
            $code = strtoupper(Str::random(self::CODE_LENGTH));
        } while (PrivateTable::where('code', $code)->exists());

        return $code;
    }

    private function getMaxPlayersForMode(string $mode): int
    {
        return match ($mode) {
            'quick' => 2,
            'classic' => 4,
            'master' => 4,
            'team_up' => 4,
            '1vs1' => 2,
            '4_player' => 4,
            default => 4,
        };
    }

    private function assignTeam(PrivateTable $table): string
    {
        $teamACount = $table->participants()->where('team', 'team_a')->count();
        $teamBCount = $table->participants()->where('team', 'team_b')->count();

        return $teamACount <= $teamBCount ? 'team_a' : 'team_b';
    }

    public function cleanupExpired(): int
    {
        return PrivateTable::where('expires_at', '<', now())->where('status', 'waiting')->update(['status' => 'expired']);
    }
}
