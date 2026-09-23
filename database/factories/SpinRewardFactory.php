<?php

namespace Database\Factories;

use App\Models\SpinReward;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SpinRewardFactory extends Factory
{
    protected $model = SpinReward::class;

    public function definition(): array
    {
        $results = ['gold', 'gems', 'dice', 'jackpot'];
        $result = $this->faker->randomElement($results);

        return [
            'user_id' => User::factory(),
            'result' => $result,
            'gold_amount' => $result === 'gold' ? $this->faker->numberBetween(100, 1000) : ($result === 'jackpot' ? 1000 : 0),
            'gem_amount' => $result === 'gems' ? $this->faker->numberBetween(1, 10) : ($result === 'jackpot' ? 20 : 0),
            'dice_id' => $result === 'dice' ? 1 : null,
            'gold_cost' => 100,
            'spun_at' => now(),
        ];
    }

    public function jackpot(): static
    {
        return $this->state(fn (array $attributes) => ['result' => 'jackpot', 'gold_amount' => 1000, 'gem_amount' => 20]);
    }
}
