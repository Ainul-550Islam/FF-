@extends('layouts.app')

@section('title', 'UTM Snapshots Detail — FF Arena')

@section('content')
<section class="container" style="max-width: 1040px">
    <div style="margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center">
        <a href="{{ route('admin.marketing.utm.index') }}" class="btn btn-sm">&larr; Back to UTM Governance</a>
        <a href="{{ route('admin.marketing.analytics.export', ['type' => 'utm'] + $filters) }}" class="btn btn-sm btn-cyan">📥 Export Filtered CSV</a>
    </div>

    <header class="page-head">
        <h1 class="page-title">Normalized UTM Periodic Snapshots</h1>
        <p class="muted">Detailed aggregated channel records across dimensions.</p>
    </header>

    <div class="card" style="margin-bottom: 20px">
        <form method="GET" action="{{ route('admin.marketing.utm.snapshots') }}" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; align-items: flex-end">
            <div class="field" style="margin: 0">
                <label for="f_source">Source</label>
                <select id="f_source" name="source">
                    <option value="">All Sources</option>
                    @foreach ($sources as $s)
                        <option value="{{ $s }}" {{ ($filters['source'] ?? '') === $s ? 'selected' : '' }}>{{ $s }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field" style="margin: 0">
                <label for="f_medium">Medium</label>
                <select id="f_medium" name="medium">
                    <option value="">All Mediums</option>
                    @foreach ($mediums as $m)
                        <option value="{{ $m }}" {{ ($filters['medium'] ?? '') === $m ? 'selected' : '' }}>{{ $m }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field" style="margin: 0">
                <label for="f_campaign">Campaign</label>
                <input type="text" id="f_campaign" name="campaign" value="{{ $filters['campaign'] ?? '' }}" placeholder="Filter campaign...">
            </div>

            <div class="field" style="margin: 0">
                <label for="f_pstart">From Date</label>
                <input type="date" id="f_pstart" name="period_start" value="{{ $filters['period_start'] ?? '' }}">
            </div>

            <div style="display: flex; gap: 8px">
                <button type="submit" class="btn btn-cyan btn-sm" style="height: 38px">Apply Filters</button>
                <a href="{{ route('admin.marketing.utm.snapshots') }}" class="btn btn-sm" style="height: 38px; line-height: 24px">Reset</a>
            </div>
        </form>
    </div>

    <div class="card">
        @if ($snapshots->isEmpty())
            <p class="muted" style="text-align: center; padding: 24px 0">No snapshot records match the given criteria.</p>
        @else
            <div style="overflow-x: auto">
                <table style="width: 100%; border-collapse: collapse">
                    <thead>
                        <tr style="text-align: left; border-bottom: 1px solid rgba(255,255,255,0.1)">
                            <th style="padding: 10px">Period</th>
                            <th style="padding: 10px">Source</th>
                            <th style="padding: 10px">Medium</th>
                            <th style="padding: 10px">Campaign</th>
                            <th style="padding: 10px; text-align: right">Touches</th>
                            <th style="padding: 10px; text-align: right">Visitors</th>
                            <th style="padding: 10px; text-align: right">Conversions</th>
                            <th style="padding: 10px; text-align: right">Users</th>
                            <th style="padding: 10px; text-align: right">CR (%)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($snapshots as $snap)
                            @php
                                $cr = $snap->touches > 0 ? round(($snap->conversions / $snap->touches) * 100, 1) : 0;
                            @endphp
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.05)">
                                <td style="padding: 10px" class="muted">
                                    {{ $snap->period_start?->format('d M') }} &ndash; {{ $snap->period_end?->format('d M Y') }}
                                </td>
                                <td style="padding: 10px"><code>{{ $snap->source ?: '(none)' }}</code></td>
                                <td style="padding: 10px"><code>{{ $snap->medium ?: '(none)' }}</code></td>
                                <td style="padding: 10px"><code>{{ $snap->campaign ?: '(none)' }}</code></td>
                                <td style="padding: 10px; text-align: right">{{ number_format($snap->touches) }}</td>
                                <td style="padding: 10px; text-align: right">{{ number_format($snap->unique_visitors) }}</td>
                                <td style="padding: 10px; text-align: right; color: #34d399; font-weight: bold">{{ number_format($snap->conversions) }}</td>
                                <td style="padding: 10px; text-align: right">{{ number_format($snap->attributed_users) }}</td>
                                <td style="padding: 10px; text-align: right">{{ $cr }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 16px">
                {{ $snapshots->withQueryString()->links() }}
            </div>
        @endif
    </div>
</section>
@endsection
