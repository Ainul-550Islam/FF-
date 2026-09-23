<?php

namespace App\Services\Gameberry;

use App\Models\Dice;
use App\Models\DiceExchange;
use App\Models\LuckyDice;
use App\Models\UserDice;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class DiceCollectionService
{
    const MAX_COLLECTION = 52; // Gameberry 52 max per user dice type? Actually total dice types 250+ but max 52 lucky dice storage

    const MAX_TOTAL_DICE_TYPES = 250;

    public function getUserCollection(int $userId): array
    {
        $userDices = UserDice::with('dice')->where('user_id', $userId)->get();
        $totalTypes = Dice::count();
        $ownedTypes = $userDices->count();
        $totalQuantity = $userDices->sum('quantity');

        return [
            'total_dice_types' => $totalTypes,
            'owned_types' => $ownedTypes,
            'completion_percent' => $totalTypes > 0 ? round(($ownedTypes / $totalTypes) * 100, 2) : 0,
            'total_quantity' => $totalQuantity,
            'max_collection_per_type' => self::MAX_COLLECTION,
            'dices' => $userDices->map(function ($ud) {
                return [
                    'id' => $ud->dice->id,
                    'name' => $ud->dice->name,
                    'rarity' => $ud->dice->rarity,
                    'quantity' => $ud->quantity,
                    'is_favorite' => $ud->is_favorite,
                    'is_equipped' => $ud->is_equipped,
                    'can_collect_more' => $ud->quantity < self::MAX_COLLECTION,
                    'image_url' => $ud->dice->image_url,
                ];
            }),
        ];
    }

    public function addDiceToUser(int $userId, int $diceId, int $quantity = 1): UserDice
    {
        return DB::transaction(function () use ($userId, $diceId, $quantity) {
            $dice = Dice::findOrFail($diceId);

            $userDice = UserDice::firstOrCreate(
                ['user_id' => $userId, 'dice_id' => $diceId],
                ['quantity' => 0, 'is_favorite' => false, 'is_equipped' => false]
            );

            if ($userDice->quantity + $quantity > self::MAX_COLLECTION) {
                throw new \Exception('Cannot exceed max collection of '.self::MAX_COLLECTION.' for this dice');
            }

            $userDice->quantity += $quantity;
            $userDice->save();

            return $userDice;
        });
    }

    public function exchangeDice(int $senderId, int $receiverId, int $diceId): DiceExchange
    {
        // Gameberry FAQ: dice exchange Facebook-only, need at least 2 to exchange
        $senderDice = UserDice::where('user_id', $senderId)->where('dice_id', $diceId)->first();
        if (! $senderDice || $senderDice->quantity < 2) {
            throw new \Exception('You need at least 2 of this dice to exchange');
        }

        if (! LuckyDice::canReceiveMore($receiverId)) {
            throw new \Exception('Receiver cannot receive more dice - max 52 reached');
        }

        return DiceExchange::create([
            'sender_id' => $senderId,
            'receiver_id' => $receiverId,
            'dice_id' => $diceId,
            'status' => 'pending',
            'is_facebook_only' => true,
        ]);
    }

    public function getAvailableDices(): Collection
    {
        return Dice::where('is_active', true)->orderBy('rarity')->orderBy('name')->get();
    }

    public function getRarityCounts(int $userId): array
    {
        return [
            'common' => UserDice::where('user_id', $userId)->whereHas('dice', fn ($q) => $q->where('rarity', 'common'))->count(),
            'rare' => UserDice::where('user_id', $userId)->whereHas('dice', fn ($q) => $q->where('rarity', 'rare'))->count(),
            'epic' => UserDice::where('user_id', $userId)->whereHas('dice', fn ($q) => $q->where('rarity', 'epic'))->count(),
            'legendary' => UserDice::where('user_id', $userId)->whereHas('dice', fn ($q) => $q->where('rarity', 'legendary'))->count(),
        ];
    }

    public function equipDice(int $userId, int $diceId): UserDice
    {
        return DB::transaction(function () use ($userId, $diceId) {
            // Unequip all
            UserDice::where('user_id', $userId)->update(['is_equipped' => false]);
            $userDice = UserDice::where('user_id', $userId)->where('dice_id', $diceId)->firstOrFail();
            $userDice->is_equipped = true;
            $userDice->save();

            return $userDice;
        });
    }

    public function toggleFavorite(int $userId, int $diceId): UserDice
    {
        $userDice = UserDice::where('user_id', $userId)->where('dice_id', $diceId)->firstOrFail();
        $userDice->is_favorite = ! $userDice->is_favorite;
        $userDice->save();

        return $userDice;
    }

    public function seedDefaultDices(): void
    {
        $rarities = ['common', 'rare', 'epic', 'legendary'];
        $diceNames = [
            'Classic White', 'Midnight Black', 'Ruby Red', 'Emerald Green', 'Sapphire Blue',
            'Golden Crown', 'Silver Star', 'Bronze Age', 'Diamond Shine', 'Titan Fury',
            'Ludo King', 'Parchisi Master', 'Lucky Seven', 'Mystic Eye', 'Dragon Scale',
            'Phoenix Feather', 'Unicorn Horn', 'Mermaid Tear', 'Wizard Staff', 'Knight Shield',
        ];

        for ($i = 1; $i <= 250; $i++) {
            $name = $diceNames[array_rand($diceNames)]." #{$i}";
            Dice::firstOrCreate(
                ['slug' => 'dice-'.$i],
                [
                    'name' => $name,
                    'description' => "Collectible dice {$i} - part of LudoStar 250+ collection",
                    'rarity' => $rarities[array_rand($rarities)],
                    'is_lucky' => $i <= 52,
                    'is_collectible' => true,
                    'max_collection' => self::MAX_COLLECTION,
                    'image_url' => "/images/dices/dice-{$i}.png",
                    'is_active' => true,
                ]
            );
        }
    }
}
