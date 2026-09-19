<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserDice extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'dice_id',
        'quantity',
        'is_favorite',
        'is_equipped',
        'acquired_at',
        'acquisition_source',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'is_favorite' => 'boolean',
        'is_equipped' => 'boolean',
        'acquired_at' => 'datetime',
        'acquisition_source' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function dice()
    {
        return $this->belongsTo(Dice::class);
    }

    public function scopeEquipped($query)
    {
        return $query->where('is_equipped', true);
    }

    public function scopeFavorite($query)
    {
        return $query->where('is_favorite', true);
    }

    public function canCollectMore(): bool
    {
        return $this->quantity < ($this->dice->max_collection ?? 52);
    }

    public function isMaxCollection(): bool
    {
        return $this->quantity >= ($this->dice->max_collection ?? 52);
    }
}
