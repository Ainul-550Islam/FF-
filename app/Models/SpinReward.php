<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SpinReward extends Model
{
    use HasFactory;

    protected $table = 'spin2win_rewards';

    protected $fillable = [
        'user_id',
        'result',
        'gold_amount',
        'gem_amount',
        'dice_id',
        'gold_cost',
        'spun_at',
        'metadata',
    ];

    protected $casts = [
        'gold_amount' => 'integer',
        'gem_amount' => 'integer',
        'gold_cost' => 'integer',
        'spun_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function dice()
    {
        return $this->belongsTo(Dice::class);
    }

    public static function spin(int $userId, int $goldCost = 100): self
    {
        $results = [
            ['result' => 'gold', 'gold' => 200, 'gems' => 0, 'weight' => 40],
            ['result' => 'gems', 'gold' => 0, 'gems' => 5, 'weight' => 30],
            ['result' => 'dice', 'gold' => 0, 'gems' => 0, 'weight' => 20],
            ['result' => 'jackpot', 'gold' => 1000, 'gems' => 20, 'weight' => 10],
        ];

        $totalWeight = array_sum(array_column($results, 'weight'));
        $rand = rand(1, $totalWeight);
        $current = 0;
        $selected = $results[0];

        foreach ($results as $r) {
            $current += $r['weight'];
            if ($rand <= $current) {
                $selected = $r;
                break;
            }
        }

        return self::create([
            'user_id' => $userId,
            'result' => $selected['result'],
            'gold_amount' => $selected['gold'],
            'gem_amount' => $selected['gems'],
            'gold_cost' => $goldCost,
            'spun_at' => now(),
        ]);
    }
}
