<?php
namespace App\Services\Gameberry;
use App\Models\PrivateTable;
use App\Models\PrivateTableParticipant;
class TeamUpService
{
    public function assignTeam(PrivateTable $table): string
    {
        $teamACount = $table->participants()->where('team', 'team_a')->count();
        $teamBCount = $table->participants()->where('team', 'team_b')->count();
        return $teamACount <= $teamBCount ? 'team_a' : 'team_b';
    }
    public function getTeamMembers(PrivateTable $table, string $team): \Illuminate\Database\Eloquent\Collection
    {
        return $table->participants()->with('user')->where('team', $team)->get();
    }
    public function isTeamFull(PrivateTable $table, string $team): bool
    {
        $maxPerTeam = (int) ($table->max_players / 2);
        return $table->participants()->where('team', $team)->count() >= $maxPerTeam;
    }
    public function canStartTeamUp(PrivateTable $table): bool
    {
        if (!$table->is_team_up) return $table->participants()->count() >= 2;
        $teamA = $table->participants()->where('team', 'team_a')->count();
        $teamB = $table->participants()->where('team', 'team_b')->count();
        return $teamA >= 1 && $teamB >= 1 && $table->participants()->count() >= 2;
    }
    public function getTeamUpStatus(PrivateTable $table): array
    {
        $teamA = $this->getTeamMembers($table, 'team_a');
        $teamB = $this->getTeamMembers($table, 'team_b');
        return [
            'is_team_up' => $table->is_team_up,
            'team_a_count' => $teamA->count(),
            'team_b_count' => $teamB->count(),
            'team_a_members' => $teamA,
            'team_b_members' => $teamB,
            'can_start' => $this->canStartTeamUp($table),
            'max_per_team' => (int) ($table->max_players / 2),
        ];
    }
}
