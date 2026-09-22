<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Phase 17 — SEO sitemap.
 *
 * Emits only indexable public resources: the homepage, the tournament
 * listing, public tournament pages, leaderboards for live/finished
 * tournaments and public player/organizer profiles. Private profiles,
 * drafts, cancelled tournaments, admin, wallet, settings, support and every
 * authenticated-only page are excluded by construction. A `noindex` X-Robots
 * header is set on the sitemap itself so search engines index the pages it
 * lists, not the sitemap document.
 */
class SitemapController extends Controller
{
    /**
     * Soft cap on the number of <url> entries emitted per generation.
     */
    private const MAX_URLS = 50000;

    public function index(): Response
    {
        $urls = Cache::remember('seo.sitemap', 3600, function () {
            $urls = [];

            $urls[] = [
                'loc' => route('home'),
                'changefreq' => 'daily',
                'priority' => '1.0',
            ];

            $urls[] = [
                'loc' => route('tournaments.index'),
                'changefreq' => 'hourly',
                'priority' => '0.9',
            ];

            Tournament::query()
                ->whereIn('status', Tournament::PUBLIC_STATUSES)
                ->select(['id', 'slug', 'status', 'updated_at'])
                ->orderBy('id')
                ->limit(10000)
                ->chunkById(500, function ($chunk) use (&$urls) {
                    foreach ($chunk as $tournament) {
                        $urls[] = [
                            'loc' => route('tournaments.show', ['tournament' => $tournament->slug]),
                            'lastmod' => $tournament->updated_at?->toAtomString(),
                            'changefreq' => 'daily',
                            'priority' => '0.8',
                        ];

                        // Standings only become meaningful once matches exist.
                        if (in_array($tournament->status, [Tournament::STATUS_LIVE, Tournament::STATUS_FINISHED], true)) {
                            $urls[] = [
                                'loc' => route('leaderboard.show', ['tournament' => $tournament->slug]),
                                'lastmod' => $tournament->updated_at?->toAtomString(),
                                'changefreq' => 'daily',
                                'priority' => '0.7',
                            ];
                        }
                    }
                });

            User::query()
                ->where('privacy', 'public')
                ->whereIn('role', ['player', 'organizer'])
                ->select(['id', 'updated_at'])
                ->orderBy('id')
                ->limit(10000)
                ->chunkById(500, function ($chunk) use (&$urls) {
                    foreach ($chunk as $user) {
                        $urls[] = [
                            'loc' => route('profile.show', $user),
                            'lastmod' => $user->updated_at?->toAtomString(),
                            'changefreq' => 'weekly',
                            'priority' => '0.5',
                        ];
                    }
                });

            return array_slice($urls, 0, self::MAX_URLS);
        });

        return response()
            ->view('seo.sitemap', ['urls' => $urls])
            ->header('Content-Type', 'text/xml; charset=UTF-8')
            ->header('X-Robots-Tag', 'noindex');
    }

    /**
     * robots.txt — served dynamically so the Sitemap directive can use the
     * configured absolute application URL.
     */
    public function robots(): Response
    {
        $lines = [
            '# FF Arena — robots.txt (Phase 17)',
            '# robots.txt is a crawl hint, not a security control; private data',
            '# is protected by server-side authorization, not by this file.',
            'User-agent: *',
            '',
            'Disallow: /admin',
            'Disallow: /wallet',
            'Disallow: /settings',
            'Disallow: /notifications',
            'Disallow: /support',
            'Disallow: /disputes',
            'Disallow: /moderation',
            'Disallow: /organizer',
            'Disallow: /profile/edit',
            'Disallow: /login',
            'Disallow: /register',
            'Disallow: /forgot-password',
            'Disallow: /reset-password',
            'Disallow: /login/phone',
            'Disallow: /auth/',
            'Disallow: /account',
            'Disallow: /api/',
            'Disallow: /webhooks/',
            '',
            'Allow: /tournaments',
            'Allow: /profile/',
            '',
            'Sitemap: '.route('sitemap'),
            '',
        ];

        return response(implode("\n", $lines), 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
