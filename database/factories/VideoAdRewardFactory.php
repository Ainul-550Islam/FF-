<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VideoAdReward;
use Illuminate\Database\Eloquent\Factories\Factory;

class VideoAdRewardFactory extends Factory
{
    protected $model = VideoAdReward::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'ad_provider' => $this->faker->randomElement(['admob', 'unity', 'facebook']),
            'status' => 'rewarded',
            'gold_reward' => 100,
            'gem_reward' => 1,
            'daily_limit' => 5,
            'watched_at' => now(),
            'rewarded_at' => now(),
        ];
    }
}
