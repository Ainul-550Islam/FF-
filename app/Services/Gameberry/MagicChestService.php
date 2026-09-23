<?php

namespace App\Services\Gameberry;

use App\Models\Dice;
use App\Models\MagicChest;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class MagicChestService
{
    const CHEST_TYPES = [
        'bronze' => ['gold_min' => 50, 'gold_max' => 200, 'gem_min' => 0, 'gem_max' => 2],
        'silver' => ['gold_min' => 200, 'gold_max' => 500, 'gem_min' => 1, 'gem_max' => 5],
        'gold' => ['gold_min' => 500, 'gold_max' => 1500, 'gem_min' => 3, 'gem_max' => 10],
        'magic' => ['gold_min' => 1000, 'gold_max' => 5000, 'gem_min' => 5, 'gem_max' => 20],
    ];

    const COOLDOWN_HOURS = 4;

    public function canGetChest(int $userId): bool
    {
        $lastChest = MagicChest::where('user_id', $userId)->orderByDesc('created_at')->first();
        if (! $lastChest) {
            return true;
        }

        return $lastChest->created_at->diffInHours(now()) >= self::COOLDOWN_HOURS;
    }

    public function getNextChestTime(int $userId): ?Carbon
    {
        $lastChest = MagicChest::where('user_id', $userId)->orderByDesc('created_at')->first();
        if (! $lastChest) {
            return null;
        }
        $next = $lastChest->created_at->addHours(self::COOLDOWN_HOURS);

        return $next->isFuture() ? $next : null;
    }

    public function createChest(int $userId, string $type = 'bronze'): MagicChest
    {
        if (! isset(self::CHEST_TYPES[$type])) {
            throw new \Exception('Invalid chest type');
        }

        if (! $this->canGetChest($userId) && $type !== 'reward') {
            throw new \Exception('Chest cooldown active');
        }

        $config = self::CHEST_TYPES[$type];
        $goldReward = rand($config['gold_min'], $config['gold_max']);
        $gemReward = rand($config['gem_min'], $config['gem_max']);

        // Chance for dice reward
        $diceRewards = [];
        if (rand(1, 100) <= 30) { // 30% chance for dice
            $randomDice = Dice::inRandomOrder()->first();
            if ($randomDice) {
                $diceRewards[] = $randomDice->id;
            }
        }

        return MagicChest::create([
            'user_id' => $userId,
            'type' => $type,
            'status' => 'available',
            'gold_reward' => $goldReward,
            'gem_reward' => $gemReward,
            'dice_rewards' => $diceRewards,
            'available_at' => now(),
            'expires_at' => now()->addDays(1),
        ]);
    }

    public function openChest(int $userId, int $chestId): array
    {
        return DB::transaction(function () use ($userId, $chestId) {
            $chest = MagicChest::where('id', $chestId)->where('user_id', $userId)->firstOrFail();

            if (! $chest->isAvailable()) {
                throw new \Exception('Chest not available');
            }

            $rewards = $chest->open();

            // Give rewards
            if ($rewards['gold'] > 0) {
                app(GoldEconomyService::class)->getOrCreateWallet($userId)->addGold($rewards['gold'], 'magic_chest', 'magic_chest', (string) $chest->id, "Magic chest {$chest->type} gold");
            }
            if ($rewards['gems'] > 0) {
                app(GemEconomyService::class)->getOrCreateWallet($userId)->addGems($rewards['gems'], 'magic_chest', 'magic_chest', (string) $chest->id, "Magic chest {$chest->type} gems");
            }
            foreach ($rewards['dices'] as $diceId) {
                app(DiceCollectionService::class)->addDiceToUser($userId, $diceId, 1);
            }

            return $rewards;
        });
    }

    public function getUserChests(int $userId): Collection
    {
        return MagicChest::where('user_id', $userId)->orderByDesc('created_at')->get();
    }

    public function getAvailableChests(int $userId): Collection
    {
        return MagicChest::where('user_id', $userId)->available()->get();
    }

    public function rewardForWin(int $userId, string $gameMode): ?MagicChest
    {
        // Chance to get chest after win - higher for harder modes
        $chances = [
            'quick' => 10,
            'classic' => 20,
            'master' => 30,
        ];

        $chance = $chances[$gameMode] ?? 15;
        if (rand(1, 100) <= $chance) {
            $types = ['bronze', 'silver', 'gold'];
            $weights = [60, 30, 10];
            $rand = rand(1, 100);
            $selected = 'bronze';
            $current = 0;
            foreach ($types as $i => $type) {
                $current += $weights[$i];
                if ($rand <= $current) {
                    $selected = $type;
                    break;
                }
            }

            return $this->createChest($userId, $selected);
        }

        return null;
    }
}
