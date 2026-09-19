@extends('layouts.app')

@section('title', 'Available Dices - 250+ Collection')

@section('content')
<div class="container" style="max-width: 1280px; margin: 0 auto; padding: 24px;">
    <h1 style="font-size: 28px; font-weight: 800; margin: 0 0 8px;">🎲 Available Dices - 250+ Collection</h1>
    <p style="color: var(--text-muted); margin: 0 0 24px;">Browse 250+ collectible dices - Max 52 per type - Lucky dice 52 - Facebook exchange - Rarities common rare epic legendary</p>

    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px;">
        @foreach($dices as $dice)
        <div class="card" style="padding: 16px; text-align: center; border-left: 4px solid {{ $dice->rarity === 'common' ? '#95a5a6' : ($dice->rarity === 'rare' ? '#3498db' : ($dice->rarity === 'epic' ? '#9b59b6' : '#f1c40f')) }};">
            <div style="font-size: 40px;">🎲</div>
            <div style="font-weight: 700; font-size: 14px; margin: 8px 0 4px;">{{ $dice->name }}</div>
            <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">{{ $dice->rarity }} @if($dice->is_lucky)🍀 Lucky @endif</div>
            <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">{{ $dice->description }}</div>
            <div style="margin-top: 8px; display: flex; gap: 4px; justify-content: center;">
                <span style="background: var(--bg-secondary); padding: 2px 6px; border-radius: 999px; font-size: 10px;">Max {{ $dice->max_collection }}</span>
                <span style="background: var(--bg-secondary); padding: 2px 6px; border-radius: 999px; font-size: 10px;">{{ $dice->is_collectible ? 'Collectible' : '' }}</span>
            </div>
            <a href="{{ route('gameberry.dice.show', $dice->id) }}" class="btn btn-sm btn-secondary" style="margin-top: 8px; width: 100%;">View</a>
        </div>
        @endforeach
    </div>

    <div style="margin-top: 24px; text-align: center;">
        <div style="font-size: 13px; color: var(--text-muted);">Showing {{ $dices->count() }} of 250+ total dices - Max 52 per type - Facebook-only exchange need 2 to give 1</div>
    </div>
</div>
@endsection
