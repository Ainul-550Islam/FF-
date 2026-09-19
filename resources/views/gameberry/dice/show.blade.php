@extends('layouts.app')

@section('title', $dice->name . ' - Dice Detail')

@section('content')
<div class="container" style="max-width: 800px; margin: 0 auto; padding: 24px;">
    <div style="display: flex; gap: 24px; flex-wrap: wrap;">
        <div class="card" style="flex: 1; min-width: 300px; padding: 24px; text-align: center;">
            <div style="font-size: 80px;">🎲</div>
            <h1 style="font-size: 24px; font-weight: 800; margin: 12px 0 4px;">{{ $dice->name }}</h1>
            <div style="font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px;">{{ $dice->rarity }} | {{ $dice->is_lucky ? 'Lucky Dice' : 'Regular' }} | {{ $dice->is_collectible ? 'Collectible' : '' }}</div>
            <div style="margin: 16px 0; background: var(--bg-secondary); padding: 12px; border-radius: 8px; font-size: 13px;">{{ $dice->description }}</div>
            <div style="display: flex; gap: 8px; justify-content: center; margin-top: 16px;">
                <span style="background: var(--bg-secondary); padding: 6px 12px; border-radius: 999px; font-size: 12px;">Max: {{ $dice->max_collection }} per type</span>
                <span style="background: var(--bg-secondary); padding: 6px 12px; border-radius: 999px; font-size: 12px;">{{ $dice->is_active ? 'Active' : 'Inactive' }}</span>
            </div>
        </div>

        <div class="card" style="flex: 1; min-width: 300px; padding: 20px;">
            <h3>Your Collection</h3>
            @if($userDice)
            <div style="background: var(--bg-secondary); padding: 16px; border-radius: 12px; margin-top: 12px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                    <span>Quantity:</span>
                    <strong>{{ $userDice->quantity }}/52 {{ $userDice->quantity >= 52 ? '(Max reached)' : '(Can collect more)' }}</strong>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                    <span>Equipped:</span>
                    <strong>{{ $userDice->is_equipped ? '✅ Yes' : 'No' }}</strong>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 16px;">
                    <span>Favorite:</span>
                    <strong>{{ $userDice->is_favorite ? '❤️ Yes' : 'No' }}</strong>
                </div>

                <div style="display: flex; gap: 8px;">
                    <form method="POST" action="{{ route('gameberry.dice.equip', $dice->id) }}" style="flex: 1;">
                        @csrf
                        <button type="submit" class="btn btn-sm {{ $userDice->is_equipped ? 'btn-primary' : 'btn-secondary' }}" style="width: 100%;">{{ $userDice->is_equipped ? 'Equipped' : 'Equip Dice' }}</button>
                    </form>
                    <form method="POST" action="{{ route('gameberry.dice.favorite', $dice->id) }}" style="flex: 1;">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-ghost" style="width: 100%;">{{ $userDice->is_favorite ? '❤️ Unfavorite' : '🤍 Favorite' }}</button>
                    </form>
                </div>

                @if($userDice->quantity >= 2)
                <div style="margin-top: 16px;">
                    <h4 style="font-size: 13px; margin: 0 0 8px;">Exchange (Facebook Only - Need 2+ to exchange)</h4>
                    <form method="POST" action="{{ route('gameberry.dice.exchange') }}" style="display: flex; gap: 8px;">
                        @csrf
                        <input type="hidden" name="dice_id" value="{{ $dice->id }}">
                        <input type="number" name="receiver_id" placeholder="Friend User ID" required style="flex: 1; padding: 6px 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg-tertiary);">
                        <button type="submit" class="btn btn-sm btn-secondary">Exchange 🔄</button>
                    </form>
                </div>
                @endif
            </div>
            @else
            <div style="background: var(--bg-secondary); padding: 16px; border-radius: 8px; margin-top: 12px; text-align: center;">
                <p style="color: var(--text-muted); font-size: 13px;">You don't own this dice yet - Play games, open magic chests, spin2win, video ads to collect 250+ dice</p>
            </div>
            @endif

            <div style="margin-top: 20px; background: var(--bg-secondary); padding: 12px; border-radius: 8px; font-size: 12px;">
                <strong>Dice Info:</strong><br>
                • Rarity: {{ $dice->rarity }}<br>
                • Lucky: {{ $dice->is_lucky ? 'Yes - Part of 52 lucky dice' : 'No' }}<br>
                • Max collection: 52 per type<br>
                • Exchange: Facebook-only, need 2+ to give 1<br>
                • Image: {{ $dice->image_url }}
            </div>
        </div>
    </div>

    <div style="margin-top: 20px; text-align: center;">
        <a href="{{ route('gameberry.dice.index') }}" class="btn btn-secondary">Back to Collection</a>
        <a href="{{ route('gameberry.dice.collection') }}" class="btn btn-ghost">My Collection</a>
    </div>
</div>
@endsection
