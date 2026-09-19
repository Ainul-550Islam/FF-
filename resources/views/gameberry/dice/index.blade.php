@extends('layouts.app')

@section('title', 'Dice Collection - 250+ Dices - LudoStar Style')

@section('content')
<div class="container" style="max-width: 1280px; margin: 0 auto; padding: 24px;">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
        <div>
            <h1 style="font-size: 28px; font-weight: 800; margin: 0;">🎲 Dice Collection</h1>
            <p style="color: var(--text-muted); margin: 4px 0 0;">250+ collectible dices - Max 52 per type - Facebook exchange</p>
        </div>
        <div style="display: flex; gap: 12px;">
            <a href="{{ route('gameberry.dice.collection') }}" class="btn btn-secondary">My Collection</a>
            <a href="{{ route('gameberry.dice.lucky') }}" class="btn btn-primary">Lucky Dice ({{ $collection['total_quantity'] }})</a>
        </div>
    </div>

    <div class="card" style="margin-bottom: 24px;">
        <div style="padding: 20px;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                <div style="background: var(--bg-secondary); padding: 16px; border-radius: 12px; text-align: center;">
                    <div style="font-size: 32px; font-weight: 800;">{{ $collection['owned_types'] }}/{{ $collection['total_dice_types'] }}</div>
                    <div style="color: var(--text-muted); font-size: 13px;">Owned Types</div>
                    <div style="margin-top: 8px; background: var(--bg-tertiary); height: 8px; border-radius: 999px; overflow: hidden;">
                        <div style="height: 100%; background: var(--primary); width: {{ $collection['completion_percent'] }}%;"></div>
                    </div>
                    <div style="font-size: 12px; margin-top: 4px;">{{ $collection['completion_percent'] }}% Complete</div>
                </div>
                <div style="background: var(--bg-secondary); padding: 16px; border-radius: 12px; text-align: center;">
                    <div style="font-size: 24px;">Common: {{ $rarityCounts['common'] ?? 0 }}</div>
                    <div style="font-size: 24px; color: #3498db;">Rare: {{ $rarityCounts['rare'] ?? 0 }}</div>
                    <div style="font-size: 24px; color: #9b59b6;">Epic: {{ $rarityCounts['epic'] ?? 0 }}</div>
                    <div style="font-size: 24px; color: #f1c40f;">Legendary: {{ $rarityCounts['legendary'] ?? 0 }}</div>
                </div>
                <div style="background: var(--bg-secondary); padding: 16px; border-radius: 12px;">
                    <h4 style="margin: 0 0 12px;">Gameberry Features</h4>
                    <ul style="margin: 0; padding-left: 18px; font-size: 13px; color: var(--text-muted);">
                        <li>250+ dice collection</li>
                        <li>Max 52 per dice type</li>
                        <li>Lucky dice gem rewards</li>
                        <li>Facebook-only exchange</li>
                        <li>Equip & Favorite</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px;">
        @foreach($collection['dices'] as $dice)
        <div class="card" style="overflow: hidden;">
            <div style="padding: 16px; text-align: center;">
                <div style="font-size: 48px; margin-bottom: 8px;">🎲</div>
                <h3 style="margin: 0; font-size: 16px;">{{ $dice['name'] }}</h3>
                <div style="font-size: 12px; color: var(--text-muted); text-transform: uppercase; margin: 4px 0;">{{ $dice['rarity'] }}</div>
                <div style="display: flex; justify-content: center; gap: 8px; margin: 8px 0;">
                    <span style="background: var(--bg-secondary); padding: 4px 8px; border-radius: 6px; font-size: 12px;">Qty: {{ $dice['quantity'] }}/52</span>
                    @if($dice['is_equipped'])<span style="background: #27ae60; color: white; padding: 4px 8px; border-radius: 6px; font-size: 12px;">Equipped</span>@endif
                    @if($dice['is_favorite'])<span style="background: #e74c3c; color: white; padding: 4px 8px; border-radius: 6px; font-size: 12px;">❤️ Fav</span>@endif
                </div>
                <div style="display: flex; gap: 8px; margin-top: 12px;">
                    <form method="POST" action="{{ route('gameberry.dice.equip', $dice['id']) }}" style="flex: 1;">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-secondary" style="width: 100%;">Equip</button>
                    </form>
                    <form method="POST" action="{{ route('gameberry.dice.favorite', $dice['id']) }}" style="flex: 1;">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-ghost" style="width: 100%;">Fav</button>
                    </form>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    <div class="card" style="margin-top: 32px;">
        <div style="padding: 20px;">
            <h3>Available Dices (250+)</h3>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>Name</th><th>Rarity</th><th>Lucky</th><th>Collectible</th></tr>
                    </thead>
                    <tbody>
                        @foreach($availableDices->take(50) as $dice)
                        <tr>
                            <td>{{ $dice->name }}</td>
                            <td><span class="badge">{{ $dice->rarity }}</span></td>
                            <td>{{ $dice->is_lucky ? 'Yes' : 'No' }}</td>
                            <td>{{ $dice->is_collectible ? 'Yes' : 'No' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p style="color: var(--text-muted); font-size: 13px; margin-top: 12px;">Showing 50 of {{ $availableDices->count() }} total - 250+ collection like LudoStar</p>
        </div>
    </div>
</div>
@endsection
