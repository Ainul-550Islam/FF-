<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Dice extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'rarity',
        'theme',
        'color',
        'image_path',
        'level_required',
        'gold_price_minor',
        'gem_price',
        'is_lucky',
        'is_collectible',
        'is_tradable',
        'max_collection',
        'metadata',
    ];

    protected $casts = [
        'is_lucky' => 'boolean',
        'is_collectible' => 'boolean',
        'is_tradable' => 'boolean',
        'level_required' => 'integer',
        'gold_price_minor' => 'integer',
        'gem_price' => 'integer',
        'max_collection' => 'integer',
        'metadata' => 'array',
    ];

    public function userDices()
    {
        return $this->hasMany(UserDice::class);
    }

    public function luckyDices()
    {
        return $this->hasMany(LuckyDice::class);
    }

    public function scopeCollectible($query)
    {
        return $query->where('is_collectible', true);
    }

    public function scopeLucky($query)
    {
        return $query->where('is_lucky', true);
    }

    public function scopeRarity($query, string $rarity)
    {
        return $query->where('rarity', $rarity);
    }

    public function isRare(): bool
    {
        return in_array($this->rarity, ['rare', 'epic', 'legendary', 'titan']);
    }

    public function getImageUrlAttribute(): string
    {
        if ($this->image_path) {
            return asset('storage/'.$this->image_path);
        }

        return asset('images/dice-default.png');
    }
}
