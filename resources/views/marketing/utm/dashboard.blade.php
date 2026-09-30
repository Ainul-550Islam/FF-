@extends('layouts.app')

@section('title', 'UTM Governance & Tagging Hygiene — FF Arena')

@section('content')
<section class="container" style="max-width: 1040px">
    <header class="page-head" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 20px">
        <div>
            <h1 class="page-title" style="margin: 0">🏷️ UTM Governance & Hygiene</h1>
            <p class="muted" style="margin: 4px 0 0">Normalized reporting and tagging quality audit over MarketingAttribution touches.</p>
        </div>

        <div style="display: flex; gap: 10px; flex-wrap: wrap">
            <a href="{{ route('admin.marketing.utm.snapshots') }}" class="btn btn-sm">View Detailed Snapshots &rarr;</a>
            <a href="{{ route('admin.marketing.analytics.export', ['type' => 'utm']) }}" class="btn btn-sm btn-cyan">📥 Export UTM CSV</a>
        </div>
    </header>

    @if (session('success'))
        <div class="alert alert-success" role="status">{{ session('success') }}</div>
    @endif

    <!-- Tagging Quality Issues Card -->
    <div class="card" style="margin-bottom: 24px">
        <h3 style="margin-top: 0">🚨 Detected Tagging Quality Issues</h3>
        <p class="muted" style="font-size: 0.85rem">Inbound traffic parameters with syntax, casing, or naming inconsistencies.</p>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-top: 16px">
            <div style="padding: 14px; background: rgba(255,255,255,0.02); border-radius: 6px; border: 1px solid rgba(255,255,255,0.06)">
                <h4 style="margin: 0 0 6px; color: #f59e0b">Unregistered Campaigns ({{ count($issues['unregistered_campaigns']) }})</h4>
                <p class="muted" style="font-size: 0.8rem; margin: 0 0 8px">Traffic arriving under utm_campaign tags that do not exist as registered landing campaigns.</p>
                @if (empty($issues['unregistered_campaigns']))
                    <p style="color: #10b981; font-size: 0.85rem; margin: 0">✓ All campaign tags are registered</p>
                @else
                    <ul style="margin: 0; padding-left: 16px; font-size: 0.85rem">
                        @foreach (array_slice($issues['unregistered_campaigns'], 0, 5) as $camp => $count)
                            <li><code>{{ $camp }}</code> ({{ $count }} touches)</li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div style="padding: 14px; background: rgba(255,255,255,0.02); border-radius: 6px; border: 1px solid rgba(255,255,255,0.06)">
                <h4 style="margin: 0 0 6px; color: #f87171">Missing Mediums ({{ $issues['missing_medium'] }})</h4>
                <p class="muted" style="font-size: 0.8rem; margin: 0 0 8px">Touches with utm_source but empty utm_medium, breaking channel grouping.</p>
                @if ($issues['missing_medium'] === 0)
                    <p style="color: #10b981; font-size: 0.85rem; margin: 0">✓ Zero missing medium parameters</p>
                @else
                    <p style="color: #f87171; font-size: 0.9rem; margin: 0"><strong>{{ $issues['missing_medium'] }}</strong> touchpoints lack utm_medium</p>
                @endif
            </div>

            <div style="padding: 14px; background: rgba(255,255,255,0.02); border-radius: 6px; border: 1px solid rgba(255,255,255,0.06)">
                <h4 style="margin: 0 0 6px; color: #818cf8">Inconsistent Casing Drift ({{ count($issues['inconsistent_source_casing']) }})</h4>
                <p class="muted" style="font-size: 0.8rem; margin: 0 0 8px">Sources submitted with mixed uppercase/lowercase (e.g. Facebook vs facebook).</p>
                @if (empty($issues['inconsistent_source_casing']))
                    <p style="color: #10b981; font-size: 0.85rem; margin: 0">✓ No source casing variance</p>
                @else
                    <ul style="margin: 0; padding-left: 16px; font-size: 0.85rem">
                        @foreach (array_slice($issues['inconsistent_source_casing'], 0, 5) as $norm => $variants)
                            <li><code>{{ $norm }}</code>: {{ implode(', ', array_keys($variants)) }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>

    <!-- Aggregated UTM Summary -->
    <div class="card" style="margin-bottom: 24px">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px">
            <div>
                <h3 style="margin: 0">Normalized Snapshot Summary</h3>
                <p class="muted" style="margin: 2px 0 0; font-size: 0.85rem">Busiest normalized channel combinations for {{ $periodStart }} to {{ $periodEnd }}.</p>
            </div>

            <form method="GET" action="{{ route('admin.marketing.utm.index') }}" style="display: flex; gap: 8px; align-items: center">
                <input type="date" name="period_start" value="{{ $periodStart }}" style="padding: 4px 8px; font-size: 0.85rem">
                <span class="muted">to</span>
                <input type="date" name="period_end" value="{{ $periodEnd }}" style="padding: 4px 8px; font-size: 0.85rem">
                <button type="submit" class="btn btn-sm">Filter</button>
            </form>
        </div>

        @if ($summary->isEmpty())
            <p class="muted" style="text-align: center; padding: 24px 0">
                No snapshots found for this period. Build a snapshot below.
            </p>
        @else
            <div style="overflow-x: auto">
                <table style="width: 100%; border-collapse: collapse">
                    <thead>
                        <tr style="text-align: left; border-bottom: 1px solid rgba(255,255,255,0.1)">
                            <th style="padding: 8px">Source</th>
                            <th style="padding: 8px">Medium</th>
                            <th style="padding: 8px">Campaign</th>
                            <th style="padding: 8px; text-align: right">Touches</th>
                            <th style="padding: 8px; text-align: right">Visitors</th>
                            <th style="padding: 8px; text-align: right">Conversions</th>
                            <th style="padding: 8px; text-align: right">CR (%)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($summary->take(15) as $snap)
                            @php
                                $cr = $snap->touches > 0 ? round(($snap->conversions / $snap->touches) * 100, 1) : 0;
                            @endphp
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.05)">
                                <td style="padding: 8px"><code>{{ $snap->source ?: '(none)' }}</code></td>
                                <td style="padding: 8px"><code>{{ $snap->medium ?: '(none)' }}</code></td>
                                <td style="padding: 8px"><code>{{ $snap->campaign ?: '(none)' }}</code></td>
                                <td style="padding: 8px; text-align: right">{{ number_format($snap->touches) }}</td>
                                <td style="padding: 8px; text-align: right">{{ number_format($snap->unique_visitors) }}</td>
                                <td style="padding: 8px; text-align: right; font-weight: bold; color: #34d399">{{ number_format($snap->conversions) }}</td>
                                <td style="padding: 8px; text-align: right">{{ $cr }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <!-- Snapshot Rebuild Action -->
    <div class="card">
        <h3 style="margin-top: 0">🔄 Aggregate / Rebuild UTM Snapshot</h3>
        <p class="muted" style="font-size: 0.85rem">
            Executes idempotent aggregation of raw MarketingAttribution touches into periodic governance records. Raw touches are never altered.
        </p>

        <form method="POST" action="{{ route('admin.marketing.utm.snapshots.build') }}" style="display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap">
            @csrf
            <div class="field" style="margin: 0">
                <label for="start_date">Period Start</label>
                <input type="date" id="start_date" name="start_date" value="{{ now()->subDays(30)->toDateString() }}" required>
            </div>
            <div class="field" style="margin: 0">
                <label for="end_date">Period End</label>
                <input type="date" id="end_date" name="end_date" value="{{ now()->toDateString() }}" required>
            </div>
            <button type="submit" class="btn btn-cyan btn-sm" style="height: 38px">Run Snapshot Builder</button>
        </form>
    </div>
</section>
@endsection
