@extends('layouts.app')
@section('title', '#' . $ticket->id . ' — Support — FF Arena')
@section('content')
    <header class="page-head">
        <div class="row-between">
            <div>
                <h1 class="page-title">🎫 {{ $ticket->subject }}</h1>
                <div class="row muted">
                    #{{ $ticket->id }} · {{ $ticket->user?->name ?? 'Unknown' }}
                    <x-status-pill :status="$ticket->statusPill()" :label="$ticket->statusLabel()" />
                    <x-status-pill :status="$ticket->priority" :label="$ticket->priority" />
                    · {{ $ticket->categoryLabel() }}
                </div>
            </div>
            <a href="{{ route('admin.support.index') }}" class="btn btn-sm">← Queue</a>
        </div>
    </header>

    <div class="grid cols-2">
        <div>
            <section class="card" style="padding: 0" aria-labelledby="conversation-heading">
                <h3 id="conversation-heading" class="sr-only">Conversation</h3>
                <div class="card-header" style="margin: 0; border-radius: 0">
                    <h3 style="margin: 0">Conversation</h3>
                </div>
                <div id="messages" style="max-height: 460px; overflow-y: auto; padding: 14px 16px">
                    @foreach ($messages as $message)
                        <div style="margin-bottom: 12px">
                            <div class="muted" style="font-size: .75rem">
                                {{ $message->author?->name ?? 'System' }}
                                @if ($message->author?->isStaff()) <span class="tag" style="color: var(--cyan)">(staff)</span> @endif
                                · {{ optional($message->created_at)->format('d M y H:i') }}
                            </div>
                            <div style="white-space: pre-wrap">{{ $message->body }}</div>
                        </div>
                    @endforeach
                </div>
                <form method="POST" action="{{ route('admin.support.reply', $ticket) }}" style="padding: 14px 16px; border-top: 1px solid var(--line)">
                    @csrf
                    <div class="field">
                        <label for="reply-body">Reply as staff</label>
                        <textarea id="reply-body" name="body" rows="3" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-cyan btn-sm mt-1">Send reply</button>
                </form>
            </section>

            <section class="card mt-4" aria-labelledby="internal-notes-heading">
                <h3 id="internal-notes-heading">🔒 Internal notes</h3>
                <div style="max-height: 240px; overflow-y: auto">
                    @foreach ($internalNotes as $note)
                        <div style="border-bottom: 1px solid var(--line); padding: 8px 0">
                            <div class="muted" style="font-size: .75rem">{{ $note->author?->name ?? 'Staff' }} · {{ optional($note->created_at)->format('d M y H:i') }}</div>
                            <div style="white-space: pre-wrap">{{ $note->body }}</div>
                        </div>
                    @endforeach
                </div>
                <form method="POST" action="{{ route('admin.support.note', $ticket) }}" class="mt-3">
                    @csrf
                    <div class="field">
                        <label for="note-body" class="sr-only">Internal note</label>
                        <textarea id="note-body" name="body" rows="2" required placeholder="Staff-only note (never visible to the user)"></textarea>
                    </div>
                    <button type="submit" class="btn btn-sm mt-1">Add note</button>
                </form>
            </section>
        </div>

        <div>
            <section class="card" aria-labelledby="assign-heading">
                <h3 id="assign-heading">Assign</h3>
                <form method="POST" action="{{ route('admin.support.assign', $ticket) }}">
                    @csrf
                    <div class="field">
                        <label for="assignee_id">Assignee</label>
                        <select id="assignee_id" name="assignee_id">
                            <option value="">— Unassigned —</option>
                            @foreach ($staff as $member)
                                <option value="{{ $member->id }}" {{ $ticket->assigned_to === $member->id ? 'selected' : '' }}>{{ $member->name }} ({{ $member->role }})</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="btn btn-cyan btn-sm mt-1">Save assignment</button>
                </form>
            </section>

            <section class="card mt-4" aria-labelledby="status-heading">
                <h3 id="status-heading">Status</h3>
                <form method="POST" action="{{ route('admin.support.status', $ticket) }}">
                    @csrf
                    <div class="field">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            @foreach (\App\Models\SupportTicket::STATUSES as $status)
                                <option value="{{ $status }}" {{ $ticket->status === $status ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="status-note">Optional note (visible to the user)</label>
                        <textarea id="status-note" name="note" rows="2"></textarea>
                    </div>
                    <button type="submit" class="btn btn-cyan btn-sm mt-1">Change status</button>
                </form>
            </section>

            <section class="card mt-4" aria-labelledby="meta-heading">
                <h3 id="meta-heading">Meta</h3>
                <div class="muted" style="font-size: .85rem; line-height: 1.9">
                    Created {{ optional($ticket->created_at)->format('d M Y H:i') }}<br>
                    Resolved {{ $ticket->resolved_at ? optional($ticket->resolved_at)->format('d M Y H:i') : '—' }}<br>
                    Closed {{ $ticket->closed_at ? optional($ticket->closed_at)->format('d M Y H:i') : '—' }}<br>
                    Reopened {{ $ticket->reopened_count }}×
                    @if ($ticket->tournament)
                        <br>Tournament: <a href="{{ route('tournaments.show', $ticket->tournament) }}">{{ $ticket->tournament->name }}</a>
                    @endif
                </div>
            </section>
        </div>
    </div>
@endsection
