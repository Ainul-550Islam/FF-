<?php

namespace App\Http\Controllers\Api\V1\Gameberry;

use App\Http\Controllers\Controller;
use App\Models\PrivateTableParticipant;
use App\Services\Gameberry\PrivateTableService;
use App\Services\Gameberry\TeamUpService;
use Illuminate\Http\Request;

class TeamUpApiController extends Controller
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
            return response()->json(['success' => false, 'error' => 'Table not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $this->teamUpService->getTeamUpStatus($table)]);
    }

    public function joinTeam(Request $request, string $code)
    {
        $request->validate(['team' => 'required|in:team_a,team_b']);
        $userId = $request->user()->id;
        $table = $this->tableService->getTableByCode($code);
        if (! $table) {
            return response()->json(['success' => false, 'error' => 'Table not found'], 404);
        }
        if (! $table->is_team_up) {
            return response()->json(['success' => false, 'error' => 'Not team up'], 400);
        }
        $participant = PrivateTableParticipant::where('private_table_id', $table->id)->where('user_id', $userId)->first();
        if (! $participant) {
            return response()->json(['success' => false, 'error' => 'Not participant'], 400);
        }
        if ($this->teamUpService->isTeamFull($table, $request->team)) {
            return response()->json(['success' => false, 'error' => 'Team full'], 400);
        }
        $participant->team = $request->team;
        $participant->save();

        return response()->json(['success' => true, 'data' => $participant]);
    }
}
