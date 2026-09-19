@extends('layouts.app')
@section('title', 'Game Buddies - Max 25')
@section('content')
<div class="container" style="max-width: 1000px; margin: 0 auto; padding: 24px;">
<h1 style="font-size: 28px; font-weight: 800;">👥 Game Buddies - Max 25</h1>
<p style="color: var(--text-muted);">Gameberry max 25 buddies - Challenge button - Team Up - Hide online status - Notify friends</p>
<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 16px; margin-top: 20px;">
@forelse($buddies as $buddy)
<div class="card" style="padding: 16px;">
<div style="display: flex; align-items: center; gap: 12px;">
<div style="width: 48px; height: 48px; border-radius: 50%; background: var(--primary); display: flex; align-items: center; justify-content: center; color: white; font-weight: 800;">{{ substr($buddy->buddy->name ?? 'U',0,1) }}</div>
<div>
<div style="font-weight: 700;">{{ $buddy->buddy->name ?? 'User '.$buddy->buddy_id }}</div>
<div style="font-size: 11px; color: var(--text-muted);">{{ $buddy->buddy->onlineStatus->is_online ?? false ? '🟢 Online' : '🔴 Offline' }} @if($buddy->buddy->onlineStatus->hide_online_status ?? false) 🙈 Hidden @endif {{ $buddy->buddy->onlineStatus->current_game ?? '' }}</div>
</div>
</div>
<div style="display: flex; gap: 6px; margin-top: 12px;">
<form method="POST" action="{{ route('gameberry.social.challenge') }}" style="flex: 1;">@csrf<input type="hidden" name="buddy_id" value="{{ $buddy->buddy_id }}"><input type="hidden" name="bet_amount" value="100"><button type="submit" class="btn btn-sm btn-primary" style="width: 100%;">Challenge 🎯</button></form>
<form method="POST" action="{{ route('gameberry.social.remove_buddy', $buddy->buddy_id) }}" style="flex: 1;">@csrf<button type="submit" class="btn btn-sm btn-ghost" style="width: 100%;">Remove</button></form>
</div>
</div>
@empty
<div class="card" style="padding: 40px; text-align: center; grid-column: 1/-1;"><div style="font-size: 48px;">👥</div><h3>No buddies yet</h3><p style="color: var(--text-muted);">Add up to 25 game buddies - Facebook friends - Challenge button - Team Up mode</p></div>
@endforelse
</div>
</div>
@endsection
