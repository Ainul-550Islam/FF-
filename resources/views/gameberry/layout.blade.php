@extends('layouts.app')
@section('content')
<div class="container" style="max-width: 1280px; margin: 0 auto; padding: 24px;">
<div style="display: flex; gap: 20px;">
<div style="width: 250px; flex-shrink: 0;">
<div class="card" style="padding: 16px;">
<h3 style="margin: 0 0 12px;">🎮 Gameberry</h3>
<nav style="display: flex; flex-direction: column; gap: 4px; font-size: 13px;">
<a href="{{ route('gameberry.dashboard.index') }}" style="padding: 8px; border-radius: 6px; text-decoration: none; color: inherit;">📊 Dashboard</a>
<a href="{{ route('gameberry.dice.index') }}" style="padding: 8px; border-radius: 6px; text-decoration: none; color: inherit;">🎲 Dice 250+ Max 52</a>
<a href="{{ route('gameberry.league.index') }}" style="padding: 8px; border-radius: 6px; text-decoration: none; color: inherit;">🏆 League 6-Step</a>
<a href="{{ route('gameberry.private_tables.index') }}" style="padding: 8px; border-radius: 6px; text-decoration: none; color: inherit;">🎮 Private Tables</a>
<a href="{{ route('gameberry.economy.index') }}" style="padding: 8px; border-radius: 6px; text-decoration: none; color: inherit;">💰 Economy</a>
<a href="{{ route('gameberry.social.index') }}" style="padding: 8px; border-radius: 6px; text-decoration: none; color: inherit;">👥 Buddies Max 25</a>
<a href="{{ route('gameberry.spin.index') }}" style="padding: 8px; border-radius: 6px; text-decoration: none; color: inherit;">🎡 Spin2Win</a>
<a href="{{ route('gameberry.chests.index') }}" style="padding: 8px; border-radius: 6px; text-decoration: none; color: inherit;">🎁 Magic Chests</a>
<a href="{{ route('gameberry.events.index') }}" style="padding: 8px; border-radius: 6px; text-decoration: none; color: inherit;">🎉 Weekly Events</a>
<a href="{{ route('gameberry.referral.index') }}" style="padding: 8px; border-radius: 6px; text-decoration: none; color: inherit;">🎟️ Referral BGI20</a>
</nav>
</div>
</div>
<div style="flex: 1;">
@yield('gameberry_content')
</div>
</div>
</div>
@endsection
