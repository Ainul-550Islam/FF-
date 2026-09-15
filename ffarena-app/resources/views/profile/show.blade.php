@extends('layouts.app')

@section('content')
    <div class="card" style="max-width: 640px; margin: 40px auto">
        @if (! $profile['visible'])
            <h2>{{ $user->name }}</h2>
            <p class="muted">This profile is private.</p>
        @else
            <div class="row" style="align-items: center">
                @if (! empty($profile['avatar']))
                    <img src="{{ $profile['avatar'] }}" alt=""
                         class="avatar" style="width: 84px; height: 84px">
                @else
                    <span class="avatar-fallback" aria-hidden="true"
                          style="width: 84px; height: 84px; font-size: 1.9rem">
                        {{ strtoupper(mb_substr((string) $profile['name'], 0, 1)) }}
                    </span>
                @endif
                <div class="grow">
                    <h2 class="mb-1">{{ $profile['name'] }}</h2>
                    @if (! empty($profile['username']))
                        <p class="muted mb-2">@{{ $profile['username'] }}</p>
                    @endif
                    <x-status-pill status="confirmed" :label="ucfirst((string) $profile['role'])" />
                </div>
            </div>

            @if (! empty($profile['bio']))
                <p class="mt-4">{{ $profile['bio'] }}</p>
            @endif

            <div class="row muted mt-4">
                @if (! empty($profile['country']))
                    <span>🌍 {{ $profile['country'] }}{{ ! empty($profile['region']) ? ' · ' . $profile['region'] : '' }}</span>
                @endif
                @if (! empty($profile['joined_at']))
                    <span>Joined {{ $profile['joined_at'] }}</span>
                @endif
            </div>
        @endif

        @auth
            @if (auth()->id() === $user->id)
                <div class="mt-4">
                    <a href="{{ route('profile.edit') }}" class="btn btn-cyan btn-sm">Edit profile</a>
                </div>
            @endif
        @endauth
    </div>
@endsection
