<?php
namespace Database\Factories;
use App\Models\Challenge;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
class ChallengeFactory extends Factory
{
    protected $model = Challenge::class;
    public function definition(): array
    {
        return [
            'challenger_id' => User::factory(),
            'challenged_id' => User::factory(),
            'private_table_id' => null,
            'type' => $this->faker->randomElement(['buddy_challenge','private_table']),
            'status' => 'pending',
            'bet_amount_minor' => $this->faker->randomElement([100, 500, 1000]),
            'expires_at' => now()->addMinutes(5),
        ];
    }
    public function accepted(): static { return $this->state(fn(array $attributes) => ['status' => 'accepted', 'responded_at' => now()]); }
    public function expired(): static { return $this->state(fn(array $attributes) => ['status' => 'pending', 'expires_at' => now()->subMinutes(10)]); }
}
