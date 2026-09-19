<?php
namespace Database\Factories;
use App\Models\ScratchCard;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
class ScratchCardFactory extends Factory
{
    protected $model = ScratchCard::class;
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'code' => 'SCRATCH-' . strtoupper(Str::random(8)),
            'type' => $this->faker->randomElement(['referral','daily','weekly','event']),
            'reward_minor' => $this->faker->randomElement([500, 1000, 2500, 5000]),
            'reward_gems' => $this->faker->numberBetween(0, 20),
            'status' => $this->faker->randomElement(['unscratched','scratched','claimed']),
            'expires_at' => now()->addDays(7),
        ];
    }
    public function unscratched(): static { return $this->state(fn(array $attributes) => ['status' => 'unscratched']); }
}
