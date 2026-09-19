@extends('layouts.app')

@section('title', 'Scratch Cards - Khiladi Adda Feature')

@section('content')
<div class="container" style="max-width: 800px; margin: 0 auto; padding: 24px;">
    <h1 style="font-size: 28px; font-weight: 800; margin: 0 0 8px;">🎫 Scratch Cards</h1>
    <p style="color: var(--text-muted); margin: 0 0 24px;">Khiladi Adda style scratch cards - Referral rewards - Gold & gems</p>

    <div class="card" style="margin-bottom: 24px; padding: 20px; text-align: center;">
        <div style="font-size: 48px;">🎫</div>
        <h3>Unscratched Cards ({{ $unscratched->count() }})</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; margin-top: 16px;">
            @forelse($unscratched as $card)
            <div style="background: linear-gradient(135deg, #f1c40f, #f39c12); color: black; padding: 16px; border-radius: 12px; text-align: center; border: 2px dashed black;">
                <div style="font-weight: 800; font-size: 14px;">{{ $card->code }}</div>
                <div style="font-size: 12px; margin: 8px 0;">??? Reward Hidden ???</div>
                <div style="font-size: 10px; opacity: 0.8;">{{ $card->type }} | Expires {{ $card->expires_at->diffForHumans() }}</div>
                <form method="POST" action="{{ route('gameberry.referral.scratch', $card->id) }}" style="margin-top: 12px;">
                    @csrf
                    <button type="submit" class="btn btn-sm" style="background: black; color: #f1c40f; width: 100%;">Scratch! 🎫</button>
                </form>
            </div>
            @empty
            <p style="color: var(--text-muted); font-size: 13px; grid-column: 1/-1;">No unscratched cards - Refer friends to get scratch cards BGI20 style</p>
            @endforelse
        </div>
    </div>

    <div class="card">
        <div style="padding: 20px;">
            <h3>All Scratch Cards ({{ $cards->count() }})</h3>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Code</th><th>Type</th><th>Reward Gold</th><th>Reward Gems</th><th>Status</th><th>Expires</th></tr></thead>
                    <tbody>
                        @foreach($cards as $card)
                        <tr>
                            <td style="font-size: 12px; font-weight: 600;">{{ $card->code }}</td>
                            <td>{{ $card->type }}</td>
                            <td>₹{{ number_format($card->reward_minor/100, 2) }}</td>
                            <td>{{ $card->reward_gems }}</td>
                            <td><span class="badge" style="background: {{ $card->status === 'unscratched' ? '#f1c40f' : ($card->status === 'scratched' ? '#3498db' : '#27ae60') }}; color: {{ $card->status === 'unscratched' ? 'black' : 'white' }};">{{ $card->status }}</span></td>
                            <td style="font-size: 11px;">{{ $card->expires_at ? $card->expires_at->diffForHumans() : '-' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
