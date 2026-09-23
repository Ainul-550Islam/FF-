@extends('layouts.app')

@section('title', 'A/B Experiments — FF Arena Admin')

@section('content')
<header class="page-head">
    <h1 class="page-title">🧪 A/B Experiments</h1>
</header>

@if (session('success'))
    <div class="alert alert-success" role="status">{{ session('success') }}</div>
@endif

<section class="card">
    <h2 style="margin-top: 0">Running experiments</h2>
    @if ($experiments->isEmpty())
        <p class="muted">No experiments yet.</p>
    @else
        <table>
            <thead>
                <tr><th>Key</th><th>Name</th><th>Status</th><th>Allocation</th><th>Variants (exposures / conversions)</th></tr>
            </thead>
            <tbody>
                @foreach ($experiments as $experiment)
                    <tr>
                        <td><code>{{ $experiment->key }}</code></td>
                        <td>{{ $experiment->name }}</td>
                        <td>{{ ucfirst($experiment->status) }}</td>
                        <td>{{ $experiment->traffic_allocation }}%</td>
                        <td>
                            @foreach ($experiment->variants as $variant)
                                <div>
                                    {{ $variant->key }} ({{ $variant->allocation }}%)
                                    — {{ $stats[$experiment->id][$variant->key]['exposures'] ?? 0 }} / {{ $stats[$experiment->id][$variant->key]['conversions'] ?? 0 }}
                                </div>
                            @endforeach
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>

<section class="card" style="margin-top: 16px">
    <h2 style="margin-top: 0">Create an experiment</h2>
    <form method="POST" action="{{ route('admin.marketing.experiments.store') }}">
        @csrf
        <div class="row" style="gap: 12px; flex-wrap: wrap">
            <div class="field">
                <label for="exp-key">Key</label>
                <input type="text" id="exp-key" name="key" value="{{ old('key') }}" required maxlength="64">
            </div>
            <div class="field">
                <label for="exp-name">Name</label>
                <input type="text" id="exp-name" name="name" value="{{ old('name') }}" required maxlength="190">
            </div>
            <div class="field" style="max-width: 140px">
                <label for="exp-alloc">Traffic %</label>
                <input type="number" id="exp-alloc" name="traffic_allocation" value="{{ old('traffic_allocation', 100) }}" min="0" max="100" required>
            </div>
        </div>
        <div class="field" style="max-width: 480px">
            <label for="exp-desc">Description (optional)</label>
            <input type="text" id="exp-desc" name="description" value="{{ old('description') }}" maxlength="500">
        </div>

        <h3>Variants</h3>
        @foreach (['A', 'B'] as $i => $label)
            <div class="row" style="gap: 12px; flex-wrap: wrap">
                <div class="field">
                    <label for="variant-key-{{ $i }}">Variant {{ $label }} key</label>
                    <input type="text" id="variant-key-{{ $i }}" name="variants[{{ $i }}][key]" value="{{ old('variants.'.$i.'.key', $label) }}" required maxlength="32">
                </div>
                <div class="field">
                    <label for="variant-name-{{ $i }}">Variant {{ $label }} name</label>
                    <input type="text" id="variant-name-{{ $i }}" name="variants[{{ $i }}][name]" value="{{ old('variants.'.$i.'.name', 'Variant '.$label) }}" required maxlength="120">
                </div>
                <div class="field" style="max-width: 120px">
                    <label for="variant-alloc-{{ $i }}">Allocation %</label>
                    <input type="number" id="variant-alloc-{{ $i }}" name="variants[{{ $i }}][allocation]" value="{{ old('variants.'.$i.'.allocation', 50) }}" min="0" max="100" required>
                </div>
            </div>
        @endforeach

        <button type="submit" class="btn btn-cyan">Create experiment</button>
    </form>
</section>
@endsection
