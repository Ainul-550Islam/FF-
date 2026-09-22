@extends('layouts.app')
@section('title', 'Open Dispute — ' . $tournament->name)
@section('content')
    <header class="page-head">
        <nav class="breadcrumbs" aria-label="Breadcrumb">
            <li><a href="{{ route('home') }}">Home</a></li>
            <li><a href="{{ route('tournaments.show', $tournament) }}">{{ $tournament->name }}</a></li>
            <li><a href="{{ route('matches.show', [$tournament, $match]) }}">Match #{{ $match->match_no }}</a></li>
            <li><span aria-current="page">Open Dispute</span></li>
        </nav>
        <h1 class="page-title">🚩 Open a Dispute</h1>
        <p class="page-subtitle">
            {{ $match->team1?->name ?? 'TBD' }} vs {{ $match->team2?->name ?? 'TBD' }} —
            {{ $match->roundLabel() }} #{{ $match->match_no }}
        </p>
    </header>

    @if ($existing)
        <div class="alert alert-error" role="alert">
            <span aria-hidden="true">✕</span>
            <span>
                This match already has an open dispute.
                <a href="{{ route('matches.disputes.show', [$tournament, $match, $existing]) }}">View it →</a>
            </span>
        </div>
    @endif

    <div class="card" style="max-width: 720px">
        <form method="POST" action="{{ route('matches.disputes.store', [$tournament, $match]) }}" enctype="multipart/form-data">
            @csrf
            <div class="field">
                <label for="category">Category</label>
                <select id="category" name="category" required>
                    <option value="">Select a reason…</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category }}" @selected(old('category') === $category)>
                            {{ ucwords(str_replace('_', ' ', $category)) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="5" maxlength="5000"
                          placeholder="Explain what happened and why you believe the result is wrong." required>{{ old('description') }}</textarea>
            </div>

            @if (auth()->user()->isAdmin() || auth()->user()->isModerator() || auth()->id() === $tournament->organizer_id)
                <div class="field">
                    <label for="team_id">Disputed team (optional — staff only)</label>
                    <select id="team_id" name="team_id">
                        <option value="">— none (staff dispute) —</option>
                        @if ($match->team1) <option value="{{ $match->team1->id }}">{{ $match->team1->name }}</option> @endif
                        @if ($match->team2) <option value="{{ $match->team2->id }}">{{ $match->team2->name }}</option> @endif
                    </select>
                </div>
            @elseif ($userTeam)
                <p class="muted mt-3" style="font-size: .85rem">
                    Disputing on behalf of: <strong class="tag">{{ $userTeam->name }}</strong>
                </p>
                <input type="hidden" name="team_id" value="{{ $userTeam->id }}">
            @endif

            <hr style="border-color: var(--line); margin: 18px 0">
            <h3>📎 Evidence (optional)</h3>

            <div class="field">
                <label for="evidence_type">Evidence type</label>
                <select id="evidence_type" name="evidence_type">
                    <option value="">— none —</option>
                    <option value="image" @selected(old('evidence_type') === 'image')>Screenshot / image</option>
                    <option value="video" @selected(old('evidence_type') === 'video')>Video clip</option>
                    <option value="document" @selected(old('evidence_type') === 'document')>Document (PDF)</option>
                    <option value="text" @selected(old('evidence_type') === 'text')>Text explanation</option>
                </select>
            </div>

            <div class="field">
                <label for="evidence_description">Evidence description</label>
                <input type="text" id="evidence_description" name="evidence_description" maxlength="2000"
                       placeholder="What does this evidence show?" value="{{ old('evidence_description') }}">
            </div>

            <div class="field">
                <label for="evidence_file">File (image / video / PDF, max {{ \App\Models\DisputeEvidence::MAX_KB / 1024 }} MB)</label>
                <input type="file" id="evidence_file" name="evidence_file">
            </div>

            <button type="submit" class="btn btn-primary mt-3">Open Dispute</button>
        </form>
    </div>
@endsection
