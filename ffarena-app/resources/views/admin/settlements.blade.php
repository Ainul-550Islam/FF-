@extends('layouts.app')
@section('title', 'Settlements — FF Arena Admin')
@section('content')
    <header class="page-head">
        <h1 class="page-title">🧾 Financial Settlements</h1>
    </header>

    <section class="card" style="padding: 0" aria-labelledby="settlements-heading">
        <h2 id="settlements-heading" class="sr-only">Settlements</h2>
        @if ($tournaments->isEmpty())
            <x-empty-state title="No tournaments require settlement yet" icon="🧾">
                Tournaments that finish will appear here.
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Tournament settlements</caption>
                    <thead>
                        <tr>
                            <th scope="col">Tournament</th>
                            <th scope="col">Status</th>
                            <th scope="col">Distribution</th>
                            <th scope="col">Net collected</th>
                            <th scope="col">Allocated</th>
                            <th scope="col">Reconciliation</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tournaments as $tournament)
                            @php $summary = $summaries[$tournament->id] ?? null; @endphp
                            <tr>
                                <td>
                                    <strong>{{ $tournament->name }}</strong>
                                    <div class="muted" style="font-size: .8rem">{{ $tournament->slug }}</div>
                                </td>
                                <td><x-status-pill :status="$tournament->status" :label="strtoupper($tournament->status)" /></td>
                                <td>
                                    @if ($tournament->financialSettlement)
                                        <span class="pill confirmed">FINALIZED</span>
                                    @else
                                        <span class="pill pending">PENDING</span>
                                    @endif
                                </td>
                                <td>৳{{ number_format($summary['net_collected_minor'] / 100, 2) }}</td>
                                <td>৳{{ number_format($summary['allocated_prizes_minor'] / 100, 2) }}</td>
                                <td>
                                    <x-status-pill :status="$tournament->financialSettlement?->statusPill() ?? 'pending'" :label="$tournament->financialSettlement?->statusLabel() ?? 'not finalized'" />
                                </td>
                                <td>
                                    <a class="btn btn-sm btn-cyan" href="{{ route('admin.settlements.show', $tournament) }}">Manage</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $tournaments->links() }}</div>
        @endif
    </section>
@endsection
