<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Support\Seo;

class HomeController extends Controller
{
    public function index()
    {
        $tournaments = Tournament::with('organizer')
            ->withCount('confirmedTeams')
            ->whereIn('status', ['open', 'live', 'finished', 'closed'])
            ->orderByRaw("CASE status WHEN 'live' THEN 0 WHEN 'open' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->limit(12)
            ->get();

        $siteName = (string) config('app.name', 'FF Arena');

        app(Seo::class)
            ->title($siteName.' — Free Fire Tournaments in Bangladesh')
            ->description('Browse live and upcoming Free Fire tournaments in Bangladesh. Register your squad, compete and get paid — the country\'s trusted tournament platform.')
            ->canonical(route('home'))
            ->indexable()
            ->jsonLd([
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => $siteName,
                'url' => route('home'),
                'description' => 'Bangladesh\'s Free Fire tournament platform.',
            ]);

        return view('home', compact('tournaments'));
    }
}