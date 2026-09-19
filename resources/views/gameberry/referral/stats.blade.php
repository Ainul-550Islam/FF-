@extends('layouts.app')
@section('title', 'Referral Stats - BGI20 ₹25 Bonus')
@section('content')
<div class="container" style="max-width: 800px; margin: 0 auto; padding: 24px;">
<h1 style="font-size: 28px; font-weight: 800;">📊 Referral Stats - BGI20 Style</h1>
<p style="color: var(--text-muted);">Referral BGI20 ₹25 bonus 2500 minor + 10 gems + scratch card - Khiladi Adda feature</p>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-top: 20px;">
<div class="card" style="padding: 16px; text-align: center;"><div style="font-size: 24px; font-weight: 800;">{{ $stats['code'] ?? 'No Code' }}</div><div style="font-size: 11px; color: var(--text-muted);">Your Code BGI20 Style</div></div>
<div class="card" style="padding: 16px; text-align: center;"><div style="font-size: 24px; font-weight: 800;">{{ $stats['total_referrals'] }}</div><div style="font-size: 11px; color: var(--text-muted);">Total Referrals</div></div>
<div class="card" style="padding: 16px; text-align: center;"><div style="font-size: 24px; font-weight: 800;">{{ $stats['completed'] }}</div><div style="font-size: 11px; color: var(--text-muted);">Completed</div></div>
<div class="card" style="padding: 16px; text-align: center;"><div style="font-size: 24px; font-weight: 800;">{{ $stats['total_earned_formatted'] }}</div><div style="font-size: 11px; color: var(--text-muted);">Total Earned</div></div>
</div>
<div class="card" style="padding: 20px; margin-top: 20px;">
<h3>Referral List</h3>
<div class="table-wrap"><table class="table"><thead><tr><th>User</th><th>Status</th><th>Bonus</th><th>Date</th></tr></thead><tbody>
@forelse($referrals as $ref)<tr><td>{{ $ref->referred->name ?? 'User '.$ref->referred_id }}</td><td><span class="badge" style="background: {{ $ref->status==='rewarded' ? '#27ae60' : '#f39c12' }}; color: white;">{{ $ref->status }}</span></td><td>₹{{ number_format($ref->bonus_minor/100,2) }}</td><td style="font-size: 11px;">{{ $ref->created_at->diffForHumans() }}</td></tr>@empty<tr><td colspan="4" style="text-align: center; color: var(--text-muted);">No referrals - Share BGI20 code for ₹25 bonus</td></tr>@endforelse
</tbody></table></div>
</div>
</div>
@endsection
