@extends('layouts.app')

@section('title', 'Referral BGI20 ₹25 Bonus - Scratch Cards')

@section('content')
<div class="container" style="max-width: 1000px; margin: 0 auto; padding: 24px;">
    <h1 style="font-size: 28px; font-weight: 800; margin: 0 0 8px;">🎁 Referral & Scratch Cards</h1>
    <p style="color: var(--text-muted); margin: 0 0 24px;">BGI20 style referral - ₹25 bonus (2500 minor) - Scratch cards - Khiladi Adda feature</p>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px;">
        <div class="card" style="border: 2px solid var(--primary);">
            <div style="padding: 20px; text-align: center;">
                <div style="font-size: 48px;">🎟️</div>
                <h2 style="margin: 8px 0;">Your Referral Code</h2>
                <div style="font-size: 32px; font-weight: 800; letter-spacing: 4px; background: var(--bg-secondary); padding: 12px; border-radius: 12px; margin: 12px 0;">{{ $stats['code'] ?? 'No Code Yet' }}</div>
                <div style="font-size: 13px; color: var(--text-muted);">Share this BGI20 style code for ₹25 bonus</div>
                <div style="margin-top: 16px; display: flex; gap: 8px; justify-content: center;">
                    <form method="POST" action="{{ route('gameberry.referral.generate') }}">
                        @csrf
                        <button type="submit" class="btn btn-primary">Generate Code</button>
                    </form>
                </div>
                <div style="margin-top: 16px; font-size: 12px; background: var(--bg-secondary); padding: 8px; border-radius: 8px; text-align: left;">
                    <div>Share text: "Join FF Arena! Use my code {{ $stats['code'] ?? 'BGI20' }} for ₹25 bonus + scratch card! {{ url('/register') }}?ref={{ $stats['code'] ?? 'BGI20' }}"</div>
                </div>
            </div>
        </div>

        <div class="card">
            <div style="padding: 20px;">
                <h3 style="margin: 0 0 12px;">Referral Stats</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; font-size: 13px;">
                    <div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 24px; font-weight: 800;">{{ $stats['total_referrals'] }}</div>
                        <div style="color: var(--text-muted);">Total Referrals</div>
                    </div>
                    <div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 24px; font-weight: 800;">{{ $stats['completed'] }}</div>
                        <div style="color: var(--text-muted);">Completed</div>
                    </div>
                    <div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 24px; font-weight: 800;">{{ $stats['pending'] }}</div>
                        <div style="color: var(--text-muted);">Pending</div>
                    </div>
                    <div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 18px; font-weight: 800;">{{ $stats['total_earned_formatted'] }}</div>
                        <div style="color: var(--text-muted);">Earned</div>
                    </div>
                </div>

                <h4 style="margin: 16px 0 8px;">Apply Referral Code</h4>
                <form method="POST" action="{{ route('gameberry.referral.apply') }}" style="display: flex; gap: 8px;">
                    @csrf
                    <input type="text" name="code" placeholder="Enter BGI20 code" required style="flex: 1; padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border); background: var(--bg-secondary); text-transform: uppercase;">
                    <button type="submit" class="btn btn-sm btn-secondary">Apply ₹25 Bonus</button>
                </form>
            </div>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
        <div class="card">
            <div style="padding: 20px;">
                <h3>My Referrals ({{ $referrals->count() }})</h3>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>User</th><th>Status</th><th>Bonus</th><th>Date</th></tr></thead>
                        <tbody>
                            @forelse($referrals as $ref)
                            <tr>
                                <td>{{ $ref->referred->name ?? 'User '.$ref->referred_id }}</td>
                                <td><span class="badge" style="background: {{ $ref->status === 'rewarded' ? '#27ae60' : '#f39c12' }}; color: white;">{{ $ref->status }}</span></td>
                                <td>₹{{ number_format($ref->bonus_minor/100, 2) }}</td>
                                <td style="font-size: 11px;">{{ $ref->created_at->diffForHumans() }}</td>
                            </tr>
                            @empty
                            <tr><td colspan="4" style="text-align: center; color: var(--text-muted);">No referrals yet - Share your BGI20 code!</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card">
            <div style="padding: 20px;">
                <h3>Scratch Cards ({{ $scratchCards->count() }}) - Khiladi Adda Feature</h3>
                @forelse($scratchCards as $card)
                <div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center; border: 2px dashed {{ $card->status === 'unscratched' ? '#f1c40f' : '#27ae60' }};">
                    <div>
                        <div style="font-weight: 600; font-size: 13px;">{{ $card->code }} - {{ $card->type }}</div>
                        <div style="font-size: 11px; color: var(--text-muted);">Reward: {{ $card->reward_minor/100 }} ₹ + {{ $card->reward_gems }} gems | {{ $card->status }} @if($card->expires_at) Expires {{ $card->expires_at->diffForHumans() }} @endif</div>
                    </div>
                    @if($card->status === 'unscratched')
                    <form method="POST" action="{{ route('gameberry.referral.scratch', $card->id) }}">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-primary">Scratch! 🎫</button>
                    </form>
                    @else
                    <span class="badge" style="background: #27ae60; color: white;">{{ $card->status }}</span>
                    @endif
                </div>
                @empty
                <p style="font-size: 13px; color: var(--text-muted);">No scratch cards - Refer friends to get scratch cards!</p>
                @endforelse
                <a href="{{ route('gameberry.referral.scratch_cards') }}" class="btn btn-sm btn-ghost" style="margin-top: 12px;">View All Scratch Cards</a>
            </div>
        </div>
    </div>
</div>
@endsection
