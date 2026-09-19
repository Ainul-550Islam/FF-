@extends('layouts.app')
@section('title', 'Video Ads History - Free Gold')
@section('content')
<div class="container" style="max-width: 800px; margin: 0 auto; padding: 24px;">
<h1 style="font-size: 28px; font-weight: 800;">📺 Video Ads History</h1>
<p style="color: var(--text-muted);">Free gold from video ads - 100 gold + 1 gem per ad - Daily limit 5</p>
<div class="card" style="padding: 20px; margin-top: 20px;">
<div class="table-wrap"><table class="table"><thead><tr><th>Date</th><th>Provider</th><th>Gold</th><th>Gems</th><th>Status</th></tr></thead><tbody>
@forelse($history as $ad)<tr><td style="font-size: 11px;">{{ $ad->created_at->format('Y-m-d H:i') }}</td><td>{{ $ad->ad_provider }}</td><td>{{ $ad->gold_reward }}</td><td>{{ $ad->gem_reward }}</td><td><span class="badge" style="background: {{ $ad->status==='rewarded' ? '#27ae60' : '#f39c12' }}; color: white;">{{ $ad->status }}</span></td></tr>@empty<tr><td colspan="5" style="text-align: center; color: var(--text-muted);">No video ads watched - Watch for free gold like LudoStar</td></tr>@endforelse
</tbody></table></div>
</div>
</div>
@endsection
