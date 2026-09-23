<?php

namespace Database\Factories;

use App\Models\ChatMessage;
use App\Models\PrivateTable;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ChatMessageFactory extends Factory
{
    protected $model = ChatMessage::class;

    public function definition(): array
    {
        return [
            'private_table_id' => PrivateTable::factory(),
            'user_id' => User::factory(),
            'message' => $this->faker->randomElement(['Good Luck!', 'Well Played!', 'Hurry Up!', 'Oops!', 'Wow!', 'GG', 'Nice Move!', '😊', '😂', '👍', '❤️', '🔥', '🎲']),
            'type' => $this->faker->randomElement(['text', 'emoji', 'quick', 'system']),
            'is_system' => false,
        ];
    }

    public function system(): static
    {
        return $this->state(fn (array $attributes) => ['user_id' => null, 'is_system' => true, 'type' => 'system', 'message' => 'Game started by host!']);
    }

    public function emoji(): static
    {
        return $this->state(fn (array $attributes) => ['type' => 'emoji', 'message' => $this->faker->randomElement(['😊', '😂', '😠', '😢', '😎', '❤️', '👍', '👎', '🔥', '🎲', '👑', '🏆'])]);
    }
}
