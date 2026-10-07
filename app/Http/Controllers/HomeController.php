<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Support\Seo;
use Illuminate\View\View;

/**
 * Public marketing/home page.
 *
 * Phase 17 — the home page is the platform's primary indexable landing page,
 * so it opts into SEO explicitly: a stable title, a snippet-length description,
 * its own canonical URL and a WebSite entity in JSON-LD (with the site search
 * action, which is what makes a "sitelinks search box" eligible). Pages that do
 * not opt in stay `noindex` by default (see App\Support\Seo), so this can never
 * leak private content to crawlers.
 */
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

        $siteName = (string) config('app.name', 'FF Arena');

        app(Seo::class)
            ->title('FF Arena — Free Fire Tournaments in Bangladesh')
            ->description(
                'Join Free Fire tournaments in Bangladesh on FF Arena: register a squad, '
                .'compete in daily and weekly cups, track live brackets and get paid straight '
                .'to your wallet.'
            )
            ->canonical(route('home'))
            ->indexable(true)
            ->ogType('website')
            ->jsonLd([
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => $siteName,
                'alternateName' => 'FFArena',
                'url' => route('home'),
                'inLanguage' => 'en-BD',
                'description' => 'Free Fire tournament platform for Bangladesh — organize, register, compete and get paid.',
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => [
                        '@type' => 'EntryPoint',
                        'urlTemplate' => route('tournaments.index').'?q={search_term_string}',
                    ],
                    'query-input' => 'required name=search_term_string',
                ],
                'publisher' => [
                    '@type' => 'Organization',
                    'name' => $siteName,
                    'url' => route('home'),
                ],
            ]);

        return view('home', compact('tournaments'));
    }
}
