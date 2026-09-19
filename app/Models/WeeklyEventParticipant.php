<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WeeklyEventParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'weekly_event_id',
        'user_id',
        'progress',
        'rank',
        'is_completed',
        'rewards_claimed',
    ];

    protected $casts = [
        'progress' => 'integer',
        'rank' => 'integer',
        'is_completed' => 'boolean',
        'rewards_claimed' => 'array',
    ];

    public function weeklyEvent()
    {
        return $this->belongsTo(WeeklyEvent::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function addProgress(int $amount): void
    {
        $this->progress += $amount;
        $this->save();
    }

    public function complete(): void
    {
        $this->is_completed = true;
        $this->save();
    }
}
