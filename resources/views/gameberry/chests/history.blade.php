@extends('layouts.app')
@section('title', 'Magic Chest History')
@section('content')
<div class="container" style="max-width: 800px; margin: 0 auto; padding: 24px;">
<h1 style="font-size: 28px; font-weight: 800;">🎁 Magic Chest History</h1>
<div class="card" style="padding: 20px; margin-top: 20px;">
<div class="table-wrap"><table class="table"><thead><tr><th>Type</th><th>Gold</th><th>Gems</th><th>Dice</th><th>Status</th><th>Date</th></tr></thead><tbody>
@forelse($chests as $chest)<tr><td>{{ ucfirst($chest->type) }}</td><td>{{ $chest->gold_reward }}</td><td>{{ $chest->gem_reward }}</td><td>{{ !empty($chest->dice_rewards) ? count($chest->dice_rewards) : '-' }}</td><td><span class="badge" style="background: {{ $chest->status==='available' ? '#27ae60' : '#95a5a6' }}; color: white;">{{ $chest->status }}</span></td><td style="font-size: 11px;">{{ $chest->created_at->diffForHumans() }}</td></tr>@empty<tr><td colspan="6" style="text-align: center; color: var(--text-muted);">No chest history</td></tr>@endforelse
</tbody></table></div>
</div>
</div>
@endsection
