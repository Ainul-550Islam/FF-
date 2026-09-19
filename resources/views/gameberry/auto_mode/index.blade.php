@extends('layouts.app')
@section('title', 'Auto Mode - On Disconnect')
@section('content')
<div class="container" style="max-width: 800px; margin: 0 auto; padding: 24px;">
<h1 style="font-size: 28px; font-weight: 800;">🤖 Auto Mode - On Disconnect</h1>
<p style="color: var(--text-muted);">Gameberry FAQ: Auto mode on disconnect - Auto-play if you disconnect during game</p>
<div class="card" style="padding: 20px; margin-top: 20px; text-align: center;">
<div style="font-size: 64px;">🤖</div>
<h2>{{ $isAuto ? 'Auto Mode ON - Will auto-play on disconnect' : 'Auto Mode OFF' }}</h2>
<div style="display: flex; gap: 12px; justify-content: center; margin-top: 16px;">
@if(!$isAuto)
<form method="POST" action="{{ route('gameberry.auto_mode.enable') }}">@csrf<input type="hidden" name="reason" value="disconnect"><button type="submit" class="btn btn-primary">Enable Auto Mode 🤖</button></form>
@else
<form method="POST" action="{{ route('gameberry.auto_mode.disable') }}">@csrf<button type="submit" class="btn btn-ghost">Disable Auto Mode</button></form>
@endif
</div>
</div>
<div class="card" style="padding: 20px; margin-top: 20px;">
<h3>Auto Mode Logs ({{ $logs->count() }})</h3>
<div class="table-wrap"><table class="table"><thead><tr><th>Date</th><th>Table</th><th>Reason</th><th>Auto ON</th><th>Duration</th></tr></thead><tbody>
@forelse($logs as $log)<tr><td style="font-size: 11px;">{{ $log->created_at->diffForHumans() }}</td><td>{{ $log->private_table_id ? 'Table #'.$log->private_table_id : '-' }}</td><td>{{ $log->reason }}</td><td>{{ $log->is_auto_on ? '🤖 ON' : 'OFF' }}</td><td style="font-size: 11px;">{{ $log->durationSeconds() ? $log->durationSeconds().'s' : '-' }}</td></tr>@empty<tr><td colspan="5" style="text-align: center; color: var(--text-muted);">No auto mode logs</td></tr>@endforelse
</tbody></table></div>
</div>
</div>
@endsection
