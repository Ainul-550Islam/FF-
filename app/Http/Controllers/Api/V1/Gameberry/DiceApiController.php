<?php

namespace App\Http\Controllers\Api\V1\Gameberry;

use App\Http\Controllers\Controller;
use App\Models\DiceExchange;
use App\Models\LuckyDice;
use App\Services\Gameberry\DiceCollectionService;
use App\Services\Gameberry\GemEconomyService;
use Illuminate\Http\Request;

class DiceApiController extends Controller
{
    protected DiceCollectionService $diceService;

    protected GemEconomyService $gemService;

    public function __construct(DiceCollectionService $diceService, GemEconomyService $gemService)
    {
        $this->diceService = $diceService;
        $this->gemService = $gemService;
    }

    public function collection(Request $request)
    {
        $userId = $request->user()->id;
        $collection = $this->diceService->getUserCollection($userId);

        return response()->json(['success' => true, 'data' => $collection]);
    }

    public function available()
    {
        $dices = $this->diceService->getAvailableDices();

        return response()->json(['success' => true, 'data' => $dices]);
    }

    public function equip(Request $request, int $diceId)
    {
        $userId = $request->user()->id;
        try {
            $userDice = $this->diceService->equipDice($userId, $diceId);

            return response()->json(['success' => true, 'data' => $userDice->load('dice'), 'message' => 'Equipped']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function favorite(Request $request, int $diceId)
    {
        $userId = $request->user()->id;
        try {
            $userDice = $this->diceService->toggleFavorite($userId, $diceId);

            return response()->json(['success' => true, 'data' => $userDice]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function exchange(Request $request)
    {
        $request->validate([
            'receiver_id' => 'required|exists:users,id',
            'dice_id' => 'required|exists:dices,id',
        ]);

        $senderId = $request->user()->id;
        try {
            $exchange = $this->diceService->exchangeDice($senderId, $request->receiver_id, $request->dice_id);

            return response()->json(['success' => true, 'data' => $exchange, 'message' => 'Exchange request sent - Facebook only']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function exchanges(Request $request)
    {
        $userId = $request->user()->id;
        $sent = DiceExchange::with(['receiver', 'dice'])->where('sender_id', $userId)->orderByDesc('created_at')->get();
        $received = DiceExchange::with(['sender', 'dice'])->where('receiver_id', $userId)->orderByDesc('created_at')->get();

        return response()->json(['success' => true, 'data' => ['sent' => $sent, 'received' => $received]]);
    }

    public function acceptExchange(Request $request, int $exchangeId)
    {
        $userId = $request->user()->id;
        try {
            $exchange = DiceExchange::where('id', $exchangeId)->where('receiver_id', $userId)->firstOrFail();
            $exchange->accept();

            return response()->json(['success' => true, 'message' => 'Accepted']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function denyExchange(Request $request, int $exchangeId)
    {
        $userId = $request->user()->id;
        try {
            $exchange = DiceExchange::where('id', $exchangeId)->where('receiver_id', $userId)->firstOrFail();
            $exchange->deny();

            return response()->json(['success' => true, 'message' => 'Denied']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function luckyDice(Request $request)
    {
        $userId = $request->user()->id;
        $luckyDices = LuckyDice::with(['dice', 'sender'])->where('user_id', $userId)->orderByDesc('created_at')->get();

        return response()->json(['success' => true, 'data' => $luckyDices]);
    }

    public function rollLuckyDice(Request $request, int $luckyDiceId)
    {
        $userId = $request->user()->id;
        try {
            $luckyDice = LuckyDice::where('id', $luckyDiceId)->where('user_id', $userId)->firstOrFail();
            $gems = $luckyDice->roll();
            $this->gemService->rewardLuckyDice($userId, $gems, $luckyDice->pattern);

            return response()->json(['success' => true, 'data' => $luckyDice, 'gems_won' => $gems, 'pattern' => $luckyDice->pattern]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }
}
