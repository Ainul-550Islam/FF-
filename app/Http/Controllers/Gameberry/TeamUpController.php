<?php

namespace App\Http\Controllers\Gameberry;

use App\Http\Controllers\Controller;
use App\Models\PrivateTableParticipant;
use App\Services\Gameberry\PrivateTableService;
use App\Services\Gameberry\TeamUpService;
use Illuminate\Http\Request;

class TeamUpController extends Controller
{
    protected TeamUpService $teamUpService;

    protected PrivateTableService $tableService;

    public function __construct(TeamUpService $teamUpService, PrivateTableService $tableService)
    {
        $this->teamUpService = $teamUpService;
        $this->tableService = $tableService;
    }

    public function show(Request $request, string $code)
    {
        $table = $this->tableService->getTableByCode($code);
        if (! $table) {
            abort(404);
        }
        $status = $this->teamUpService->getTeamUpStatus($table);

        return view('gameberry.team_up.show', compact('table', 'status'));
    }

    public function joinTeam(Request $request, string $code)
    {
        $request->validate(['team' => 'required|in:team_a,team_b']);
        $userId = $request->user()->id;
        $table = $this->tableService->getTableByCode($code);
        if (! $table) {
            abort(404);
        }
        if (! $table->is_team_up) {
            return redirect()->back()->with('error', 'Not a team up table');
        }
        $participant = PrivateTableParticipant::where('private_table_id', $table->id)->where('user_id', $userId)->first();
        if (! $participant) {
            return redirect()->back()->with('error', 'Not a participant');
        }
        if ($this->teamUpService->isTeamFull($table, $request->team)) {
            return redirect()->back()->with('error', 'Team full');
        }
        $participant->team = $request->team;
        $participant->save();

        return redirect()->back()->with('success', "Joined {$request->team}");
    }
}
