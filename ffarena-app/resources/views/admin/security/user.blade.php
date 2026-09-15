@extends('layouts.app')
@section('title', 'Investigation — ' . $subject->name . ' — FF Arena Admin')
@section('content')
    <header class="page-head">
        <div class="row-between">
            <h1 class="page-title">🔍 {{ $subject->name }}</h1>
            <a class="btn btn-sm" href="{{ route('admin.security.users') }}">← All users</a>
        </div>
        <p class="muted">{{ $subject->email }} · role {{ $subject->role }} · user #{{ $subject->id }}</p>
    </header>

    <div class="grid cols-2">
        <section class="card" aria-labelledby="risk-profile-heading">
            <h3 id="risk-profile-heading">Risk Profile</h3>
            @if (!$profile)
                <p class="muted">No risk profile yet (low risk).</p>
            @else
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">Risk profile</caption>
                        <tbody>
                            <tr><th scope="row">Score</th><td>{{ $profile->risk_score }}/100</td></tr>
                            <tr><th scope="row">Level</th><td><x-status-pill :status="$profile->levelPill()" :label="strtoupper($profile->risk_level)" /></td></tr>
                            <tr><th scope="row">Status</th><td>{{ $profile->status }}</td></tr>
                            <tr><th scope="row">Manual review</th><td>{{ $profile->manual_review_required ? '⚠️ required' : 'no' }}</td></tr>
                            <tr><th scope="row">Restricted until</th><td>{{ $profile->restricted_until?->format('d M Y, h:i A') ?? '—' }}</td></tr>
                            <tr><th scope="row">Flags</th><td class="muted" style="font-size: .8rem">{{ implode(', ', $profile->account_flags ?? []) ?: '—' }}</td></tr>
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="card" aria-labelledby="identity-heading">
            <h3 id="identity-heading">Identity Verification</h3>
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Identity verification</caption>
                    <tbody>
                        <tr><th scope="row">Status</th><td><x-status-pill :status="$identity->statusPill()" :label="$identity->statusLabel()" /></td></tr>
                        <tr><th scope="row">Provider</th><td class="muted">{{ $identity->provider }}</td></tr>
                        <tr><th scope="row">Verified at</th><td class="muted">{{ $identity->verified_at?->format('d M Y, h:i A') ?? '—' }}</td></tr>
                        <tr><th scope="row">Expires</th><td class="muted">{{ $identity->expires_at?->format('d M Y') ?? '—' }}</td></tr>
                        <tr><th scope="row">Reviewed by</th><td class="muted">{{ $identity->reviewedBy?->name ?? '—' }}</td></tr>
                        <tr><th scope="row">Notes</th><td class="muted" style="font-size: .8rem">{{ $identity->notes ?? '—' }}</td></tr>
                    </tbody>
                </table>
            </div>
            @if ($identity->status === 'pending' || $identity->status === 'review_required' || $identity->status === 'rejected' || $identity->status === 'expired')
                <div class="row mt-3" style="gap: 10px">
                    <form method="POST" action="{{ route('admin.security.verify', $subject) }}">
                        @csrf
                        <div class="row" style="gap: 6px; align-items: center">
                            <label for="verify-notes" class="sr-only">Review note</label>
                            <input type="text" id="verify-notes" name="notes" placeholder="Review note (optional)" style="max-width: 160px">
                            <button type="submit" class="btn btn-green btn-sm">Verify</button>
                        </div>
                    </form>
                    <form method="POST" action="{{ route('admin.security.reject', $subject) }}">
                        @csrf
                        <div class="row" style="gap: 6px; align-items: center">
                            <label for="reject-notes" class="sr-only">Rejection note</label>
                            <input type="text" id="reject-notes" name="notes" placeholder="Rejection note" style="max-width: 160px">
                            <button type="submit" class="btn btn-sm btn-danger">Reject</button>
                        </div>
                    </form>
                </div>
            @endif
        </section>
    </div>

    <div class="grid cols-2 mt-4">
        <section class="card" aria-labelledby="devices-heading">
            <h3 id="devices-heading">🔒 Devices</h3>
            @if ($devices->isEmpty())
                <p class="muted">No device associations.</p>
            @else
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">Device associations</caption>
                        <thead>
                            <tr>
                                <th scope="col">Device (hash)</th>
                                <th scope="col">Accounts</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($devices as $device)
                                <tr>
                                    <td class="muted" style="font-size: .8rem">{{ substr($device->device_hash, 0, 16) }}…</td>
                                    <td>{{ $device->links_count }}</td>
                                    <td><x-status-pill :status="$device->isBlocked() ? 'failed' : 'confirmed'" :label="$device->status" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="card" aria-labelledby="ip-heading">
            <h3 id="ip-heading">🌐 IP Observations</h3>
            @if ($ipIntel->isEmpty())
                <p class="muted">No IP observations.</p>
            @else
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">IP observations</caption>
                        <thead>
                            <tr>
                                <th scope="col">IP (hash)</th>
                                <th scope="col">Observations</th>
                                <th scope="col">Last seen</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($ipIntel as $link)
                                <tr>
                                    <td class="muted" style="font-size: .8rem">{{ substr($link->ipIntel->ip_hash, 0, 16) }}…</td>
                                    <td>{{ $link->ipIntel->observation_count }}</td>
                                    <td class="muted" style="font-size: .8rem">{{ $link->ipIntel->last_seen_at?->format('d M, h:i A') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>

    <div class="grid cols-2 mt-4">
        <section class="card" aria-labelledby="linked-heading">
            <h3 id="linked-heading">🔗 Linked Accounts</h3>
            @if ($links->isEmpty())
                <p class="muted">No linked accounts.</p>
            @else
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">Linked accounts</caption>
                        <thead>
                            <tr>
                                <th scope="col">Linked user</th>
                                <th scope="col">Strength</th>
                                <th scope="col">Reasons</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($links as $link)
                                @php $other = $link->user_id === $subject->id ? $link->linkedUser : $link->user; @endphp
                                <tr>
                                    <td>{{ $other?->name ?? '—' }}</td>
                                    <td><x-status-pill :status="$link->strength === 'strong' ? 'failed' : ($link->strength === 'moderate' ? 'pending' : 'draft')" :label="$link->strength" /></td>
                                    <td class="muted" style="font-size: .8rem">{{ implode(', ', $link->reasons ?? []) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="card" aria-labelledby="restrictions-heading">
            <h3 id="restrictions-heading">🚫 Restrictions</h3>
            @if ($restrictions->isEmpty())
                <p class="muted">No restrictions.</p>
            @else
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">Restrictions</caption>
                        <thead>
                            <tr>
                                <th scope="col">Type</th>
                                <th scope="col">Status</th>
                                <th scope="col">Reason</th>
                                <th scope="col"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($restrictions as $restriction)
                                <tr>
                                    <td class="muted" style="font-size: .8rem">{{ $restriction->typeLabel() }}</td>
                                    <td><x-status-pill :status="$restriction->isActive() ? 'failed' : 'confirmed'" :label="$restriction->status" /></td>
                                    <td class="muted" style="font-size: .8rem">{{ $restriction->reason }}</td>
                                    <td>
                                        @if ($restriction->isActive())
                                            <form method="POST" action="{{ route('admin.security.lift', $restriction) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-green">Lift</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.security.restrict', $subject) }}" class="mt-4">
                @csrf
                <div class="row" style="align-items: flex-end">
                    <div class="field" style="min-width: 200px">
                        <label for="restriction-type">Restriction type</label>
                        <select id="restriction-type" name="type">
                            @foreach (\App\Models\Restriction::TYPES as $type)
                                <option value="{{ $type }}">{{ ucwords(str_replace('_', ' ', $type)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field" style="min-width: 200px">
                        <label for="restriction-reason">Reason</label>
                        <input type="text" id="restriction-reason" name="reason" required>
                    </div>
                    <div class="field" style="min-width: 120px">
                        <label for="restriction-expires">Expires (days, optional)</label>
                        <input type="number" id="restriction-expires" name="expires_in_days" min="1" max="3650">
                    </div>
                    <button type="submit" class="btn btn-sm btn-danger">Apply Restriction</button>
                </div>
            </form>
        </section>
    </div>

    <section class="card mt-4" aria-labelledby="risk-events-heading">
        <h3 id="risk-events-heading">🧾 Risk Events</h3>
        @if ($events->isEmpty())
            <p class="muted">No risk events.</p>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Risk events for this account</caption>
                    <thead>
                        <tr>
                            <th scope="col">Type</th>
                            <th scope="col">Severity</th>
                            <th scope="col">Score</th>
                            <th scope="col">Source</th>
                            <th scope="col">When</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($events as $event)
                            <tr>
                                <td class="muted" style="font-size: .8rem">{{ $event->type }}</td>
                                <td><x-status-pill :status="in_array($event->severity, ['high', 'critical'], true) ? 'failed' : ($event->severity === 'medium' ? 'pending' : 'draft')" :label="strtoupper($event->severity)" /></td>
                                <td>+{{ $event->score_contribution }}</td>
                                <td class="muted" style="font-size: .8rem">{{ $event->source }}</td>
                                <td class="muted" style="font-size: .8rem">{{ $event->created_at?->format('d M, h:i A') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="card mt-4" aria-labelledby="incidents-heading">
        <h3 id="incidents-heading">🎮 Anti-cheat Incidents</h3>
        @if ($incidents->isEmpty())
            <p class="muted">No anti-cheat incidents.</p>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Anti-cheat incidents for this account</caption>
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Tournament</th>
                            <th scope="col">Category</th>
                            <th scope="col">Severity</th>
                            <th scope="col">Status</th>
                            <th scope="col">Role</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($incidents as $incident)
                            <tr>
                                <td><strong>#{{ $incident->id }}</strong></td>
                                <td>{{ $incident->tournament?->name ?? '—' }}</td>
                                <td class="muted">{{ $incident->category }}</td>
                                <td class="muted">{{ $incident->severity }}</td>
                                <td><x-status-pill :status="$incident->statusPill()" :label="$incident->statusLabel()" /></td>
                                <td class="muted" style="font-size: .8rem">{{ $incident->accused_user_id === $subject->id ? 'accused' : 'reporter' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
