<?php
namespace Database\Factories;
use App\Models\WeeklyEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
class WeeklyEventFactory extends Factory
{
    protected $model = WeeklyEvent::class;
    public function definition(): array
    {
        $name = $this->faker->words(3, true) . ' Event';
        return [
            'name' => $name,
            'slug' => Str::slug($name) . '-' . $this->faker->unique()->numberBetween(1, 10000),
            'description' => $this->faker->sentence() . ' - Gameberry weekly special event evolving engaging social rewarding',
            'type' => $this->faker->randomElement(['gold_rush','dice_collector','titan_challenge','team_up','spin']),
            'starts_at' => now()->startOfWeek(),
            'ends_at' => now()->endOfWeek(),
            'target_progress' => $this->faker->numberBetween(1, 20),
            'max_participants' => 10000,
            'rewards' => ['gold' => $this->faker->numberBetween(100, 5000), 'gems' => $this->faker->numberBetween(1, 50)],
            'is_active' => true,
        ];
    }
    public function active(): static { return $this->state(fn(array $attributes) => ['is_active' => true, 'starts_at' => now()->subDay(), 'ends_at' => now()->addWeek()]); }
    public function upcoming(): static { return $this->state(fn(array $attributes) => ['is_active' => true, 'starts_at' => now()->addWeek(), 'ends_at' => now()->addWeeks(2)]); }
}
