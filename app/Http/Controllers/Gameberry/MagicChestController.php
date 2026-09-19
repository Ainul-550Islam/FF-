<?php

namespace App\Http\Controllers\Gameberry;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\MagicChestService;
use Illuminate\Http\Request;

class MagicChestController extends Controller
{
    protected MagicChestService $chestService;

    public function __construct(MagicChestService $chestService)
    {
        $this->chestService = $chestService;
    }

    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $chests = $this->chestService->getUserChests($userId);
        $available = $this->chestService->getAvailableChests($userId);
        $canGet = $this->chestService->canGetChest($userId);
        $nextTime = $this->chestService->getNextChestTime($userId);

        return view('gameberry.chests.index', compact('chests', 'available', 'canGet', 'nextTime'));
    }

    public function open(Request $request, int $chestId)
    {
        $userId = $request->user()->id;
        try {
            $rewards = $this->chestService->openChest($userId, $chestId);
            $msg = "Chest opened! ";
            if ($rewards['gold'] > 0) $msg .= "+{$rewards['gold']} gold ";
            if ($rewards['gems'] > 0) $msg .= "+{$rewards['gems']} gems ";
            if (!empty($rewards['dices'])) $msg .= "+".count($rewards['dices'])." dice";
            return redirect()->back()->with('success', $msg);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function create(Request $request)
    {
        $request->validate([
            'type' => 'required|in:bronze,silver,gold,magic',
        ]);

        $userId = $request->user()->id;
        try {
            $chest = $this->chestService->createChest($userId, $request->type);
            return redirect()->back()->with('success', "Got {$chest->type} chest - {$chest->gold_reward} gold, {$chest->gem_reward} gems inside!");
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}
