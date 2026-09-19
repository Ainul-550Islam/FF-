@extends('layouts.app')

@section('title', 'Magic Chests - All Chests')

@section('content')
<div class="container" style="max-width: 1000px; margin: 0 auto; padding: 24px;">
    <h1 style="font-size: 28px; font-weight: 800; margin: 0 0 8px;">🎁 Magic Chests History</h1>
    <p style="color: var(--text-muted); margin: 0 0 24px;">Bronze Silver Gold Magic - Gold gems dice rewards</p>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
        <div class="card">
            <div style="padding: 20px;">
                <h3>Available to Open ({{ $available->count() }})</h3>
                @forelse($available as $chest)
                <div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <div style="font-weight: 700;">{{ ucfirst($chest->type) }} Chest - {{ $chest->gold_reward }} gold, {{ $chest->gem_reward }} gems</div>
                        <div style="font-size: 11px; color: var(--text-muted);">Expires {{ $chest->expires_at->diffForHumans() }} @if(!empty($chest->dice_rewards))+{{ count($chest->dice_rewards) }} dice @endif</div>
                    </div>
                    <form method="POST" action="{{ route('gameberry.economy.open_chest', $chest->id) }}">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-primary">Open</button>
                    </form>
                </div>
                @empty
                <p style="color: var(--text-muted); font-size: 13px;">No available chests</p>
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
                            @foreach($chests->take(30) as $chest)
                            <tr>
                                <td>{{ ucfirst($chest->type) }}</td>
                                <td>{{ $chest->gold_reward }}</td>
                                <td>{{ $chest->gem_reward }}</td>
                                <td>{{ $chest->status }}</td>
                                <td style="font-size: 11px;">{{ $chest->created_at->diffForHumans() }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="card" style="margin-top: 20px; padding: 20px; text-align: center;">
        <h3>Get New Chest</h3>
        <div style="display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; margin-top: 12px;">
            @foreach(['bronze','silver','gold','magic'] as $type)
            <form method="POST" action="{{ route('gameberry.economy.get_chest') }}">
                @csrf
                <input type="hidden" name="type" value="{{ $type }}">
                <button type="submit" class="btn btn-sm btn-secondary" {{ !$canGet ? 'disabled' : '' }}>Get {{ ucfirst($type) }} Chest</button>
            </form>
            @endforeach
        </div>
        @if(!$canGet && $nextTime)
        <div style="margin-top: 8px; font-size: 12px; color: var(--text-muted);">Next available: {{ $nextTime->diffForHumans() }}</div>
        @endif
    </div>
</div>
@endsection
