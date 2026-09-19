<?php

namespace App\Http\Controllers\Gameberry;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\SpinService;
use Illuminate\Http\Request;

class SpinController extends Controller
{
    protected SpinService $spinService;

    public function __construct(SpinService $spinService)
    {
        $this->spinService = $spinService;
    }

    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $stats = $this->spinService->getSpinStats($userId);
        $history = $this->spinService->getSpinHistory($userId, 20);

        return view('gameberry.spin.index', compact('stats', 'history'));
    }

    public function spin(Request $request)
    {
        $request->validate([
            'use_free' => 'boolean',
        ]);

        $userId = $request->user()->id;
        try {
            $spin = $this->spinService->spin($userId, $request->boolean('use_free', false));
            $msg = "Spin result: {$spin->result}";
            if ($spin->gold_amount > 0) $msg .= " +{$spin->gold_amount} gold";
            if ($spin->gem_amount > 0) $msg .= " +{$spin->gem_amount} gems";
            return redirect()->back()->with('success', $msg);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function history(Request $request)
    {
        $userId = $request->user()->id;
        $history = $this->spinService->getSpinHistory($userId, 50);
        return view('gameberry.spin.history', compact('history'));
    }
}
