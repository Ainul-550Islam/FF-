@extends('layouts.app')

@section('title', 'Magic Chests - Bronze Silver Gold Magic')

@section('content')
<div class="container" style="max-width: 1000px; margin: 0 auto; padding: 24px;">
    <h1 style="font-size: 28px; font-weight: 800; margin: 0 0 8px;">🎁 Magic Chests</h1>
    <p style="color: var(--text-muted); margin: 0 0 24px;">Bronze Silver Gold Magic chests - Gold gems dice rewards - 4 hours cooldown</p>

    <div class="card" style="margin-bottom: 24px; padding: 20px; text-align: center;">
        <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
            <form method="POST" action="{{ route('gameberry.chests.create') }}">
                @csrf
                <input type="hidden" name="type" value="bronze">
                <button type="submit" class="btn btn-secondary" {{ !$canGet ? 'disabled' : '' }}>Get Bronze Chest (50-200 gold)</button>
            </form>
            <form method="POST" action="{{ route('gameberry.chests.create') }}">
                @csrf
                <input type="hidden" name="type" value="silver">
                <button type="submit" class="btn btn-secondary" {{ !$canGet ? 'disabled' : '' }}>Get Silver Chest (200-500 gold)</button>
            </form>
            <form method="POST" action="{{ route('gameberry.chests.create') }}">
                @csrf
                <input type="hidden" name="type" value="gold">
                <button type="submit" class="btn btn-primary" {{ !$canGet ? 'disabled' : '' }}>Get Gold Chest (500-1500 gold)</button>
            </form>
            <form method="POST" action="{{ route('gameberry.chests.create') }}">
                @csrf
                <input type="hidden" name="type" value="magic">
                <button type="submit" class="btn btn-primary" {{ !$canGet ? 'disabled' : '' }}>Get Magic Chest (1000-5000 gold)</button>
            </form>
        </div>
        @if(!$canGet && $nextTime)
        <div style="margin-top: 12px; font-size: 13px; color: var(--text-muted);">Next chest available: {{ $nextTime->diffForHumans() }} ({{ $nextTime->format('H:i') }}) - Cooldown 4 hours</div>
        @endif
    </div>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
        <div class="card">
            <div style="padding: 20px;">
                <h3>Available Chests ({{ $available->count() }})</h3>
                @forelse($available as $chest)
                <div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center; border-left: 4px solid {{ $chest->type === 'bronze' ? '#CD7F32' : ($chest->type === 'silver' ? '#C0C0C0' : ($chest->type === 'gold' ? '#FFD700' : '#9b59b6')) }};">
                    <div>
                        <div style="font-weight: 700;">{{ ucfirst($chest->type) }} Chest</div>
                        <div style="font-size: 12px; color: var(--text-muted);">{{ $chest->gold_reward }} gold, {{ $chest->gem_reward }} gems @if(!empty($chest->dice_rewards))+{{ count($chest->dice_rewards) }} dice @endif</div>
                        <div style="font-size: 11px; color: var(--text-muted);">Expires {{ $chest->expires_at->diffForHumans() }}</div>
                    </div>
                    <form method="POST" action="{{ route('gameberry.chests.open', $chest->id) }}">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-primary">Open 🎁</button>
                    </form>
                </div>
                @empty
                <p style="color: var(--text-muted); font-size: 13px;">No available chests - Win games to earn magic chests</p>
                @endforelse
            </div>
        </div>

        <div class="card">
            <div style="padding: 20px;">
                <h3>All Chests ({{ $chests->count() }})</h3>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Type</th><th>Gold</th><th>Gems</th><th>Status</th><th>Date</th></tr></thead>
                        <tbody>
                            @forelse($chests->take(20) as $chest)
                            <tr>
                                <td>{{ ucfirst($chest->type) }}</td>
                                <td>{{ $chest->gold_reward }}</td>
                                <td>{{ $chest->gem_reward }}</td>
                                <td><span class="badge" style="background: {{ $chest->status === 'available' ? '#27ae60' : '#95a5a6' }}; color: white;">{{ $chest->status }}</span></td>
                                <td style="font-size: 11px;">{{ $chest->created_at->diffForHumans() }}</td>
                            </tr>
                            @empty
                            <tr><td colspan="5" style="text-align: center; color: var(--text-muted);">No chests yet</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
