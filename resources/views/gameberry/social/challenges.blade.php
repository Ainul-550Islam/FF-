@extends('layouts.app')

@section('title', 'Challenges - Challenge Button')

@section('content')
<div class="container" style="max-width: 1000px; margin: 0 auto; padding: 24px;">
    <h1 style="font-size: 28px; font-weight: 800; margin: 0 0 8px;">🎯 Challenges - Challenge Button</h1>
    <p style="color: var(--text-muted); margin: 0 0 24px;">Challenge friends via challenge button - Private table & buddy challenges</p>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
        <div class="card">
            <div style="padding: 20px;">
                <h3>Sent Challenges ({{ $sent->count() }})</h3>
                @forelse($sent as $ch)
                <div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <div style="font-weight: 600; font-size: 13px;">To: {{ $ch->challenged->name ?? 'User '.$ch->challenged_id }} - {{ $ch->type }}</div>
                        <div style="font-size: 11px; color: var(--text-muted);">Bet: {{ $ch->bet_amount_minor }} gold | {{ $ch->status }} | Expires {{ $ch->expires_at->diffForHumans() }}</div>
                    </div>
                    <span class="badge" style="background: {{ $ch->status === 'pending' ? '#f39c12' : ($ch->status === 'accepted' ? '#27ae60' : '#e74c3c') }}; color: white;">{{ $ch->status }}</span>
                </div>
                @empty
                <p style="color: var(--text-muted); font-size: 13px;">No sent challenges - Use challenge button on buddies</p>
                @endforelse
            </div>
        </div>

        <div class="card">
            <div style="padding: 20px;">
                <h3>Received Challenges ({{ $received->count() }})</h3>
                @forelse($received as $ch)
                <div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px; margin-bottom: 8px;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <div style="font-weight: 600; font-size: 13px;">From: {{ $ch->challenger->name ?? 'User '.$ch->challenger_id }} - {{ $ch->type }}</div>
                            <div style="font-size: 11px; color: var(--text-muted);">Bet: {{ $ch->bet_amount_minor }} gold | {{ $ch->status }} | {{ $ch->created_at->diffForHumans() }}</div>
                        </div>
                        @if($ch->status === 'pending' && $ch->expires_at->isFuture())
                        <div style="display: flex; gap: 4px;">
                            <form method="POST" action="{{ route('gameberry.social.challenge.accept', $ch->id) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-primary">Accept</button>
                            </form>
                            <form method="POST" action="{{ route('gameberry.social.challenge.deny', $ch->id) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-ghost">Deny</button>
                            </form>
                        </div>
                        @else
                        <span class="badge" style="background: var(--bg-tertiary);">{{ $ch->status }}</span>
                        @endif
                    </div>
                </div>
                @empty
                <p style="color: var(--text-muted); font-size: 13px;">No received challenges</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
