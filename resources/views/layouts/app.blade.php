<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0b0e1a">

    {{-- SEO (Phase 17) — title honours per-page @section('title') for the
         long-tail pages, falling back to the SEO manager's computed title. --}}
    <title>@yield('title', $seo['title'])</title>
    <meta name="description" content="{{ $seo['description'] }}">

    @if ($seo['indexable'])
        <link rel="canonical" href="{{ $seo['canonical'] }}">
    @else
        <meta name="robots" content="noindex, nofollow">
    @endif

    {{-- Open Graph / social previews (only publicly accessible data).
         Phase 20: every page falls back to the branded 1200x630 share card
         so social previews are always a controlled FF Arena card, and the
         Twitter/X card carries the same image. --}}
    @php
        $ogImage = $seo['og_image'] ?? asset((string) config('marketing.og.default_image', 'img/og-default.png'));
        $ogImageAlt = $seo['og_image_alt'] ?? (string) config('marketing.og.default_image_alt', 'FF Arena');
    @endphp
    <meta property="og:site_name" content="{{ config('app.name', 'FF Arena') }}">
    <meta property="og:title" content="@yield('title', $seo['title'])">
    <meta property="og:description" content="{{ $seo['description'] }}">
    <meta property="og:type" content="{{ $seo['og_type'] }}">
    <meta property="og:url" content="{{ $seo['canonical'] }}">
    <meta property="og:image" content="{{ $ogImage }}">
    <meta property="og:image:alt" content="{{ $ogImageAlt }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="@yield('title', $seo['title'])">
    <meta name="twitter:description" content="{{ $seo['description'] }}">
    <meta name="twitter:image" content="{{ $ogImage }}">
    @if (config('marketing.og.twitter_site'))
        <meta name="twitter:site" content="{{ config('marketing.og.twitter_site') }}">
    @endif

    {{-- Site identity --}}
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

    {{-- JSON-LD structured data (server-encoded, HTML-safe) --}}
    @if ($seo['jsonld'])
        <script type="application/ld+json">{!! $seo['jsonld'] !!}</script>
    @endif

    {{-- Page-specific head additions --}}
    @stack('head')

    {{-- Stylesheet: prefer the Vite build when present, else the served copy
         (identical content; see public/css/app.css + resources/css/app.css). --}}
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    @endif

    {{-- Shared behaviours, deferred so they never block first paint --}}
    <script src="{{ asset('js/app.js') }}" defer></script>
</head>
<body id="top">
    <a class="skip-link" href="#main">{{ __('ui.skip_to_content') }}</a>

    {{-- Offline banner + internet status (progressive enhancement; the inline
         script below keeps it correct before public/js/app.js loads). --}}
    <div id="offline-banner" class="offline-banner" role="alert" aria-live="assertive" aria-hidden="true">
        <span class="dot" aria-hidden="true"></span>
        <span>You are offline - check your internet connection</span>
        <button onclick="window.FFArena?.checkInternet()" class="btn btn-sm btn-secondary"
                style="margin-left: 12px; background: rgba(255,255,255,0.2); border-color: rgba(255,255,255,0.3); color: white;">Retry</button>
    </div>

    <header class="site-header">
        <div class="container nav-bar">
            <a href="{{ route('home') }}" class="brand" aria-label="{{ config('app.name', 'FF Arena') }} — home">
                <svg class="brand-mark" viewBox="0 0 64 64" aria-hidden="true" focusable="false">
                    <defs>
                        <linearGradient id="brandFg" x1="0" y1="0" x2="1" y2="1">
                            <stop offset="0" stop-color="#22d3ee"/><stop offset="1" stop-color="#a855f7"/>
                        </linearGradient>
                    </defs>
                    <rect x="2" y="2" width="60" height="60" rx="14" fill="#141a2e" stroke="#28335a" stroke-width="2"/>
                    <path d="M22 14h22l-5 14h-8l-2 8h8l-5 14H20l5-14h8l2-8h-8z" fill="url(#brandFg)"/>
                </svg>
                FF<span>ARENA</span>
            </a>

            <span data-internet-status class="internet-status online" aria-live="polite" title="Internet connection status">
                <span class="dot" style="background: var(--success)" aria-hidden="true"></span> Online
            </span>

            <button class="nav-toggle" type="button" data-nav-toggle
                    aria-expanded="false" aria-controls="site-nav">
                <span class="nav-toggle-icon" aria-hidden="true"></span>
                <span class="sr-only">{{ __('ui.menu') }}</span>
            </button>

            <nav id="site-nav" class="nav-links" aria-label="{{ __('ui.primary_navigation') }}"
                 @auth data-unread-url="{{ route('notifications.unread') }}" @endauth>
                <a class="nav-link" href="{{ route('tournaments.index') }}">{{ __('ui.tournaments') }}</a>

                @auth
                    @if (auth()->user()->isOrganizer() || auth()->user()->isAdmin())
                        <a class="nav-link" href="{{ route('tournaments.create') }}">+ Create Tournament</a>
                    @endif
                    <a class="nav-link" href="{{ route('wallet.index') }}">Wallet</a>
                    <a class="nav-link" href="{{ route('support.index') }}">Support</a>
                    <a class="nav-link" href="{{ route('notifications.index') }}">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
                            <path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        {{ __('ui.notifications') }}
                        <span class="nav-badge" id="unread-badge" aria-live="polite"
                              @if (($unreadNotifications ?? 0) === 0) hidden @endif>
                            {{ ($unreadNotifications ?? 0) > 99 ? '99+' : ($unreadNotifications ?? 0) }}
                        </span>
                    </a>
                    @if (auth()->user()->isAdmin() || auth()->user()->isModerator() || auth()->user()->isOrganizer())
                        <a class="nav-link" href="{{ route('moderation.index') }}">Moderation</a>
                    @endif
                    @if (auth()->user()->isAdmin() || auth()->user()->isModerator())
                        <a class="nav-link" href="{{ route('moderation.security') }}">Security</a>
                    @endif
                    @if (auth()->user()->isAdmin() || auth()->user()->isModerator())
                        <a class="nav-link" href="{{ route('admin.support.index') }}">Support Queue</a>
                    @endif
                    @if (auth()->user()->isAdmin())
                        <a class="nav-link" href="{{ route('admin.accounts.index') }}">Accounts</a>
                        <a class="nav-link" href="{{ route('admin.analytics.index') }}">Analytics</a>
                        <a class="nav-link" href="{{ route('admin.audit.index') }}">Audit</a>
                        <a class="nav-link" href="{{ route('admin.dashboard') }}">Admin</a>
                    @endif
                    <a class="nav-link" href="{{ route('profile.show', auth()->user()) }}">Profile</a>
                    <a class="nav-link" href="{{ route('profile.edit') }}">Settings</a>

                    <span class="nav-link muted" aria-hidden="true">{{ auth()->user()->name }}</span>

                    <form method="POST" action="{{ route('logout') }}" class="nav-form">
                        @csrf
                        <button type="submit" class="btn btn-ghost btn-sm">{{ __('ui.logout') }}</button>
                    </form>
                @else
                    <a class="nav-link" href="{{ route('login') }}">{{ __('ui.login') }}</a>
                    <a class="btn btn-primary btn-sm" href="{{ route('register') }}">{{ __('ui.register') }}</a>
                @endauth
            </nav>
        </div>
    </header>

    <main id="main" class="site-main container">
        @if (session('success'))
            <div class="alert alert-success" role="status">
                <span aria-hidden="true">✓</span>
                <span>{{ session('success') }}</span>
            </div>
        @endif

        @if (session('error'))
            <div class="alert alert-error" role="alert">
                <span aria-hidden="true">✕</span>
                <span>{{ session('error') }}</span>
            </div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error" role="alert">
                <span aria-hidden="true">✕</span>
                <div>
                    <strong>{{ __('ui.form_errors') }}</strong>
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        @yield('content')
    </main>

    <footer class="site-footer">
        <div class="container">
            <div>
                <strong class="tag">{{ config('app.name', 'FF Arena') }}</strong>
                — Bangladesh's Free Fire tournament platform.
                Legit. Smart. Profitable. No hacks, ever.
            </div>
            <nav aria-label="{{ __('ui.footer_navigation') }}">
                <a href="{{ route('tournaments.index') }}">{{ __('ui.tournaments') }}</a>
                &middot;
                <a href="{{ route('marketing.privacy') }}">Privacy</a>
                &middot;
                <a href="{{ route('marketing.terms') }}">Terms</a>
                &middot;
                <a href="{{ route('marketing.faq') }}">FAQ</a>
                &middot;
                <a href="{{ route('marketing.contact') }}">Contact</a>
                &middot;
                <a href="{{ route('sitemap') }}">Sitemap</a>
                &middot;
                <a href="{{ route('robots') }}">robots.txt</a>
            </nav>
        </div>
    </footer>
    <script>
        // Critical inline for offline detection before public/js/app.js loads.
        (function () {
            const banner = document.getElementById('offline-banner');
            function updateBanner() {
                if (!banner) return;
                if (!navigator.onLine) {
                    banner.classList.add('show');
                    banner.setAttribute('aria-hidden', 'false');
                } else {
                    banner.classList.remove('show');
                    banner.setAttribute('aria-hidden', 'true');
                }
            }
            window.addEventListener('online', updateBanner);
            window.addEventListener('offline', updateBanner);
            updateBanner();
        })();
    </script>

    {{-- Phase 20 — consent banner + consent-aware marketing trackers.
         Both are inert by default: no decision → only the banner shows;
         no credentials configured → no third-party tag is emitted. --}}
    <x-marketing-consent />
    <x-marketing-tracking />
</body>
</html>