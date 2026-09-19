@extends('layouts.app')
@section('title', 'Available Magic Chests')
@section('content')
<div class="container" style="max-width: 800px; margin: 0 auto; padding: 24px;">
<h1 style="font-size: 28px; font-weight: 800;">🎁 Available Magic Chests</h1>
<p style="color: var(--text-muted);">Bronze Silver Gold Magic chests - Open for gold gems dice</p>
<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 16px; margin-top: 20px;">
@forelse($available as $chest)
<div class="card" style="padding: 16px; border-left: 4px solid {{ $chest->type==='bronze' ? '#CD7F32' : ($chest->type==='silver' ? '#C0C0C0' : ($chest->type==='gold' ? '#FFD700' : '#9b59b6')) }};">
<div style="font-weight: 800;">{{ ucfirst($chest->type) }} Chest</div>
<div style="font-size: 12px; color: var(--text-muted);">{{ $chest->gold_reward }} gold, {{ $chest->gem_reward }} gems @if(!empty($chest->dice_rewards))+{{ count($chest->dice_rewards) }} dice @endif</div>
<div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">Expires {{ $chest->expires_at->diffForHumans() }}</div>
<form method="POST" action="{{ route('gameberry.chests.open', $chest->id) }}" style="margin-top: 12px;">@csrf<button type="submit" class="btn btn-sm btn-primary" style="width: 100%;">Open 🎁</button></form>
</div>
@empty
<p style="color: var(--text-muted);">No available chests - Win games to get magic chest</p>
@endforelse
</div>
</div>
@endsection
