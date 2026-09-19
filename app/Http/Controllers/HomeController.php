<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index(Request $request)
    {
        $tournaments = Tournament::where('status', '!=', 'draft')
            ->orderBy('starts_at', 'desc')
            ->limit(6)
            ->get();

        return view('home', compact('tournaments'));
    }

    public function livePoll(Request $request)
    {
        $cursor = $request->input('cursor', 0);
        $events = [];

        if ($request->user()) {
            // Simulate live events - in production would query live_events table
            $events = [
                ['id' => 1, 'type' => 'tournament.update', 'message' => 'Tournament starting soon', 'cursor' => $cursor + 1],
            ];
        }

        return view('live.poll', [
            'events' => $events,
            'cursor' => $cursor + 1,
            'online' => true,
        ]);
    }
}
