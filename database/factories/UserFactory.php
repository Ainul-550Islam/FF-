<?php
namespace Database\Factories;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
class UserFactory extends Factory
{
    protected $model = User::class;
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'display_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_admin' => false,
            'is_staff' => false,
            'is_active' => true,
            'phone' => '+8801'.fake()->numerify('#########'),
            'bio' => fake()->sentence(),
            'country' => 'BD',
            'timezone' => 'Asia/Dhaka',
            'locale' => 'en',
        ];
    }
    public function admin(): static { return $this->state(fn(array $a)=>['is_admin'=>true,'is_staff'=>true]); }
}
