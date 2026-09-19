<?php
namespace Database\Factories;
use App\Models\GemWallet;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
class GemWalletFactory extends Factory
{
    protected $model = GemWallet::class;
    public function definition(): array
    {
        $balance = $this->faker->numberBetween(0, 1000);
        return [
            'user_id' => User::factory(),
            'gem_balance' => $balance,
            'total_earned' => $balance + $this->faker->numberBetween(0, 500),
            'total_spent' => $this->faker->numberBetween(0, 500),
            'total_purchased' => $this->faker->numberBetween(0, 200),
        ];
    }
}
