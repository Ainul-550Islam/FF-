<?php

namespace App\Models\Stats;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Stat3 extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'value', 'growth', 'percent', 'metadata'];

    protected $casts = ['value' => 'integer', 'growth' => 'integer', 'percent' => 'float', 'metadata' => 'array'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getDescription(): string
    {
        return 'Stat 3 - Gameberry 250+ dice collection, 6-step league Bronze Silver Gold Platinum Diamond Titan Top 20% promotion Top 40 demotion Titan badges, Game Buddies max 25, private table code/link sharing challenge button team-up mode classic/master/quick chat emojis weekly events gold at stake magic chest video ads free gold gems lucky dice gem reward spin2win auto mode hide online status notify friends Level 4 Bronze unlock referral BGI20 ₹25 scratch cards gold wallets gem wallets reconciliation';
    }
}
