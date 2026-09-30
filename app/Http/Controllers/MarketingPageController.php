<?php

namespace App\Http\Controllers;

use App\Support\Seo;
use Illuminate\View\View;

class MarketingPageController extends Controller
{
    public function contact(): View
    {
        app(Seo::class)
            ->title('Contact — FF Arena')
            ->description('Get in touch with the FF Arena support and tournament operations team.')
            ->canonical(route('marketing.contact'))
            ->indexable(true);

        return view('marketing.contact');
    }

    public function faq(): View
    {
        app(Seo::class)
            ->title('Frequently Asked Questions — FF Arena')
            ->description('Frequently asked questions about FF Arena Free Fire tournaments, payments, rules, and payouts.')
            ->canonical(route('marketing.faq'))
            ->indexable(true);

        return view('marketing.faq');
    }

    public function privacy(): View
    {
        app(Seo::class)
            ->title('Privacy Policy — FF Arena')
            ->description('FF Arena privacy policy explaining data protection, user accounts, and security compliance.')
            ->canonical(route('marketing.privacy'))
            ->indexable(true);

        return view('marketing.privacy');
    }

    public function terms(): View
    {
        app(Seo::class)
            ->title('Terms of Service — FF Arena')
            ->description('FF Arena terms of service for organizing and competing in verified Free Fire esports tournaments.')
            ->canonical(route('marketing.terms'))
            ->indexable(true);

        return view('marketing.terms');
    }
}
