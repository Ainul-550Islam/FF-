@extends('layouts.app')

@section('title', 'Dice Exchanges - Facebook Only')

@section('content')
<div class="container" style="max-width: 1000px; margin: 0 auto; padding: 24px;">
    <h1 style="font-size: 28px; font-weight: 800; margin: 0 0 8px;">🔄 Dice Exchanges - Facebook Only</h1>
    <p style="color: var(--text-muted); margin: 0 0 24px;">Dice exchange Facebook-only - Need at least 2 of dice to exchange - Max 52 collection</p>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
        <div class="card">
            <div style="padding: 20px;">
                <h3>Sent Exchanges ({{ $sent->count() }})</h3>
                @forelse($sent as $ex)
                <div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <div style="font-weight: 600; font-size: 13px;">To: {{ $ex->receiver->name ?? 'User '.$ex->receiver_id }} - {{ $ex->dice->name ?? 'Dice #'.$ex->dice_id }}</div>
                        <div style="font-size: 11px; color: var(--text-muted);">{{ $ex->status }} | Facebook only: {{ $ex->is_facebook_only ? 'Yes' : 'No' }} | Expires {{ $ex->expires_at->diffForHumans() }}</div>
                    </div>
                    <span class="badge" style="background: {{ $ex->status === 'pending' ? '#f39c12' : ($ex->status === 'accepted' ? '#27ae60' : '#e74c3c') }}; color: white;">{{ $ex->status }}</span>
                </div>
                @empty
                <p style="color: var(--text-muted); font-size: 13px;">No sent exchanges - Exchange dice with Facebook friends, need at least 2 of same dice</p>
                @endforelse
            </div>
        </div>

        <div class="card">
            <div style="padding: 20px;">
                <h3>Received Exchanges ({{ $received->count() }})</h3>
                @forelse($received as $ex)
                <div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px; margin-bottom: 8px;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <div style="font-weight: 600; font-size: 13px;">From: {{ $ex->sender->name ?? 'User '.$ex->sender_id }} - {{ $ex->dice->name ?? 'Dice #'.$ex->dice_id }}</div>
                            <div style="font-size: 11px; color: var(--text-muted);">{{ $ex->status }} | Facebook only | {{ $ex->created_at->diffForHumans() }}</div>
                        </div>
                        @if($ex->status === 'pending' && $ex->expires_at->isFuture())
                        <div style="display: flex; gap: 4px;">
                            <form method="POST" action="{{ route('gameberry.dice.exchange.accept', $ex->id) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-primary">Accept</button>
                            </form>
                            <form method="POST" action="{{ route('gameberry.dice.exchange.deny', $ex->id) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-ghost">Deny</button>
                            </form>
                        </div>
                        @else
                        <span class="badge">{{ $ex->status }}</span>
                        @endif
                    </div>
                </div>
                @empty
                <p style="color: var(--text-muted); font-size: 13px;">No received exchanges</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="card" style="margin-top: 20px; padding: 16px;">
        <h4 style="margin: 0 0 8px;">How Dice Exchange Works (Gameberry FAQ)</h4>
        <ul style="margin: 0; padding-left: 18px; font-size: 13px; color: var(--text-muted);">
            <li>✅ Facebook-only - Must be Facebook friends to exchange</li>
            <li>✅ Need at least 2 of same dice to exchange one</li>
            <li>✅ Max 52 per dice type collection</li>
            <li>✅ 250+ dice types available</li>
            <li>✅ Receiver max 52 lucky dice check</li>
            <li>✅ Exchange expires in 7 days</li>
        </ul>
    </div>
</div>
@endsection
