<?php

namespace Database\Factories;

use App\Models\League;
use Illuminate\Database\Eloquent\Factories\Factory;

class LeagueFactory extends Factory
{
    protected $model = League::class;

    public function definition(): array
    {
        $leagues = [
            ['name' => 'Bronze', 'slug' => 'bronze', 'level' => 1, 'color' => '#CD7F32'],
            ['name' => 'Silver', 'slug' => 'silver', 'level' => 2, 'color' => '#C0C0C0'],
            ['name' => 'Gold', 'slug' => 'gold', 'level' => 3, 'color' => '#FFD700'],
            ['name' => 'Platinum', 'slug' => 'platinum', 'level' => 4, 'color' => '#E5E4E2'],
            ['name' => 'Diamond', 'slug' => 'diamond', 'level' => 5, 'color' => '#B9F2FF'],
            ['name' => 'Titan', 'slug' => 'titan', 'level' => 6, 'color' => '#FF4500'],
        ];

        $league = $this->faker->randomElement($leagues);

        return [
            'name' => $league['name'],
            'slug' => $league['slug'] . '-' . $this->faker->unique()->numberBetween(1, 10000),
            'level' => $league['level'],
            'min_trophies' => $this->faker->numberBetween(0, 5000),
            'max_trophies' => $this->faker->optional()->numberBetween(500, 10000),
            'color_code' => $league['color'],
            'description' => $league['name'] . ' league - Top 20% promotion Top 40 demotion Titan badges Level 4 Bronze unlock',
            'is_active' => true,
        ];
    }

    public function bronze(): static
    {
        return $this->state(fn (array $attributes) => ['name' => 'Bronze', 'slug' => 'bronze', 'level' => 1, 'min_trophies' => 0, 'max_trophies' => 499, 'color_code' => '#CD7F32']);
    }

    public function titan(): static
    {
        return $this->state(fn (array $attributes) => ['name' => 'Titan', 'slug' => 'titan', 'level' => 6, 'min_trophies' => 5000, 'max_trophies' => null, 'color_code' => '#FF4500']);
    }
}
