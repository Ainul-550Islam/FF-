@extends('layouts.app')

@section('content')
    <section class="hero" aria-labelledby="hero-title">
        <h1 id="hero-title">Bangladesh's <span class="tag">Free Fire</span> Tournament Platform</h1>
        <p class="page-subtitle">
            Organizers run fair tournaments. Players pay entry with bKash, get auto brackets,
            submit scores with proof — and winners get paid. No chaos, no cheating.
        </p>
        <div class="row mt-4">
            <a href="{{ route('tournaments.index') }}" class="btn btn-primary btn-lg">Browse Tournaments</a>
            @guest
                <a href="{{ route('register') }}" class="btn btn-cyan">Join as Player</a>
                <a href="{{ route('register') }}" class="btn">Become an Organizer</a>
            @endguest
        </div>
    </section>

    <section aria-labelledby="featured-heading">
        <h2 id="featured-heading">🔥 Live &amp; Upcoming Tournaments</h2>

        <div class="grid cols-3">
            @forelse ($tournaments as $t)
                <article class="card">
                    <div class="row-between">
                        <x-status-pill :status="$t->status" />
                        <span class="muted">{{ strtoupper($t->game_mode) }} · {{ $t->map }}</span>
                    </div>
                    <h3 class="mt-3">
                        <a href="{{ route('tournaments.show', $t) }}">{{ $t->name }}</a>
                    </h3>
                    <p class="muted mb-3" style="font-size: .85rem">
                        by {{ $t->organizer->name ?? 'Organizer' }}
                    </p>
                    <dl class="row">
                        <div class="stat">
                            <dt class="label">Entry</dt>
                            <dd class="num">৳{{ number_format($t->entry_fee) }}</dd>
                        </div>
                        <div class="stat">
                            <dt class="label">Prize</dt>
                            <dd class="num">৳{{ number_format($t->prize_pool) }}</dd>
                        </div>
                        <div class="stat">
                            <dt class="label">Teams</dt>
                            <dd class="num">{{ $t->confirmed_teams_count }}/{{ $t->team_slots }}</dd>
                        </div>
                    </dl>
                    <div class="mt-3">
                        <a href="{{ route('tournaments.show', $t) }}"
                           class="btn btn-sm {{ $t->status === 'open' ? 'btn-green' : '' }}">
                            {{ $t->status === 'open' ? 'Register →' : 'View Details' }}
                        </a>
                    </div>
                </article>
            @empty
                <x-empty-state title="No tournaments yet" icon="🏆">
                    Be the first organizer to publish a tournament on FF Arena.
                </x-empty-state>
            @endforelse
        </div>
    </section>
@endsection
