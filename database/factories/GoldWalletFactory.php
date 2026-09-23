<?php

namespace Database\Factories;

use App\Models\GoldWallet;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class GoldWalletFactory extends Factory
{
    protected $model = GoldWallet::class;

    public function definition(): array
    {
        $balance = $this->faker->numberBetween(0, 100000);

        return [
            'user_id' => User::factory(),
            'gold_balance' => $balance,
            'total_earned' => $balance + $this->faker->numberBetween(0, 50000),
            'total_spent' => $this->faker->numberBetween(0, 50000),
            'total_won' => $this->faker->numberBetween(0, 100000),
            'total_lost' => $this->faker->numberBetween(0, 50000),
        ];
    }
}
