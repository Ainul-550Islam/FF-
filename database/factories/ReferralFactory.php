<?php

namespace Database\Factories;

use App\Models\Referral;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ReferralFactory extends Factory
{
    protected $model = Referral::class;

    public function definition(): array
    {
        return [
            'referrer_id' => User::factory(),
            'referred_id' => null,
            'code' => strtoupper(Str::random(4)).'20',
            'status' => 'pending',
            'bonus_minor' => 2500,
            'referred_email_or_phone' => $this->faker->optional()->email(),
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'completed', 'referred_id' => User::factory(), 'completed_at' => now()]);
    }

    public function rewarded(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'rewarded', 'referred_id' => User::factory(), 'completed_at' => now()->subDay(), 'rewarded_at' => now()]);
    }
}
