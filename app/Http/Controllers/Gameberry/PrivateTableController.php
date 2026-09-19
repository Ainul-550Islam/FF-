<?php

namespace App\Http\Controllers\Gameberry;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\PrivateTableService;
use App\Services\Gameberry\ChatEmojiService;
use Illuminate\Http\Request;

class PrivateTableController extends Controller
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
        $myTables = \App\Models\PrivateTable::with(['participants.user'])
            ->where('host_id', $userId)
            ->where('status', '!=', 'expired')
            ->orderByDesc('created_at')
            ->get();

        $joinedTables = \App\Models\PrivateTable::with(['host', 'participants.user'])
            ->whereHas('participants', fn($q) => $q->where('user_id', $userId))
            ->where('host_id', '!=', $userId)
            ->where('status', '!=', 'expired')
            ->orderByDesc('created_at')
            ->get();

        return view('gameberry.private_tables.index', compact('myTables', 'joinedTables'));
    }

    public function create(Request $request)
    {
        return view('gameberry.private_tables.create');
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

            return redirect()->route('gameberry.private_tables.show', $table->code)->with('success', "Private table created! Code: {$table->code} Link: {$table->link}");
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function show(Request $request, string $code)
    {
        $table = $this->tableService->getTableByCode($code);
        if (!$table) {
            abort(404, 'Table not found');
        }

        $messages = $this->chatService->getMessages($code, 50);
        $emojis = $this->chatService->getEmojis();
        $quickMessages = $this->chatService->getQuickMessages();

        return view('gameberry.private_tables.show', compact('table', 'messages', 'emojis', 'quickMessages'));
    }

    public function join(Request $request, string $code)
    {
        $userId = $request->user()->id;
        try {
            $participant = $this->tableService->joinTable($userId, $code);
            return redirect()->route('gameberry.private_tables.show', strtoupper($code))->with('success', 'Joined table - gold at stake!');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function leave(Request $request, string $code)
    {
        $userId = $request->user()->id;
        try {
            $this->tableService->leaveTable($userId, $code);
            return redirect()->route('gameberry.private_tables.index')->with('success', 'Left table');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function ready(Request $request, string $code)
    {
        $userId = $request->user()->id;
        $isReady = $request->boolean('is_ready', true);
        try {
            $this->tableService->setReady($userId, $code, $isReady);
            return redirect()->back()->with('success', $isReady ? 'Ready!' : 'Not ready');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function start(Request $request, string $code)
    {
        $userId = $request->user()->id;
        try {
            $table = $this->tableService->startGame($userId, $code);
            $this->chatService->sendSystemMessage($code, "Game started by host!");
            return redirect()->route('gameberry.private_tables.show', strtoupper($code))->with('success', 'Game started!');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function share(Request $request, string $code)
    {
        $table = $this->tableService->getTableByCode($code);
        if (!$table) abort(404);

        $shareData = [
            'code' => $table->code,
            'link' => $table->link ?? url("/private-table/{$table->code}"),
            'game_mode' => $table->game_mode,
            'bet_amount' => $table->bet_amount_minor,
        ];

        return view('gameberry.private_tables.share', compact('table', 'shareData'));
    }

    public function autoMode(Request $request, string $code)
    {
        $userId = $request->user()->id;
        $autoOn = $request->boolean('auto_on', true);
        $reason = $request->get('reason', 'disconnect');

        try {
            $this->tableService->setAutoMode($userId, $code, $autoOn, $reason);
            $msg = $autoOn ? 'Auto mode ON - will play on disconnect' : 'Auto mode OFF';
            return redirect()->back()->with('success', $msg);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}
