<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="dark light">
    <meta name="theme-color" content="#0a0a0f">
    <meta name="description" content="@yield('description', 'FF Arena - Competitive gaming tournaments platform')">
    
    <title>@yield('title', config('app.name', 'FF Arena'))</title>
    
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
    
    <style>
        /* Critical CSS for FOUC prevention */
        .skip-link { position: absolute; top: -100%; left: 16px; z-index: 9999; padding: 12px 20px; background: #6c5ce7; color: white; border-radius: 8px; font-weight: 600; text-decoration: none; }
        .skip-link:focus { top: 16px; }
    </style>
</head>
<body>
    {{-- Skip Link for Accessibility --}}
    <a href="#main" class="skip-link">{{ __('ui.skip_to_content', [], 'Skip to main content') }}</a>

    {{-- Offline Banner - Internet Check --}}
    <div id="offline-banner" class="offline-banner" role="alert" aria-live="assertive" aria-hidden="true">
        <span class="dot" aria-hidden="true"></span>
        <span>You are offline - check your internet connection</span>
        <button onclick="window.FFArena?.checkInternet()" class="btn btn-sm btn-secondary" style="margin-left: 12px; background: rgba(255,255,255,0.2); border-color: rgba(255,255,255,0.3); color: white;">Retry</button>
    </div>

    <div class="app-shell">
        {{-- Header --}}
        <header class="app-header" role="banner">
            <div class="header-inner">
                <a href="{{ route('home') }}" class="brand" aria-label="FF Arena Home">
                    <span class="brand-icon" aria-hidden="true">FF</span>
                    <span>FF Arena</span>
                </a>

                <nav class="nav-links" data-mobile-nav role="navigation" aria-label="Main navigation">
                    <a href="{{ route('home') }}" class="nav-link {{ request()->routeIs('home') ? 'active' : '' }}">Home</a>
                    <a href="{{ route('tournaments.index') }}" class="nav-link {{ request()->routeIs('tournaments.*') ? 'active' : '' }}">Tournaments</a>
                    <a href="{{ route('leaderboard.show', ['tournament' => 'latest']) }}" class="nav-link {{ request()->routeIs('leaderboard.*') ? 'active' : '' }}">Leaderboard</a>
                    @auth
                        <a href="{{ route('wallet.index') }}" class="nav-link {{ request()->routeIs('wallet.*') ? 'active' : '' }}">Wallet</a>
                        <a href="{{ route('notifications.index') }}" class="nav-link {{ request()->routeIs('notifications.*') ? 'active' : '' }}">
                            Notifications
                            @if(auth()->user()->unreadNotifications ?? false)
                                <span class="unread-badge" aria-label="Unread notifications" style="margin-left: 6px; background: #d63031; color: white; font-size: 10px; padding: 2px 6px; border-radius: 999px;">•</span>
                            @endif
                        </a>
                        @if(auth()->user()->isAdmin())
                            <a href="{{ route('admin.dashboard') }}" class="nav-link {{ request()->routeIs('admin.*') ? 'active' : '' }}">Admin</a>
                        @endif
                    @endauth
                </nav>

                <div style="display: flex; align-items: center; gap: 12px;">
                    {{-- Internet Status Indicator --}}
                    <span data-internet-status class="internet-status online" aria-live="polite" title="Internet connection status">
                        <span class="dot" style="background: var(--success)" aria-hidden="true"></span> Online
                    </span>

                    @auth
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <a href="{{ route('profile.show') }}" style="display: flex; align-items: center; gap: 10px; text-decoration: none; color: inherit;" aria-label="Profile - {{ auth()->user()->display_name_or_name }}">
                                <span class="avatar avatar-sm" data-initials="{{ auth()->user()->initials }}">
                                    @if(auth()->user()->hasAvatar())
                                        <img src="{{ auth()->user()->avatar_url }}" alt="{{ auth()->user()->display_name_or_name }} avatar" width="32" height="32" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                                    @else
                                        <span class="avatar-fallback">{{ auth()->user()->initials }}</span>
                                    @endif
                                </span>
                                <span style="font-weight: 600; font-size: 14px;" class="hide-mobile">{{ auth()->user()->display_name_or_name }}</span>
                            </a>
                            <form method="POST" action="{{ route('logout') }}" style="display: inline;">
                                @csrf
                                <button type="submit" class="btn btn-ghost btn-sm" aria-label="Logout">Logout</button>
                            </form>
                        </div>
                    @else
                        <a href="{{ route('login') }}" class="btn btn-ghost btn-sm">Login</a>
                        <a href="{{ route('register') }}" class="btn btn-primary btn-sm">Register</a>
                    @endauth

                    <button class="mobile-nav-toggle" data-mobile-toggle aria-expanded="false" aria-controls="main-nav" aria-label="Toggle navigation">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M3 12h18M3 6h18M3 18h18"/>
                        </svg>
                    </button>
                </div>
            </div>
        </header>

        {{-- Main --}}
        <main id="main" class="main-content" role="main" tabindex="-1">
            {{-- Flash Messages --}}
            @if(session('success'))
                <div class="alert alert-success" role="status" aria-live="polite">
                    <span aria-hidden="true">✓</span>
                    <span>{{ session('success') }}</span>
                </div>
            @endif
            @if(session('error'))
                <div class="alert alert-danger" role="alert" aria-live="assertive">
                    <span aria-hidden="true">✕</span>
                    <span>{{ session('error') }}</span>
                </div>
            @endif
            @if(session('warning'))
                <div class="alert alert-warning" role="status" aria-live="polite">
                    <span aria-hidden="true">⚠</span>
                    <span>{{ session('warning') }}</span>
                </div>
            @endif
            @if($errors->any())
                <div class="alert alert-danger" role="alert" aria-live="assertive">
                    <span aria-hidden="true">✕</span>
                    <div>
                        <strong>Please fix the following:</strong>
                        <ul style="margin: 8px 0 0; padding-left: 20px;">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            @yield('content')
        </main>

        {{-- Footer --}}
        <footer class="app-footer" role="contentinfo">
            <div style="max-width: 1280px; margin: 0 auto; display: flex; flex-wrap: wrap; gap: 16px; justify-content: space-between; align-items: center;">
                <div>
                    <strong>FF Arena</strong> © {{ date('Y') }} - Competitive Gaming Platform
                    <span style="margin-left: 12px;" data-internet-status class="internet-status online" aria-live="polite"></span>
                </div>
                <nav aria-label="Footer navigation" style="display: flex; gap: 16px;">
                    <a href="{{ route('sitemap') }}" style="color: var(--text-muted); text-decoration: none;">Sitemap</a>
                    <a href="{{ route('health.index') }}" style="color: var(--text-muted); text-decoration: none;">Status</a>
                    <a href="#" onclick="window.FFArena?.checkInternet(); return false;" style="color: var(--text-muted); text-decoration: none;">Check Connection</a>
                </nav>
            </div>
        </footer>
    </div>

    {{-- Toast Container --}}
    <div id="toast-container" class="toast-container" aria-live="polite" aria-atomic="false"></div>

    @stack('scripts')
    
    <script>
        // Critical inline for offline detection before main JS loads
        (function() {
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
</body>
</html>
