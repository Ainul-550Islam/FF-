@extends('layouts.app')

@section('title', 'Marketing Analytics Dashboard — FF Arena')

@section('content')
<section class="container" style="max-width: 1080px">
    <header class="page-head" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px">
        <div>
            <h1 class="page-title" style="margin: 0">📊 Marketing Analytics & Funnel</h1>
            <p class="muted" style="margin: 4px 0 0">Real-time acquisition performance and conversion tracking.</p>
        </div>

        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap">
            <form method="GET" action="{{ route('admin.marketing.analytics.index') }}" style="display: flex; gap: 6px">
                <select name="days" onchange="this.form.submit()" style="padding: 6px 10px; border-radius: 4px">
                    <option value="7" {{ $days === 7 ? 'selected' : '' }}>Last 7 Days</option>
                    <option value="30" {{ $days === 30 ? 'selected' : '' }}>Last 30 Days</option>
                    <option value="90" {{ $days === 90 ? 'selected' : '' }}>Last 90 Days</option>
                    <option value="365" {{ $days === 365 ? 'selected' : '' }}>Last 1 Year</option>
                </select>
            </form>

            <a href="{{ route('admin.marketing.analytics.export', ['type' => 'funnel', 'period_start' => now()->subDays($days)->toDateString()]) }}" class="btn btn-sm btn-cyan">
                📥 Export Funnel CSV
            </a>
            <a href="{{ route('admin.marketing.analytics.export', ['type' => 'campaigns']) }}" class="btn btn-sm">
                📥 Export Campaigns CSV
            </a>
        </div>
    </header>

    @if ($totalIssues > 0)
        <div class="alert alert-warning" style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center">
            <div>
                <strong>⚠️ {{ $totalIssues }} UTM Tagging Issues Detected</strong>
                <span class="muted" style="font-size: 0.9rem"> (unregistered campaign codes, casing drift, or missing mediums)</span>
            </div>
            <a href="{{ route('admin.marketing.utm.index') }}" class="btn btn-sm">Review in UTM Governance &rarr;</a>
        </div>
    @endif

    <!-- High Level Audience KPIs -->
    <div class="row" style="display: flex; gap: 16px; margin-bottom: 24px; flex-wrap: wrap">
        <div class="card" style="flex: 1 1 220px">
            <span class="muted" style="font-size: 0.85rem">Tracked Unique Visitors</span>
            <h2 style="margin: 4px 0 0; color: #38bdf8">{{ number_format($totalVisitors) }}</h2>
            <p class="muted" style="margin: 4px 0 0; font-size: 0.8rem">Inbound Anonymous IDs</p>
        </div>
        <div class="card" style="flex: 1 1 220px">
            <span class="muted" style="font-size: 0.85rem">Attributed Registrations</span>
            <h2 style="margin: 4px 0 0; color: #818cf8">{{ number_format($totalAttributedUsers) }}</h2>
            <p class="muted" style="margin: 4px 0 0; font-size: 0.8rem">Users Linked to Touchpoint</p>
        </div>
        <div class="card" style="flex: 1 1 220px">
            <span class="muted" style="font-size: 0.85rem">Paid Transactions</span>
            <h2 style="margin: 4px 0 0; color: #34d399">{{ number_format($funnel['payments']) }}</h2>
            <p class="muted" style="margin: 4px 0 0; font-size: 0.8rem">Completed Entry Fees / Deposits</p>
        </div>
        <div class="card" style="flex: 1 1 220px">
            <span class="muted" style="font-size: 0.85rem">Overall Visitor-to-Paid CR</span>
            <h2 style="margin: 4px 0 0">
                {{ $totalVisitors > 0 ? round(($funnel['payments'] / $totalVisitors) * 100, 2) : 0 }}%
            </h2>
            <p class="muted" style="margin: 4px 0 0; font-size: 0.8rem">End-to-End Funnel Yield</p>
        </div>
    </div>

    <!-- Funnel Section -->
    @include('marketing.analytics._funnel')

    <!-- Conversions Section -->
    @include('marketing.analytics._conversions')

    <!-- Acquisition Sources and UTM Campaigns -->
    @include('marketing.analytics._acquisition')

    <!-- Active Campaign Performance -->
    @include('marketing.analytics._campaigns')
</section>
@endsection
