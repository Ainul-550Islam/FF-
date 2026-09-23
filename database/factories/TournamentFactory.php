<?php

namespace Database\Factories;

use App\Models\Tournament;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TournamentFactory extends Factory
{
    protected $model = Tournament::class;

    public function definition(): array
    {
        $name = fake()->words(3, true).' Cup';

        return ['name' => $name, 'slug' => Str::slug($name).'-'.Str::random(6), 'status' => 'open', 'entry_fee_minor' => 0, 'prize_pool_minor' => 100000, 'max_teams' => 16];
    }
}
