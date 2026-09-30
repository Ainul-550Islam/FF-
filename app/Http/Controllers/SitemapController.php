<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Models\User;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function index(): Response
    {
        $urls = [
            ['loc' => route('home'), 'changefreq' => 'daily', 'priority' => '1.0'],
            ['loc' => route('tournaments.index'), 'changefreq' => 'hourly', 'priority' => '0.9'],
        ];

        // Public indexable tournaments
        $tournaments = Tournament::query()
            ->whereNotIn('status', [Tournament::STATUS_DRAFT, Tournament::STATUS_CANCELLED])
            ->get();

        foreach ($tournaments as $tournament) {
            $urls[] = [
                'loc' => route('tournaments.show', ['tournament' => $tournament->slug]),
                'lastmod' => $tournament->updated_at?->toAtomString(),
                'changefreq' => 'hourly',
                'priority' => '0.8',
            ];

            if (in_array($tournament->status, [Tournament::STATUS_LIVE, Tournament::STATUS_FINISHED])) {
                $urls[] = [
                    'loc' => route('leaderboard.show', ['tournament' => $tournament->slug]),
                    'lastmod' => $tournament->updated_at?->toAtomString(),
                    'changefreq' => 'always',
                    'priority' => '0.7',
                ];
            }
        }

        // Public users
        $publicUsers = User::query()->where('privacy', 'public')->get();
        foreach ($publicUsers as $user) {
            $urls[] = [
                'loc' => route('profile.show', $user),
                'lastmod' => $user->updated_at?->toAtomString(),
                'changefreq' => 'weekly',
                'priority' => '0.5',
            ];
        }

        $xml = view('seo.sitemap', ['urls' => $urls])->render();

        return response($xml, 200, [
            'Content-Type' => 'text/xml; charset=UTF-8',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Disallow: /admin',
            'Disallow: /wallet',
            'Disallow: /settings',
            'Disallow: /profile/edit',
            'Allow: /tournaments',
            'Allow: /blog',
            'Allow: /',
            'Sitemap: '.route('sitemap'),
        ];

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
