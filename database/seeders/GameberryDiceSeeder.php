<?php

namespace Database\Seeders;

use App\Models\Dice;
use Illuminate\Database\Seeder;

class GameberryDiceSeeder extends Seeder
{
    public function run(): void
    {
        $rarities = ['common' => 50, 'rare' => 30, 'epic' => 15, 'legendary' => 5];
        $diceNames = [
            'Classic White', 'Midnight Black', 'Ruby Red', 'Emerald Green', 'Sapphire Blue',
            'Golden Crown', 'Silver Star', 'Bronze Age', 'Diamond Shine', 'Titan Fury',
            'Ludo King', 'Parchisi Master', 'Lucky Seven', 'Mystic Eye', 'Dragon Scale',
            'Phoenix Feather', 'Unicorn Horn', 'Mermaid Tear', 'Wizard Staff', 'Knight Shield',
            'Fire Dice', 'Ice Dice', 'Thunder Dice', 'Earth Dice', 'Wind Dice',
            'Sun Dice', 'Moon Dice', 'Star Dice', 'Galaxy Dice', 'Cosmic Dice',
        ];

        $count = 0;
        foreach ($rarities as $rarity => $percent) {
            $num = (int) (250 * $percent / 100);
            for ($i = 0; $i < $num; $i++) {
                $count++;
                $name = $diceNames[array_rand($diceNames)]." #{$count}";
                Dice::firstOrCreate(
                    ['slug' => 'dice-'.$count],
                    [
                        'name' => $name,
                        'description' => "Collectible {$rarity} dice {$count} - LudoStar 250+ collection, max 52 per type, Facebook exchange",
                        'rarity' => $rarity,
                        'is_lucky' => $count <= 52,
                        'is_collectible' => true,
                        'max_collection' => 52,
                        'image_url' => "/images/dices/dice-{$count}.png",
                        'is_active' => true,
                    ]
                );
            }
        }

        // Ensure 250
        for ($i = $count + 1; $i <= 250; $i++) {
            Dice::firstOrCreate(
                ['slug' => 'dice-'.$i],
                [
                    'name' => "Special Dice #{$i}",
                    'description' => 'Special collectible dice',
                    'rarity' => 'common',
                    'is_lucky' => false,
                    'is_collectible' => true,
                    'max_collection' => 52,
                    'image_url' => "/images/dices/dice-{$i}.png",
                    'is_active' => true,
                ]
            );
        }

        $this->command->info('Seeded 250+ dices with 52 max collection, lucky dice, Facebook exchange');
    }
}
