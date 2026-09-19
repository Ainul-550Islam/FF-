<?php

namespace App\Http\Controllers\Gameberry;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\DiceCollectionService;
use App\Services\Gameberry\GemEconomyService;
use App\Models\DiceExchange;
use App\Models\LuckyDice;
use Illuminate\Http\Request;

class DiceController extends Controller
{
    protected DiceCollectionService $diceService;
    protected GemEconomyService $gemService;

    public function __construct(DiceCollectionService $diceService, GemEconomyService $gemService)
    {
        $this->diceService = $diceService;
        $this->gemService = $gemService;
    }

    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $collection = $this->diceService->getUserCollection($userId);
        $rarityCounts = $this->diceService->getRarityCounts($userId);
        $availableDices = $this->diceService->getAvailableDices();

        return view('gameberry.dice.index', compact('collection', 'rarityCounts', 'availableDices'));
    }

    public function collection(Request $request)
    {
        $userId = $request->user()->id;
        $collection = $this->diceService->getUserCollection($userId);
        return view('gameberry.dice.collection', compact('collection'));
    }

    public function show(Request $request, int $diceId)
    {
        $dice = \App\Models\Dice::findOrFail($diceId);
        $userDice = \App\Models\UserDice::where('user_id', $request->user()->id)->where('dice_id', $diceId)->first();
        return view('gameberry.dice.show', compact('dice', 'userDice'));
    }

    public function equip(Request $request, int $diceId)
    {
        $userId = $request->user()->id;
        try {
            $userDice = $this->diceService->equipDice($userId, $diceId);
            return redirect()->back()->with('success', "Equipped {$userDice->dice->name}");
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function favorite(Request $request, int $diceId)
    {
        $userId = $request->user()->id;
        try {
            $userDice = $this->diceService->toggleFavorite($userId, $diceId);
            $msg = $userDice->is_favorite ? 'Added to favorites' : 'Removed from favorites';
            return redirect()->back()->with('success', $msg);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
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
            return redirect()->back()->with('success', 'Dice exchange request sent - Facebook friends only');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function exchanges(Request $request)
    {
        $userId = $request->user()->id;
        $sent = DiceExchange::with(['receiver', 'dice'])->where('sender_id', $userId)->orderByDesc('created_at')->get();
        $received = DiceExchange::with(['sender', 'dice'])->where('receiver_id', $userId)->orderByDesc('created_at')->get();
        return view('gameberry.dice.exchanges', compact('sent', 'received'));
    }

    public function acceptExchange(Request $request, int $exchangeId)
    {
        $userId = $request->user()->id;
        try {
            $exchange = DiceExchange::where('id', $exchangeId)->where('receiver_id', $userId)->firstOrFail();
            $exchange->accept();
            return redirect()->back()->with('success', 'Dice exchange accepted');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function denyExchange(Request $request, int $exchangeId)
    {
        $userId = $request->user()->id;
        try {
            $exchange = DiceExchange::where('id', $exchangeId)->where('receiver_id', $userId)->firstOrFail();
            $exchange->deny();
            return redirect()->back()->with('success', 'Dice exchange denied');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function luckyDice(Request $request)
    {
        $userId = $request->user()->id;
        $luckyDices = LuckyDice::with(['dice', 'sender'])->where('user_id', $userId)->orderByDesc('created_at')->get();
        $unrolled = $luckyDices->where('is_rolled', false);
        return view('gameberry.dice.lucky', compact('luckyDices', 'unrolled'));
    }

    public function rollLuckyDice(Request $request, int $luckyDiceId)
    {
        $userId = $request->user()->id;
        try {
            $luckyDice = LuckyDice::where('id', $luckyDiceId)->where('user_id', $userId)->firstOrFail();
            $gems = $luckyDice->roll();

            // Reward gems
            $this->gemService->rewardLuckyDice($userId, $gems, $luckyDice->pattern);

            return redirect()->back()->with('success', "Rolled {$luckyDice->pattern} - won {$gems} gems!");
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}
