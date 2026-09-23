<?php

namespace App\Http\Controllers;

use App\Support\Seo;

/**
 * Phase 20 — public marketing/trust pages (P0 launch surfaces).
 *
 * /privacy, /terms, /faq and /contact. These are the stable public URLs the
 * mobile store listing, lifecycle email footers and ad accounts link to.
 * All of them are public and indexable — they are marketing surfaces, not
 * account settings.
 */
class MarketingPageController extends Controller
{
    public function privacy()
    {
        return $this->page(
            'marketing.privacy',
            'Privacy Policy',
            'How FF Arena collects, uses and protects your data — plain-language privacy policy for players and organizers.'
        );
    }

    public function terms()
    {
        return $this->page(
            'marketing.terms',
            'Terms of Service',
            'The rules of the arena: accounts, tournaments, payments, payouts and fair play on FF Arena.'
        );
    }

    public function faq()
    {
        return $this->page(
            'marketing.faq',
            'FAQ — Frequently Asked Questions',
            'Answers about tournaments, entry fees, payments, payouts and fair play on FF Arena.'
        );
    }

    public function contact()
    {
        return $this->page(
            'marketing.contact',
            'Contact & Support',
            'Questions about a tournament, payment or payout? Reach the FF Arena team.'
        );
    }

    protected function page(string $view, string $title, string $description)
    {
        $siteName = (string) config('app.name', 'FF Arena');

        app(Seo::class)
            ->title($title.' — '.$siteName)
            ->description($description)
            ->canonical(route($view))
            ->indexable();

        return view($view);
    }
}
