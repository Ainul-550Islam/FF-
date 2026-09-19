@extends('layouts.app')
@section('title', 'Team Up Mode - 2v2')
@section('content')
<div class="container" style="max-width: 1000px; margin: 0 auto; padding: 24px;">
<h1 style="font-size: 28px; font-weight: 800;">👥 Team Up Mode - 2v2 - Table {{ $table->code }}</h1>
<p style="color: var(--text-muted);">Team Up mode - Play with partner as team - Gameberry LudoStar Team Up 4 Player</p>
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
<div class="card" style="padding: 20px; border: 2px solid #3498db;">
<h3 style="margin: 0 0 12px; color: #3498db;">Team A ({{ $status['team_a_count'] }}/{{ $status['max_per_team'] }})</h3>
@forelse($status['team_a_members'] as $member)
<div style="background: var(--bg-secondary); padding: 10px; border-radius: 8px; margin-bottom: 8px; display: flex; justify-content: space-between;">
<span>{{ $member->user->name ?? 'User '.$member->user_id }} @if($member->role==='host')👑@endif</span>
<span>{{ $member->is_ready ? '✅ Ready' : '⏳ Waiting' }}</span>
</div>
@empty
<p style="color: var(--text-muted); font-size: 13px;">No members in Team A</p>
@endforelse
</div>
<div class="card" style="padding: 20px; border: 2px solid #e74c3c;">
<h3 style="margin: 0 0 12px; color: #e74c3c;">Team B ({{ $status['team_b_count'] }}/{{ $status['max_per_team'] }})</h3>
@forelse($status['team_b_members'] as $member)
<div style="background: var(--bg-secondary); padding: 10px; border-radius: 8px; margin-bottom: 8px; display: flex; justify-content: space-between;">
<span>{{ $member->user->name ?? 'User '.$member->user_id }} @if($member->role==='host')👑@endif</span>
<span>{{ $member->is_ready ? '✅ Ready' : '⏳ Waiting' }}</span>
</div>
@empty
<p style="color: var(--text-muted); font-size: 13px;">No members in Team B</p>
@endforelse
</div>
</div>
<div class="card" style="padding: 16px; margin-top: 20px; text-align: center;">
<div style="font-size: 13px;">Can Start: {{ $status['can_start'] ? '✅ Yes' : '❌ No' }} | Is Team Up: {{ $status['is_team_up'] ? 'Yes 2v2' : 'No' }}</div>
<a href="{{ route('gameberry.private_tables.show', $table->code) }}" class="btn btn-secondary" style="margin-top: 12px;">Back to Table</a>
</div>
</div>
@endsection
