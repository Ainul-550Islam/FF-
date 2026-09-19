<?php

namespace Database\Factories;

use App\Models\Dice;
use Illuminate\Database\Eloquent\Factories\Factory;

class DiceFactory extends Factory
{
    protected $model = Dice::class;

    public function definition(): array
    {
        $rarities = ['common', 'rare', 'epic', 'legendary'];
        $themes = ['classic', 'neon', 'gold', 'titan', 'diamond'];
        return [
            'name' => $this->faker->words(2, true) . ' Dice #' . $this->faker->unique()->numberBetween(1, 10000),
            'slug' => 'dice-' . $this->faker->unique()->numberBetween(1, 100000) . '-' . $this->faker->slug(1),
            'rarity' => $this->faker->randomElement($rarities),
            'theme' => $this->faker->randomElement($themes),
            'color' => $this->faker->hexColor(),
            'image_path' => 'dices/dice-' . $this->faker->numberBetween(1, 250) . '.png',
            'level_required' => $this->faker->numberBetween(1, 20),
            'gold_price_minor' => $this->faker->numberBetween(100, 100000),
            'gem_price' => $this->faker->numberBetween(0, 100),
            'is_lucky' => $this->faker->boolean(20),
            'is_collectible' => true,
            'is_tradable' => $this->faker->boolean(30),
            'max_collection' => 52,
            'metadata' => ['description' => $this->faker->sentence() . ' - LudoStar 250+ collection, max 52, Facebook exchange'],
        ];
    }

    public function lucky(): static
    {
        return $this->state(fn (array $attributes) => ['is_lucky' => true]);
    }

    public function rare(): static
    {
        return $this->state(fn (array $attributes) => ['rarity' => 'rare']);
    }

    public function legendary(): static
    {
        return $this->state(fn (array $attributes) => ['rarity' => 'legendary']);
    }
}
