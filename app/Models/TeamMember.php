<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeamMember extends Model
{
    use HasFactory;

    /**
     * team_id is set server-side from the authenticated team's relationship,
     * never from client input.
     */
    protected $fillable = [
        'player_name',
        'game_uid',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function belongsToTeam(Team $team): bool
    {
        return $this->team_id === $team->id;
    }
}
