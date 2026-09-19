<?php
namespace Database\Factories;
use App\Models\UserDice;
use App\Models\User;
use App\Models\Dice;
use Illuminate\Database\Eloquent\Factories\Factory;
class UserDiceFactory extends Factory
{
    protected $model = UserDice::class;
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'dice_id' => Dice::factory(),
            'quantity' => $this->faker->numberBetween(1, 52),
            'is_favorite' => $this->faker->boolean(20),
            'is_equipped' => $this->faker->boolean(10),
        ];
    }
    public function equipped(): static { return $this->state(fn(array $attributes) => ['is_equipped' => true]); }
    public function favorite(): static { return $this->state(fn(array $attributes) => ['is_favorite' => true]); }
    public function maxCollection(): static { return $this->state(fn(array $attributes) => ['quantity' => 52]); }
}
