@extends('layouts.app')

@section('content')
    <header class="page-head">
        <div class="row-between">
            <h1 class="page-title">🔔 Notifications</h1>
            @if ($items->isNotEmpty())
                <form method="POST" action="{{ route('notifications.readAll') }}">
                    @csrf
                    <button class="btn btn-sm btn-cyan">Mark all as read</button>
                </form>
            @endif
        </div>
    </header>

    <section class="card" aria-label="Your notifications">
        @forelse ($items as $item)
            <article class="row" style="align-items: flex-start; gap: 14px; padding: 14px 0; border-bottom: 1px solid var(--line); {{ $item->isRead() ? 'opacity: .55' : '' }}">
                <div class="grow">
                    <div class="row" style="gap: 10px">
                        @unless ($item->isRead())
                            <x-status-pill status="live" label="New" />
                        @endunless
                        <strong>{{ $item->title }}</strong>
                        <span class="muted" style="font-size: .8rem">{{ $item->typeLabel() }}</span>
                    </div>
                    <p class="muted mt-2" style="font-size: .9rem">{{ $item->body }}</p>
                    <div class="row" style="gap: 12px">
                        @if ($item->link)
                            <a href="{{ $item->link }}" style="font-size: .85rem">View →</a>
                        @endif
                        <span class="muted" style="font-size: .8rem">{{ $item->created_at?->format('d M Y, h:i A') }}</span>
                    </div>
                </div>
                @unless ($item->isRead())
                    <form method="POST" action="{{ route('notifications.read', $item) }}">
                        @csrf
                        <button class="btn btn-sm">Mark read</button>
                    </form>
                @endunless
            </article>
        @empty
            <x-empty-state title="You have no notifications yet" icon="🔕">
                When something happens — scores, disputes, payouts — you'll see it here.
            </x-empty-state>
        @endforelse

        @if ($items->hasPages())
            <div class="mt-4">{{ $items->links() }}</div>
        @endif
    </section>
@endsection
