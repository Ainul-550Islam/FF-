<?php
namespace Database\Factories;
use App\Models\MagicChest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
class MagicChestFactory extends Factory
{
    protected $model = MagicChest::class;
    public function definition(): array
    {
        $types = ['bronze','silver','gold','magic'];
        $type = $this->faker->randomElement($types);
        $config = match($type) {
            'bronze' => ['gold_min' => 50, 'gold_max' => 200, 'gem_min' => 0, 'gem_max' => 2],
            'silver' => ['gold_min' => 200, 'gold_max' => 500, 'gem_min' => 1, 'gem_max' => 5],
            'gold' => ['gold_min' => 500, 'gold_max' => 1500, 'gem_min' => 3, 'gem_max' => 10],
            'magic' => ['gold_min' => 1000, 'gold_max' => 5000, 'gem_min' => 5, 'gem_max' => 20],
            default => ['gold_min' => 50, 'gold_max' => 200, 'gem_min' => 0, 'gem_max' => 2],
        };
        return [
            'user_id' => User::factory(),
            'type' => $type,
            'status' => 'available',
            'gold_reward' => $this->faker->numberBetween($config['gold_min'], $config['gold_max']),
            'gem_reward' => $this->faker->numberBetween($config['gem_min'], $config['gem_max']),
            'dice_rewards' => $this->faker->boolean(30) ? [$this->faker->numberBetween(1, 250)] : [],
            'available_at' => now(),
            'expires_at' => now()->addDay(),
        ];
    }
    public function opened(): static { return $this->state(fn(array $attributes) => ['status' => 'opened', 'opened_at' => now()]); }
}
