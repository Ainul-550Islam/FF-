<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LuckyDice extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'dice_id',
        'sender_id',
        'pattern',
        'is_rolled',
        'rolled_at',
        'gem_reward',
    ];

    protected $casts = [
        'is_rolled' => 'boolean',
        'rolled_at' => 'datetime',
        'gem_reward' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function dice()
    {
        return $this->belongsTo(Dice::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function scopeUnrolled($query)
    {
        return $query->where('is_rolled', false);
    }

    public function scopeRolled($query)
    {
        return $query->where('is_rolled', true);
    }

    public function roll(): int
    {
        if ($this->is_rolled) {
            throw new \Exception('Already rolled');
        }

        // Gameberry pattern logic: 3 lucky dice roll for gems
        $patterns = [
            'three_same' => 10,
            'three_different' => 5,
            'sequence' => 15,
        ];

        $this->pattern = array_rand($patterns);
        $this->gem_reward = $patterns[$this->pattern];
        $this->is_rolled = true;
        $this->rolled_at = now();
        $this->save();

        return $this->gem_reward;
    }

    public static function canReceiveMore(int $userId): bool
    {
        $count = self::where('user_id', $userId)->where('is_rolled', false)->count();
        return $count < 52; // Gameberry max 52
    }
}
