@extends('layouts.app')
@section('title', 'Dispute — ' . $tournament->name)
@section('content')
    <header class="page-head">
        <nav class="breadcrumbs" aria-label="Breadcrumb">
            <li><a href="{{ route('home') }}">Home</a></li>
            <li><a href="{{ route('tournaments.show', $tournament) }}">{{ $tournament->name }}</a></li>
            <li><a href="{{ route('matches.show', [$tournament, $match]) }}">Match #{{ $match->match_no }}</a></li>
            <li><span aria-current="page">Dispute #{{ $dispute->id }}</span></li>
        </nav>
        <h1 class="page-title">🚩 Dispute #{{ $dispute->id }}
            <x-status-pill :status="$dispute->statusPill()" :label="strtoupper($dispute->status)" />
        </h1>
        <p class="page-subtitle">
            {{ $match->team1?->name ?? 'TBD' }} vs {{ $match->team2?->name ?? 'TBD' }} ·
            {{ $match->roundLabel() }} #{{ $match->match_no }}
        </p>
    </header>

    <div class="grid cols-2">
        <section class="card" aria-labelledby="details-heading">
            <h3 id="details-heading">Details</h3>
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Dispute details</caption>
                    <tbody>
                        <tr><th scope="row">Category</th><td>{{ $dispute->categoryLabel() }}</td></tr>
                        <tr><th scope="row">Opened by</th><td>{{ $dispute->opener?->name ?? 'System' }}</td></tr>
                        <tr><th scope="row">Team</th><td>{{ $dispute->team?->name ?? '—' }}</td></tr>
                        <tr><th scope="row">Opened</th><td>{{ $dispute->created_at->format('d M Y, h:i A') }}</td></tr>
                        @if ($dispute->assignee)
                            <tr><th scope="row">Reviewer</th><td>{{ $dispute->assignee->name }}</td></tr>
                        @endif
                        @if ($dispute->resolved_at)
                            <tr><th scope="row">Resolved</th><td>{{ $dispute->resolved_at->format('d M Y, h:i A') }} by {{ $dispute->resolver?->name ?? '—' }}</td></tr>
                        @endif
                        @if ($dispute->resolutionWinner)
                            <tr><th scope="row">Confirmed winner</th><td><strong class="tag">{{ $dispute->resolutionWinner->name }}</strong></td></tr>
                        @endif
                    </tbody>
                </table>
            </div>

            <h3 class="mt-4">Description</h3>
            <p style="white-space: pre-wrap">{{ $dispute->description }}</p>

            @if ($dispute->resolution)
                <h3 class="mt-4">Resolution</h3>
                <p class="text-success" style="white-space: pre-wrap">{{ $dispute->resolution }}</p>
            @endif
        </section>

        <section class="card" aria-labelledby="evidence-heading">
            <h3 id="evidence-heading">📎 Evidence ({{ $dispute->evidence->count() }})</h3>
            @if ($dispute->evidence->isEmpty())
                <p class="muted">No evidence yet.</p>
            @else
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">Submitted evidence</caption>
                        <thead>
                            <tr>
                                <th scope="col">Type</th>
                                <th scope="col">By</th>
                                <th scope="col">Description</th>
                                <th scope="col"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($dispute->evidence as $evidence)
                                <tr>
                                    <td><x-status-pill status="pending" :label="$evidence->typeLabel()" /></td>
                                    <td>{{ $evidence->submitter?->name ?? 'System' }}</td>
                                    <td class="muted" style="font-size: .85rem">{{ $evidence->description }}</td>
                                    <td>
                                        @if ($evidence->path)
                                            <a href="{{ route('matches.disputes.evidence.show', [$tournament, $match, $dispute, $evidence]) }}" class="btn btn-sm" target="_blank">View</a>
                                        @else
                                            <span class="muted">text</span>
                                        @endif
                                        @if ($isStaff)
                                            <form method="POST" action="{{ route('matches.disputes.evidence.remove', [$tournament, $match, $dispute, $evidence]) }}" class="mt-1">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-danger"
                                                    onclick="return confirm('Remove this evidence permanently? This is audited.')">Remove</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($dispute->isActionable() && auth()->check())
                <hr style="border-color: var(--line); margin: 16px 0">
                <h3>Submit Evidence</h3>
                <form method="POST" action="{{ route('matches.disputes.evidence.store', [$tournament, $match, $dispute]) }}" enctype="multipart/form-data">
                    @csrf
                    <div class="field">
                        <label for="type">Type</label>
                        <select id="type" name="type" required>
                            <option value="image">Screenshot / image</option>
                            <option value="video">Video clip</option>
                            <option value="document">Document (PDF)</option>
                            <option value="text">Text explanation</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="evidence_description">Description</label>
                        <input type="text" id="evidence_description" name="description" maxlength="2000" placeholder="What does this evidence show?">
                    </div>
                    <div class="field">
                        <label for="evidence_file">File (image / video / PDF, max {{ \App\Models\DisputeEvidence::MAX_KB / 1024 }} MB)</label>
                        <input type="file" id="evidence_file" name="evidence_file">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm mt-2">Add Evidence</button>
                </form>
            @endif
        </section>
    </div>

    <section class="card" aria-labelledby="timeline-heading">
        <h3 id="timeline-heading">🕓 Timeline</h3>
        @if ($dispute->events->isEmpty())
            <p class="muted">No events recorded.</p>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Dispute event timeline</caption>
                    <thead>
                        <tr>
                            <th scope="col">When</th>
                            <th scope="col">Actor</th>
                            <th scope="col">Event</th>
                            <th scope="col">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($dispute->events as $event)
                            <tr>
                                <td class="muted" style="font-size: .8rem">{{ $event->created_at->format('d M, h:i A') }}</td>
                                <td>{{ $event->actor?->name ?? 'System' }}</td>
                                <td><span class="tag">{{ $event->event }}</span></td>
                                <td class="muted" style="font-size: .8rem">{{ json_encode($event->metadata) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @auth
        @if ($dispute->isActionable())
            <section class="card" aria-labelledby="moderation-heading">
                @if ($isStaff)
                    <h3 id="moderation-heading">🛡 Moderation</h3>
                    <div class="grid cols-2">
                        @if ($dispute->status === \App\Models\Dispute::STATUS_OPEN)
                            <form method="POST" action="{{ route('matches.disputes.review', [$tournament, $match, $dispute]) }}">
                                @csrf
                                <button type="submit" class="btn btn-cyan btn-sm">Mark Under Review</button>
                            </form>
                        @endif

                        <form method="POST" action="{{ route('matches.disputes.assign', [$tournament, $match, $dispute]) }}">
                            @csrf
                            <div class="row" style="align-items: flex-end">
                                <div class="field grow">
                                    <label for="reviewer_id">Assign reviewer</label>
                                    <select id="reviewer_id" name="reviewer_id" required>
                                        <option value="">— select —</option>
                                        @foreach ($reviewers as $reviewer)
                                            <option value="{{ $reviewer->id }}" @selected($dispute->assigned_to === $reviewer->id)>{{ $reviewer->name }} ({{ $reviewer->role }})</option>
                                        @endforeach
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-sm">Assign</button>
                            </div>
                        </form>
                    </div>

                    <hr style="border-color: var(--line); margin: 16px 0">
                    <h3>⚖ Resolve</h3>
                    <form method="POST" action="{{ route('matches.disputes.resolve', [$tournament, $match, $dispute]) }}">
                        @csrf
                        <div class="field">
                            <label for="winner_team_id">Confirmed winner</label>
                            <select id="winner_team_id" name="winner_team_id" required>
                                @if ($match->team1) <option value="{{ $match->team1->id }}" @selected($match->winner_team_id === $match->team1_id)>{{ $match->team1->name }}</option> @endif
                                @if ($match->team2) <option value="{{ $match->team2->id }}" @selected($match->winner_team_id === $match->team2_id)>{{ $match->team2->name }}</option> @endif
                            </select>
                        </div>
                        <div class="field">
                            <label for="resolution">Resolution reason (required, audited)</label>
                            <textarea id="resolution" name="resolution" rows="3" maxlength="5000" placeholder="Explain the decision." required></textarea>
                        </div>

                        @if ($match->scores->isNotEmpty())
                            <fieldset>
                                <legend>Score corrections (optional — recalculated by the scoring engine)</legend>
                                @foreach ($match->scores as $score)
                                    <div class="row" style="align-items: center; margin-bottom: 6px">
                                        <span class="muted" style="min-width: 140px; font-size: .85rem">{{ $score->team->name }}</span>
                                        <input type="hidden" name="corrections[{{ $loop->index }}][team_id]" value="{{ $score->team_id }}">
                                        <label for="corr-kills-{{ $loop->index }}" class="sr-only">Kills for {{ $score->team->name }}</label>
                                        <input type="number" id="corr-kills-{{ $loop->index }}" name="corrections[{{ $loop->index }}][kills]" value="{{ $score->kills }}" min="0" placeholder="Kills" style="max-width: 90px">
                                        <label for="corr-place-{{ $loop->index }}" class="sr-only">Placement for {{ $score->team->name }}</label>
                                        <input type="number" id="corr-place-{{ $loop->index }}" name="corrections[{{ $loop->index }}][placement]" value="{{ $score->placement }}" min="1" max="{{ \App\Models\ScoringRule::MAX_PLACEMENT }}" placeholder="Place" style="max-width: 90px">
                                    </div>
                                @endforeach
                                <p class="help-text">Changes are recalculated with the score's original rule version; totals are never trusted from the client.</p>
                            </fieldset>
                        @endif

                        <div class="row mt-3">
                            <button type="submit" class="btn btn-green btn-sm">Resolve Dispute</button>
                        </div>
                    </form>

                    <hr style="border-color: var(--line); margin: 16px 0">
                    <div class="row" style="align-items: flex-end">
                        <form method="POST" action="{{ route('matches.disputes.reject', [$tournament, $match, $dispute]) }}" class="grow">
                            @csrf
                            <div class="field">
                                <label for="reject-reason">Rejection reason (required)</label>
                                <div class="row" style="gap: 8px">
                                    <input type="text" id="reject-reason" name="resolution" maxlength="5000" placeholder="Why the result stands" required>
                                    <button type="submit" class="btn btn-sm" style="border-color: var(--amber); color: var(--amber)">Reject</button>
                                </div>
                            </div>
                        </form>
                        <form method="POST" action="{{ route('matches.disputes.cancel', [$tournament, $match, $dispute]) }}">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-ghost">Cancel</button>
                        </form>
                    </div>
                @elseif ($isOpener && $dispute->status === \App\Models\Dispute::STATUS_OPEN)
                    <form method="POST" action="{{ route('matches.disputes.cancel', [$tournament, $match, $dispute]) }}">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-ghost">Cancel my dispute</button>
                    </form>
                @endif
            </section>
        @endif
    @endauth
@endsection
