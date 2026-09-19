@extends('layouts.app')

@section('title', 'Favorite Dices')

@section('content')
<div class="container" style="max-width: 1000px; margin: 0 auto; padding: 24px;">
    <h1 style="font-size: 28px; font-weight: 800; margin: 0 0 8px;">❤️ Favorite Dices</h1>
    <p style="color: var(--text-muted); margin: 0 0 24px;">Your favorite dices from 250+ collection - Max 52 per type</p>

    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px;">
        @forelse($favorites as $ud)
        <div class="card" style="padding: 16px; text-align: center;">
            <div style="font-size: 40px;">🎲</div>
            <div style="font-weight: 700; margin: 8px 0 4px;">{{ $ud->dice->name }}</div>
            <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">{{ $ud->dice->rarity }}</div>
            <div style="font-size: 12px; margin-top: 4px;">Qty: {{ $ud->quantity }}/52</div>
            <div style="display: flex; gap: 4px; margin-top: 8px;">
                <form method="POST" action="{{ route('gameberry.dice.equip', $ud->dice_id) }}" style="flex: 1;">
                    @csrf
                    <button type="submit" class="btn btn-sm {{ $ud->is_equipped ? 'btn-primary' : 'btn-secondary' }}" style="width: 100%;">{{ $ud->is_equipped ? 'Equipped' : 'Equip' }}</button>
                </form>
                <form method="POST" action="{{ route('gameberry.dice.favorite', $ud->dice_id) }}" style="flex: 1;">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-ghost" style="width: 100%;">❤️ Unfav</button>
                </form>
            </div>
        </div>
        @empty
        <div class="card" style="padding: 40px; text-align: center; grid-column: 1/-1;">
            <div style="font-size: 48px;">🤍</div>
            <h3>No favorites yet</h3>
            <p style="color: var(--text-muted);">Add dices to favorites from your collection - 250+ dices max 52 per type</p>
            <a href="{{ route('gameberry.dice.collection') }}" class="btn btn-primary" style="margin-top: 12px;">My Collection</a>
        </div>
        @endforelse
    </div>
</div>
@endsection
