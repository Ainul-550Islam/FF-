<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SpinWheel extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'cost_gold', 'is_active', 'rewards_config', 'daily_free_spins', 'max_spins_per_day'];

    protected $casts = ['cost_gold' => 'integer', 'is_active' => 'boolean', 'rewards_config' => 'array', 'daily_free_spins' => 'integer', 'max_spins_per_day' => 'integer'];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function getRewardForSpin(): array
    {
        $config = $this->rewards_config ?? [
            ['result' => 'gold', 'gold' => 200, 'gems' => 0, 'weight' => 40],
            ['result' => 'gems', 'gold' => 0, 'gems' => 5, 'weight' => 30],
            ['result' => 'dice', 'gold' => 0, 'gems' => 0, 'weight' => 20],
            ['result' => 'jackpot', 'gold' => 1000, 'gems' => 20, 'weight' => 10],
        ];
        $totalWeight = array_sum(array_column($config, 'weight'));
        $rand = rand(1, $totalWeight);
        $current = 0;
        foreach ($config as $r) {
            $current += $r['weight'];
            if ($rand <= $current) {
                return $r;
            }
        }

        return $config[0];
    }
}
