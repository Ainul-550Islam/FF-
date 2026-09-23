<?php

namespace App\Console\Commands;

use App\Models\Challenge;
use App\Models\ChatMessage;
use App\Models\DiceExchange;
use App\Services\Gameberry\PrivateTableService;
use Illuminate\Console\Command;

class GameberryCleanupExpiredTablesCommand extends Command
{
    protected $signature = 'gameberry:cleanup-tables';

    protected $description = 'Cleanup expired private tables - Code/Link sharing expiry 2 hours';

    public function handle(PrivateTableService $tableService): int
    {
        $this->info('Cleaning up expired private tables...');

        $expiredCount = $tableService->cleanupExpired();
        $this->info("Marked {$expiredCount} tables as expired");

        // Also cleanup old chat messages for expired tables older than 7 days
        $oldMessages = ChatMessage::whereHas('privateTable', function ($q) {
            $q->where('status', 'expired')->where('expires_at', '<', now()->subDays(7));
        })->delete();

        $this->info("Deleted {$oldMessages} old chat messages for expired tables");

        // Cleanup old challenges
        $oldChallenges = Challenge::where('expires_at', '<', now()->subDay())->where('status', 'pending')->update(['status' => 'expired']);
        $this->info("Expired {$oldChallenges} old challenges");

        // Cleanup old dice exchanges
        $oldExchanges = DiceExchange::where('expires_at', '<', now()->subDays(7))->where('status', 'pending')->update(['status' => 'expired']);
        $this->info("Expired {$oldExchanges} old dice exchanges");

        return self::SUCCESS;
    }
}
