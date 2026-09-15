@php /** @var \App\Models\GameMatch $match */ @endphp
<div class="bracket-match">
    <a href="{{ route('matches.show', [$tournament, $match]) }}"
       aria-label="Match: {{ $match->team1?->name ?? 'TBD' }} vs {{ $match->team2?->name ?? 'TBD' }} — {{ $match->statusPill() }}">
        <div class="bracket-team {{ $match->winner_team_id === $match->team1_id ? 'win' : '' }}">
            <span>{{ $match->team1?->name ?? 'TBD' }}</span>
            @if ($match->isBye() && $match->team1_id !== null && $match->team2_id === null)
                <span class="muted" style="font-size: .7rem">(bye)</span>
            @endif
        </div>
        <div class="divider"></div>
        <div class="bracket-team {{ $match->winner_team_id === $match->team2_id ? 'win' : '' }}">
            <span>{{ $match->team2?->name ?? 'TBD' }}</span>
            @if ($match->isBye() && $match->team2_id !== null && $match->team1_id === null)
                <span class="muted" style="font-size: .7rem">(bye)</span>
            @endif
        </div>
        <div style="font-size: .7rem; margin-top: 4px; text-align: center">
            @if ($match->isBye())
                <x-status-pill status="bye" />
            @elseif ($match->isCompleted())
                <x-status-pill status="finished" label="Done" />
            @elseif ($match->isDisputed())
                <x-status-pill status="disputed" />
            @elseif ($match->status === 'live')
                <x-status-pill status="live" />
            @else
                <x-status-pill status="ready" />
            @endif
        </div>
    </a>
</div>
