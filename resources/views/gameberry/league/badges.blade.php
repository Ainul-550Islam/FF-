@extends('layouts.app')

@section('title', 'Titan Badges - Weekly Rewards')

@section('content')
<div class="container" style="max-width: 1000px; margin: 0 auto; padding: 24px;">
    <h1 style="font-size: 28px; font-weight: 800; margin: 0 0 8px;">👑 Titan Badges Collection</h1>
    <p style="color: var(--text-muted); margin: 0 0 24px;">Weekly Titan badges - Awarded for staying in Titan league - Top 20% promotion Top 40 stay rule - Level 12 required</p>

    @if($badges->isEmpty())
    <div class="card" style="padding: 40px; text-align: center;">
        <div style="font-size: 64px;">👑</div>
        <h3>No Titan Badges Yet</h3>
        <p style="color: var(--text-muted);">Reach Titan league (Level 12, 5000+ trophies) and stay in top 20% each week to earn Titan badges weekly</p>
        <div style="background: var(--bg-secondary); padding: 16px; border-radius: 8px; margin-top: 16px; text-align: left; font-size: 13px;">
            <strong>How Titan Badges Work (Gameberry FAQ):</strong>
            <ul style="margin: 8px 0 0; padding-left: 18px;">
                <li>6-step league: Bronze → Silver → Gold → Platinum → Diamond → Titan</li>
                <li>Titan is highest league - Level 12 required</li>
                <li>Top 20% promotion each season, Bottom 40% demotion</li>
                <li>In Titan, Top 40 stay (special rule)</li>
                <li>Weekly Titan badges awarded for maintaining Titan</li>
                <li>Badges show week/year and rank</li>
            </ul>
        </div>
        <a href="{{ route('gameberry.league.index') }}" class="btn btn-primary" style="margin-top: 16px;">View Leagues</a>
    </div>
    @else
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px;">
        @foreach($badges as $badge)
        <div style="background: linear-gradient(135deg, #FFD700 0%, #FFA500 50%, #FF8C00 100%); color: black; padding: 20px; border-radius: 16px; text-align: center; box-shadow: 0 4px 12px rgba(255,215,0,0.4);">
            <div style="font-size: 48px;">👑</div>
            <div style="font-weight: 900; font-size: 18px; margin: 8px 0;">TITAN BADGE</div>
            <div style="font-size: 13px; font-weight: 700;">Week {{ $badge->week }}, {{ $badge->year }}</div>
            <div style="font-size: 12px; margin-top: 4px;">Rank #{{ $badge->rank }} | Season {{ $badge->season }}</div>
            <div style="font-size: 10px; margin-top: 8px; background: rgba(0,0,0,0.2); padding: 4px 8px; border-radius: 999px; display: inline-block;">{{ $badge->badge_type }}</div>
            <div style="font-size: 10px; margin-top: 8px; opacity: 0.7;">{{ $badge->created_at->format('Y-m-d') }}</div>
        </div>
        @endforeach
    </div>

    <div class="card" style="margin-top: 24px; padding: 20px; text-align: center;">
        <h3>Total Titan Badges: {{ $badges->count() }} 👑</h3>
        <p style="color: var(--text-muted); font-size: 13px;">Keep playing in Titan league to earn more weekly badges - Top 40 stay rule</p>
    </div>
    @endif
</div>
@endsection
