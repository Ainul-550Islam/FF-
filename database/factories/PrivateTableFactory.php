<?php

namespace Database\Factories;

use App\Models\PrivateTable;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PrivateTableFactory extends Factory
{
    protected $model = PrivateTable::class;

    public function definition(): array
    {
        $code = strtoupper(Str::random(6));
        $modes = ['classic', 'master', 'quick', 'team_up'];

        return [
            'host_id' => User::factory(),
            'code' => $code,
            'link' => url("/private-table/{$code}"),
            'game_mode' => $this->faker->randomElement($modes),
            'game_variation' => $this->faker->randomElement(['classic', 'master', 'quick']),
            'max_players' => $this->faker->randomElement([2, 4]),
            'bet_amount_minor' => $this->faker->randomElement([100, 500, 1000, 5000]),
            'is_team_up' => $this->faker->boolean(20),
            'is_private' => true,
            'status' => $this->faker->randomElement(['waiting', 'playing', 'finished']),
            'expires_at' => now()->addHours(2),
        ];
    }

    public function waiting(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'waiting', 'expires_at' => now()->addHours(2)]);
    }

    public function teamUp(): static
    {
        return $this->state(fn (array $attributes) => ['is_team_up' => true, 'game_mode' => 'team_up', 'max_players' => 4]);
    }

    public function classic(): static
    {
        return $this->state(fn (array $attributes) => ['game_mode' => 'classic', 'game_variation' => 'classic', 'max_players' => 4]);
    }
}
