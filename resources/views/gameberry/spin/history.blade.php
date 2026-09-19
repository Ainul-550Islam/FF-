@extends('layouts.app')

@section('title', 'Spin2Win History')

@section('content')
<div class="container" style="max-width: 800px; margin: 0 auto; padding: 24px;">
    <h1 style="font-size: 24px; font-weight: 800; margin: 0 0 16px;">🎡 Spin2Win History</h1>

    <div class="card">
        <div style="padding: 20px;">
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Date</th><th>Result</th><th>Gold</th><th>Gems</th><th>Dice</th><th>Cost</th></tr></thead>
                    <tbody>
                        @forelse($history as $spin)
                        <tr>
                            <td style="font-size: 11px;">{{ $spin->spun_at->format('Y-m-d H:i') }} ({{ $spin->spun_at->diffForHumans() }})</td>
                            <td><span class="badge" style="background: {{ $spin->result === 'jackpot' ? 'gold' : ($spin->result === 'gold' ? '#f1c40f' : ($spin->result === 'gems' ? '#9b59b6' : '#3498db')) }}; color: {{ $spin->result === 'jackpot' || $spin->result === 'gold' ? 'black' : 'white' }};">{{ $spin->result }}</span></td>
                            <td>{{ $spin->gold_amount }}</td>
                            <td>{{ $spin->gem_amount }}</td>
                            <td>{{ $spin->dice_id ? 'Dice #'.$spin->dice_id : '-' }}</td>
                            <td>{{ $spin->gold_cost }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="6" style="text-align: center; color: var(--text-muted);">No spin history - Spin2Win 100 gold for rewards gold gems dice jackpot</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div style="margin-top: 16px; text-align: center;">
        <a href="{{ route('gameberry.spin.index') }}" class="btn btn-secondary">Back to Spin2Win</a>
    </div>
</div>
@endsection
