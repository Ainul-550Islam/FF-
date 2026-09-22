@extends('layouts.app')
@section('title', '#' . $ticket->id . ' — ' . $ticket->subject . ' — FF Arena')
@section('content')
    <header class="page-head">
        <div class="row-between">
            <div>
                <h1 class="page-title">🎫 {{ $ticket->subject }}</h1>
                <div class="row muted">
                    #{{ $ticket->id }}
                    <x-status-pill :status="$ticket->statusPill()" :label="$ticket->statusLabel()" />
                    <x-status-pill :status="$ticket->priority" :label="$ticket->priority" />
                    · {{ $ticket->categoryLabel() }}
                </div>
            </div>
            <a href="{{ route('support.index') }}" class="btn btn-sm">← My tickets</a>
        </div>
    </header>

    <div class="grid cols-2">
        <section class="card" style="padding: 0" aria-labelledby="conversation-heading">
            <h3 id="conversation-heading" class="sr-only">Conversation</h3>
            <div class="card-header" style="margin: 0; border-radius: 0">
                <h3 style="margin: 0">Conversation</h3>
            </div>
            <div id="messages" class="support-messages"
                 data-url="{{ route('support.tickets.messages', $ticket) }}"
                 data-latest="{{ $latestId }}"
                 data-status="{{ $ticket->status }}"
                 aria-live="polite"
                 style="max-height: 440px; overflow-y: auto; padding: 14px 16px">
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

            @if ($ticket->isOpen())
                <form method="POST" action="{{ route('support.tickets.reply', $ticket) }}" style="padding: 14px 16px; border-top: 1px solid var(--line)">
                    @csrf
                    <div class="field">
                        <label for="reply-body">Reply</label>
                        <textarea id="reply-body" name="body" rows="3" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-cyan btn-sm mt-1">Send reply</button>
                </form>
            @else
                <div style="padding: 14px 16px; border-top: 1px solid var(--line)">
                    <form method="POST" action="{{ route('support.tickets.reopen', $ticket) }}">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-cyan">Reopen ticket</button>
                    </form>
                </div>
            @endif
        </section>

        <section class="card" aria-labelledby="details-heading">
            <h3 id="details-heading">Details</h3>
            <div class="muted" style="font-size: .85rem; line-height: 1.9">
                Created {{ optional($ticket->created_at)->format('d M Y H:i') }}<br>
                Last activity {{ optional($ticket->last_activity_at)->diffForHumans() }}
                @if ($ticket->resolved_at)
                    <br>Resolved {{ optional($ticket->resolved_at)->format('d M Y H:i') }}
                @endif
            </div>

            @if ($ticket->isOpen())
                <form method="POST" action="{{ route('support.tickets.close', $ticket) }}" class="mt-4">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-danger">Close ticket</button>
                </form>
            @endif
        </section>
    </div>

    <script>
    (function () {
        var list = document.getElementById('messages');
        if (!list || !list.dataset.url) { return; }

        var url = list.dataset.url;
        var latest = parseInt(list.dataset.latest || '0', 10);
        var status = list.dataset.status;
        var interval = 8000;
        var currentInterval = interval;
        var maxInterval = 120000;
        var timer = null;

        function tick() {
            if (document.hidden) { schedule(); return; }

            fetch(url + '?after=' + latest, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { if (!r.ok) { throw new Error('http ' + r.status); } return r.json(); })
                .then(function (d) {
                    currentInterval = interval;
                    var items = d.messages || [];
                    items.forEach(function (m) {
                        latest = Math.max(latest, m.id);
                        var block = document.createElement('div');
                        block.style.marginBottom = '12px';
                        var meta = document.createElement('div');
                        meta.style.cssText = 'font-size:12px;color:var(--muted)';
                        meta.textContent = (m.author || 'System') + (m.staff ? ' (staff)' : '') + ' · just now';
                        var body = document.createElement('div');
                        body.style.whiteSpace = 'pre-wrap';
                        body.textContent = m.body;
                        block.appendChild(meta);
                        block.appendChild(body);
                        list.appendChild(block);
                        list.scrollTop = list.scrollHeight;
                    });
                    if (d.status && d.status !== status) {
                        window.location.reload();
                    }
                })
                .catch(function () {
                    currentInterval = Math.min(currentInterval * 2, maxInterval);
                });
            schedule();
        }

        function schedule() {
            clearTimeout(timer);
            timer = setTimeout(tick, currentInterval);
        }

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) { clearTimeout(timer); tick(); }
        });

        schedule();
    })();
    </script>
@endsection
