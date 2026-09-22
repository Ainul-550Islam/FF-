@extends('layouts.app')
@section('title', 'Account: ' . $subject->name . ' — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">👤 {{ $subject->name }}</h1>
        <div class="row muted">
            ID {{ $subject->id }} · {{ $subject->email }}
            <x-status-pill :status="$subject->role === 'admin' ? 'live' : ($subject->role === 'moderator' ? 'pending' : 'draft')" :label="$subject->role" />
            <x-status-pill :status="$subject->account_status === 'active' ? 'confirmed' : 'failed'" :label="$subject->account_status" />
        </div>
    </header>

    <div class="card row">
        <form method="POST" action="{{ route('admin.accounts.sessions.revoke', $subject) }}">
            @csrf
            <button type="submit" class="btn btn-sm">Revoke all sessions</button>
        </form>
        @if ($subject->isActive())
            <form method="POST" action="{{ route('admin.accounts.deactivate', $subject) }}">
                @csrf
                <button type="submit" class="btn btn-sm" style="border-color: var(--amber); color: var(--amber)">Deactivate</button>
            </form>
        @elseif ($subject->account_status === 'deactivated')
            <form method="POST" action="{{ route('admin.accounts.reactivate', $subject) }}">
                @csrf
                <button type="submit" class="btn btn-green btn-sm">Reactivate</button>
            </form>
        @endif
        <form method="POST" action="{{ route('admin.accounts.delete', $subject) }}"
              onsubmit="return confirm('Anonymize (delete) this account? Immutable history is preserved.')">
            @csrf
            <button type="submit" class="btn btn-sm btn-danger">Delete (anonymize)</button>
        </form>
    </div>

    <div class="grid cols-2 mt-4">
        <section class="card" aria-labelledby="identities-heading">
            <h3 id="identities-heading">Identities &amp; verification</h3>
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Linked identities</caption>
                    <thead>
                        <tr>
                            <th scope="col">Provider</th>
                            <th scope="col">Subject</th>
                            <th scope="col">Verified</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($identities as $linkedIdentity)
                            <tr>
                                <td>{{ $linkedIdentity->provider }}</td>
                                <td class="muted">{{ $linkedIdentity->provider_subject }}</td>
                                <td>{{ $linkedIdentity->isVerified() ? '✅' : '—' }}</td>
                            </tr>
                        @endforeach
                        @if ($identities->isEmpty())
                            <tr><td colspan="3" class="muted">No linked identities.</td></tr>
                        @endif
                    </tbody>
                </table>
            </div>
            <p class="mt-3">
                Email verified: {{ $subject->hasVerifiedEmail() ? '✅' : '—' }} ·
                Identity verification:
                <x-status-pill :status="$identity->statusPill()" :label="$identity->statusLabel()" />
            </p>
        </section>

        <section class="card" aria-labelledby="risk-heading">
            <h3 id="risk-heading">Risk &amp; restrictions</h3>
            <p>
                Risk level:
                <x-status-pill :status="($riskProfile?->risk_level ?? 'low') === 'low' ? 'confirmed' : 'failed'" :label="$riskProfile?->risk_level ?? 'low'" />
                (score {{ $riskProfile?->risk_score ?? 0 }})
            </p>
            @if ($restrictions->isEmpty())
                <p class="muted">No restrictions.</p>
            @else
                <ul>
                    @foreach ($restrictions as $restriction)
                        <li>{{ $restriction->typeLabel() }} — {{ $restriction->reason }}
                            <span class="muted">({{ $restriction->status }})</span></li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <div class="grid cols-2 mt-4">
        <section class="card" aria-labelledby="events-heading">
            <h3 id="events-heading">Recent security events</h3>
            @if ($loginEvents->isEmpty())
                <p class="muted">None.</p>
            @else
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">Recent security events</caption>
                        <thead>
                            <tr>
                                <th scope="col">Event</th>
                                <th scope="col">Status</th>
                                <th scope="col">Device</th>
                                <th scope="col">When</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($loginEvents as $event)
                                <tr>
                                    <td>{{ ucwords(str_replace(['.', '_'], ' ', $event->event)) }}</td>
                                    <td>{{ $event->status }}</td>
                                    <td class="muted">{{ $event->device_label ?? '—' }}</td>
                                    <td class="muted">{{ $event->created_at?->diffForHumans() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="card" aria-labelledby="payment-methods-heading">
            <h3 id="payment-methods-heading">Payment methods</h3>
            @if ($paymentMethods->isEmpty())
                <p class="muted">None.</p>
            @else
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">Linked payment methods</caption>
                        <thead>
                            <tr>
                                <th scope="col">Provider</th>
                                <th scope="col">Label</th>
                                <th scope="col">Identifier</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($paymentMethods as $method)
                                <tr>
                                    <td>{{ $method->provider }}</td>
                                    <td>{{ $method->label }}</td>
                                    <td class="muted">{{ $method->masked_identifier }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>

    <section class="card mt-4" aria-labelledby="audit-heading">
        <h3 id="audit-heading">Audit history</h3>
        @if ($auditHistory->isEmpty())
            <p class="muted">No audit entries.</p>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Audit history for this account</caption>
                    <thead>
                        <tr>
                            <th scope="col">Action</th>
                            <th scope="col">Entity</th>
                            <th scope="col">When</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($auditHistory as $entry)
                            <tr>
                                <td>{{ $entry->action }}</td>
                                <td class="muted">{{ $entry->entity_type }}#{{ $entry->entity_id }}</td>
                                <td class="muted">{{ $entry->created_at?->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
