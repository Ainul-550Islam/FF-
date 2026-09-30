<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(): View
    {
        $tournaments = Tournament::query()
            ->with('organizer')
            ->withCount(['teams as confirmed_teams_count' => fn ($q) => $q->where('status', 'confirmed')])
            ->whereIn('status', ['open', 'live', 'upcoming', 'published'])
            ->latest()
            ->take(6)
            ->get();

        return view('home', compact('tournaments'));
    }
}
