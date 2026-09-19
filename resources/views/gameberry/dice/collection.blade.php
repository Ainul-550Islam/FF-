@extends('layouts.app')

@section('title', 'My Dice Collection - 52 Max - Facebook Exchange')

@section('content')
<div class="container" style="max-width: 1280px; margin: 0 auto; padding: 24px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <div>
            <h1 style="font-size: 28px; font-weight: 800; margin: 0;">My Dice Collection</h1>
            <p style="color: var(--text-muted); margin: 4px 0 0;">{{ $collection['owned_types'] }}/{{ $collection['total_dice_types'] }} types | {{ $collection['completion_percent'] }}% complete | Max 52 per type</p>
        </div>
        <a href="{{ route('gameberry.dice.index') }}" class="btn btn-secondary">Browse All Dices</a>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px;">
        @foreach($collection['dices'] as $dice)
        <div class="card" style="padding: 16px; text-align: center;">
            <div style="font-size: 40px;">🎲</div>
            <div style="font-weight: 700; margin: 8px 0 4px;">{{ $dice['name'] }}</div>
            <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">{{ $dice['rarity'] }}</div>
            <div style="margin: 8px 0; background: var(--bg-secondary); padding: 4px 8px; border-radius: 999px; font-size: 12px; display: inline-block;">{{ $dice['quantity'] }}/52 {{ $dice['can_collect_more'] ? 'Can collect more' : 'Max reached' }}</div>
            <div style="display: flex; gap: 4px; margin-top: 8px;">
                <form method="POST" action="{{ route('gameberry.dice.equip', $dice['id']) }}" style="flex: 1;">
                    @csrf
                    <button type="submit" class="btn btn-sm {{ $dice['is_equipped'] ? 'btn-primary' : 'btn-secondary' }}" style="width: 100%; font-size: 11px;">{{ $dice['is_equipped'] ? 'Equipped' : 'Equip' }}</button>
                </form>
                <form method="POST" action="{{ route('gameberry.dice.favorite', $dice['id']) }}" style="flex: 1;">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-ghost" style="width: 100%; font-size: 11px;">{{ $dice['is_favorite'] ? '❤️' : '🤍' }} Fav</button>
                </form>
            </div>
        </div>
        @endforeach
    </div>

    @if($collection['dices']->isEmpty())
    <div class="card" style="padding: 40px; text-align: center; margin-top: 24px;">
        <div style="font-size: 48px;">🎲</div>
        <h3>No dices yet</h3>
        <p style="color: var(--text-muted);">Play games, open magic chests, spin2win to collect 250+ dices - Max 52 per type - Facebook exchange available</p>
    </div>
    @endif
</div>
@endsection
