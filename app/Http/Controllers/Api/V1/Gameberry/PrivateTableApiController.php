<?php

namespace App\Http\Controllers\Api\V1\Gameberry;

use App\Http\Controllers\Controller;
use App\Models\PrivateTable;
use App\Services\Gameberry\ChatEmojiService;
use App\Services\Gameberry\PrivateTableService;
use Illuminate\Http\Request;

class PrivateTableApiController extends Controller
{
    protected PrivateTableService $tableService;

    protected ChatEmojiService $chatService;

    public function __construct(PrivateTableService $tableService, ChatEmojiService $chatService)
    {
        $this->tableService = $tableService;
        $this->chatService = $chatService;
    }

    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $myTables = PrivateTable::with(['participants.user'])->where('host_id', $userId)->where('status', '!=', 'expired')->orderByDesc('created_at')->get();
        $joinedTables = PrivateTable::with(['host'])->whereHas('participants', fn ($q) => $q->where('user_id', $userId))->where('host_id', '!=', $userId)->where('status', '!=', 'expired')->orderByDesc('created_at')->get();

        return response()->json(['success' => true, 'data' => ['my_tables' => $myTables, 'joined_tables' => $joinedTables]]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'game_mode' => 'required|in:classic,master,quick,team_up',
            'bet_amount' => 'required|integer|min:100|max:100000',
            'is_team_up' => 'boolean',
        ]);

        $userId = $request->user()->id;
        try {
            $table = $this->tableService->createTable($userId, [
                'game_mode' => $request->game_mode,
                'bet_amount' => $request->bet_amount,
                'is_team_up' => $request->boolean('is_team_up'),
                'variation' => $request->get('variation', 'classic'),
            ]);

            return response()->json(['success' => true, 'data' => $table, 'message' => "Table created Code: {$table->code} Link: {$table->link}"], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function show(Request $request, string $code)
    {
        $table = $this->tableService->getTableByCode($code);
        if (! $table) {
            return response()->json(['success' => false, 'error' => 'Table not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $table->load(['host', 'participants.user'])]);
    }

    public function join(Request $request, string $code)
    {
        $userId = $request->user()->id;
        try {
            $participant = $this->tableService->joinTable($userId, $code);

            return response()->json(['success' => true, 'data' => $participant, 'message' => 'Joined - gold at stake']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function leave(Request $request, string $code)
    {
        $userId = $request->user()->id;
        try {
            $this->tableService->leaveTable($userId, $code);

            return response()->json(['success' => true, 'message' => 'Left table']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function ready(Request $request, string $code)
    {
        $request->validate(['is_ready' => 'boolean']);
        $userId = $request->user()->id;
        try {
            $participant = $this->tableService->setReady($userId, $code, $request->boolean('is_ready', true));

            return response()->json(['success' => true, 'data' => $participant]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function start(Request $request, string $code)
    {
        $userId = $request->user()->id;
        try {
            $table = $this->tableService->startGame($userId, $code);
            $this->chatService->sendSystemMessage($code, 'Game started by host!');

            return response()->json(['success' => true, 'data' => $table]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function autoMode(Request $request, string $code)
    {
        $request->validate(['auto_on' => 'required|boolean', 'reason' => 'in:disconnect,afk,manual']);
        $userId = $request->user()->id;
        try {
            $this->tableService->setAutoMode($userId, $code, $request->boolean('auto_on'), $request->get('reason', 'disconnect'));

            return response()->json(['success' => true, 'message' => $request->boolean('auto_on') ? 'Auto mode ON' : 'Auto mode OFF']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function share(Request $request, string $code)
    {
        $table = $this->tableService->getTableByCode($code);
        if (! $table) {
            return response()->json(['success' => false, 'error' => 'Table not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'code' => $table->code,
                'link' => $table->link ?? url("/private-table/{$table->code}"),
                'game_mode' => $table->game_mode,
                'bet_amount' => $table->bet_amount_minor,
                'share_text' => "Join my Ludo table! Code: {$table->code} Link: {$table->link} Bet: {$table->bet_amount_minor} gold - Team Up: ".($table->is_team_up ? 'Yes' : 'No'),
            ],
        ]);
    }

    public function challenge(Request $request)
    {
        $request->validate([
            'challenged_id' => 'required|exists:users,id',
            'bet_amount' => 'integer|min:100|max:100000',
        ]);

        $userId = $request->user()->id;
        try {
            $challenge = $this->tableService->challengeFriend($userId, $request->challenged_id, 'private_table', $request->get('bet_amount', 100));

            return response()->json(['success' => true, 'data' => $challenge, 'message' => 'Challenge button - challenge sent']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }
}
