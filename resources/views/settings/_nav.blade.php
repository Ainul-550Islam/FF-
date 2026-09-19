<nav class="settings-nav" aria-label="Settings navigation">
    <div style="padding: 8px 12px; font-weight: 800; font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted);">Settings</div>
    
    <a href="{{ route('profile.edit') }}" class="settings-nav-item {{ request()->routeIs('profile.*') ? 'active' : '' }}">
        <span aria-hidden="true">👤</span> Profile & Avatar
    </a>
    <a href="{{ route('settings.security') }}" class="settings-nav-item {{ request()->routeIs('settings.security*') ? 'active' : '' }}">
        <span aria-hidden="true">🔒</span> Security
    </a>
    <a href="{{ route('settings.sessions') }}" class="settings-nav-item {{ request()->routeIs('settings.sessions*') ? 'active' : '' }}">
        <span aria-hidden="true">💻</span> Sessions
        <span data-internet-status class="internet-status online" style="margin-left: auto; font-size: 10px;"></span>
    </a>
    <a href="{{ route('settings.login-history') }}" class="settings-nav-item {{ request()->routeIs('settings.login-history*') ? 'active' : '' }}">
        <span aria-hidden="true">📜</span> Login History
    </a>
    <a href="{{ route('settings.connected-accounts') }}" class="settings-nav-item {{ request()->routeIs('settings.connected-accounts*') ? 'active' : '' }}">
        <span aria-hidden="true">🔗</span> Connected Accounts
    </a>
    <a href="{{ route('settings.payment-methods') }}" class="settings-nav-item {{ request()->routeIs('settings.payment-methods*') ? 'active' : '' }}">
        <span aria-hidden="true">💳</span> Payment Methods
    </a>
    <a href="{{ route('wallet.index') }}" class="settings-nav-item {{ request()->routeIs('wallet.*') ? 'active' : '' }}">
        <span aria-hidden="true">👛</span> Wallet & Ledger
    </a>

    <div style="margin-top: 12px; padding: 12px; background: var(--bg-elevated); border-radius: 8px; border: 1px solid var(--border);">
        <div style="font-size: 12px; font-weight: 600; margin-bottom: 4px;">Connection</div>
        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            <span data-internet-status class="internet-status online"></span>
            <button onclick="window.FFArena?.checkInternet()" class="btn btn-ghost btn-sm" style="font-size: 11px; padding: 4px 8px; min-height: 28px;">Check</button>
        </div>
        <div class="text-muted" style="font-size: 11px; margin-top: 6px;">We check connectivity every 30s and before financial actions.</div>
    </div>
</nav>
