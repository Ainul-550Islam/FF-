<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Services\AuditLogService;
use App\Services\BracketService;
use App\Services\TournamentLifecycleService;
use App\Services\TournamentParticipationService;
use App\Support\Seo;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TournamentController extends Controller
{
    public function __construct(
        protected TournamentLifecycleService $lifecycle,
        protected TournamentParticipationService $participation,
        protected AuditLogService $audit,
    ) {}

    public function index(Request $request)
    {
        // Draft and cancelled tournaments are not shown publicly.
        $query = Tournament::with('organizer')
            ->withCount('confirmedTeams')
            ->whereIn('status', Tournament::PUBLIC_STATUSES);

        // Discovery filters (Phase 17) — additive, presentation-only. The
        // default listing behaviour is unchanged when no filters are given.
        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            // Escape LIKE wildcards so user input can never broaden the match.
            $escaped = addcslashes($search, '%_\\');
            $query->where(function ($builder) use ($escaped) {
                $builder->where('name', 'like', "%{$escaped}%")
                    ->orWhere('map', 'like', "%{$escaped}%");
            });
        }

        $status = (string) $request->query('status', '');
        if ($status !== '' && in_array($status, Tournament::PUBLIC_STATUSES, true)) {
            $query->where('status', $status);
        }

        $gameMode = (string) $request->query('game_mode', '');
        if (in_array($gameMode, ['squad', 'duo', 'solo'], true)) {
            $query->where('game_mode', $gameMode);
        }

        $tournaments = $query->orderByDesc('created_at')->paginate(12)->withQueryString();

        app(Seo::class)
            ->title('Free Fire Tournaments in Bangladesh — '.(string) config('app.name', 'FF Arena'))
            ->description('Browse Free Fire tournaments in Bangladesh — entry fees, prize pools, game modes and team slots at a glance.')
            ->canonical(route('tournaments.index'))
            ->indexable();

        return view('tournaments.index', compact('tournaments', 'search', 'status', 'gameMode'));
    }

    public function show(Tournament $tournament)
    {
        $tournament->load([
            'organizer',
            'confirmedTeams',
            'matches' => fn ($q) => $q->orderBy('bracket')->orderBy('round')->orderBy('match_no'),
        ]);

        $myTeam = null;
        if (auth()->check()) {
            $myTeam = $tournament->teams()->where('captain_id', auth()->id())->first();
        }

        // Waitlist is shown to organizers/admin (and positions are shown to
        // the relevant captains via their own team's waitlistPosition()).
        $waitlist = null;
        if (auth()->check() && (auth()->user()->isAdmin() || auth()->user()->isOrganizer())) {
            $waitlist = $tournament->waitlistedTeams()
                ->orderBy('waitlisted_at')
                ->orderBy('id')
                ->get();
        }

        $this->applyTournamentSeo($tournament);

        return view('tournaments.show', compact('tournament', 'myTeam', 'waitlist'));
    }

    /**
     * Phase 17 — per-tournament search & social metadata. Only public status
     * pages are indexable (draft/cancelled tournaments render the noindex
     * default). Structured data describes the visible page content only.
     */
    private function applyTournamentSeo(Tournament $tournament): void
    {
        $siteName = (string) config('app.name', 'FF Arena');
        $organizerName = $tournament->organizer->name ?? $siteName;

        $fee = '৳'.number_format($tournament->entry_fee, 0, '.', ',');
        $prize = '৳'.number_format($tournament->prize_pool, 0, '.', ',');

        app(Seo::class)
            ->title($tournament->name.' — '.$siteName)
            ->description(sprintf(
                '%s — %s %s tournament on %s. Entry %s, prize pool %s, %d team slots. Hosted by %s.',
                $tournament->name,
                strtoupper($tournament->game_mode),
                $tournament->map,
                $siteName,
                $fee,
                $prize,
                $tournament->team_slots,
                $organizerName
            ))
            ->canonical(route('tournaments.show', $tournament))
            ->indexable(in_array($tournament->status, Tournament::PUBLIC_STATUSES, true))
            ->ogType('article');

        // Structured data only when it accurately represents the visible
        // content: a real, scheduled public tournament (never cancelled).
        if ($tournament->status !== Tournament::STATUS_CANCELLED) {
            $event = [
                '@context' => 'https://schema.org',
                '@type' => 'Event',
                'name' => $tournament->name,
                'url' => route('tournaments.show', $tournament),
                'eventAttendanceMode' => 'https://schema.org/OnlineEventAttendanceMode',
                'eventStatus' => match ($tournament->status) {
                    Tournament::STATUS_FINISHED => 'https://schema.org/EventCompleted',
                    Tournament::STATUS_LIVE => 'https://schema.org/EventScheduled',
                    default => 'https://schema.org/EventScheduled',
                },
                'organizer' => ['@type' => 'Organization', 'name' => $organizerName],
                'location' => ['@type' => 'VirtualLocation', 'name' => 'Online — Free Fire custom room'],
                'description' => Str::limit((string) ($tournament->rules ?: $tournament->name), 300),
            ];

            if ($tournament->starts_at !== null) {
                $event['startDate'] = $tournament->starts_at->toIso8601String();
            }

            if ($tournament->entry_fee > 0) {
                $event['offers'] = [
                    '@type' => 'Offer',
                    'price' => (string) $tournament->entry_fee,
                    'priceCurrency' => 'BDT',
                    'url' => route('tournaments.show', $tournament),
                ];
            }

            app(Seo::class)->jsonLd($event);
        }
    }

    public function create()
    {
        $this->authorize('create', Tournament::class);

        return view('tournaments.create');
    }

    public function store(Request $request)
    {
        $this->authorize('create', Tournament::class);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'game_mode' => 'required|in:squad,duo,solo',
            'map' => 'required|string|max:60',
            'entry_fee' => 'required|numeric|min:0',
            'prize_pool' => 'required|numeric|min:0',
            'team_slots' => 'required|in:8,16,32',
            'team_size' => 'required|integer|min:1|max:6',
            'rules' => 'nullable|string',
            'starts_at' => 'required|date|after:now',
            'check_in_starts_at' => 'nullable|date|required_with:check_in_ends_at',
            'check_in_ends_at' => 'nullable|date|after:check_in_starts_at|before_or_equal:starts_at',
            'format' => 'nullable|in:single_elim,double_elim',
            'dispute_window_hours' => 'nullable|integer|min:0|max:720',
        ]);

        // organizer_id, slug and status are server-controlled — a client can
        // never inject them. New tournaments always start as DRAFT.
        $tournament = new Tournament;
        $tournament->organizer_id = $request->user()->id;
        $tournament->name = $data['name'];
        $tournament->slug = Str::slug($data['name']).'-'.Str::random(6);
        $tournament->game_mode = $data['game_mode'];
        $tournament->map = $data['map'];
        $tournament->entry_fee = $data['entry_fee'];
        $tournament->prize_pool = $data['prize_pool'];
        $tournament->team_slots = $data['team_slots'];
        $tournament->team_size = $data['team_size'];
        $tournament->rules = $data['rules'] ?? null;
        $tournament->starts_at = $data['starts_at'];
        $tournament->check_in_starts_at = $data['check_in_starts_at'] ?? null;
        $tournament->check_in_ends_at = $data['check_in_ends_at'] ?? null;
        $tournament->format = $data['format'] ?? Tournament::FORMAT_SINGLE_ELIM;
        $tournament->dispute_window_hours = $data['dispute_window_hours'] ?? 24;
        $tournament->status = Tournament::STATUS_DRAFT;
        $tournament->save();

        return redirect()
            ->route('tournaments.show', $tournament)
            ->with('success', 'Tournament created as draft. Publish it to open registration.');
    }

    public function edit(Tournament $tournament)
    {
        $this->authorize('update', $tournament);

        return view('tournaments.edit', compact('tournament'));
    }

    public function update(Request $request, Tournament $tournament)
    {
        $this->authorize('update', $tournament);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'game_mode' => 'required|in:squad,duo,solo',
            'map' => 'required|string|max:60',
            'entry_fee' => 'required|numeric|min:0',
            'prize_pool' => 'required|numeric|min:0',
            'team_slots' => 'required|in:8,16,32',
            'team_size' => 'required|integer|min:1|max:6',
            'rules' => 'nullable|string',
            'starts_at' => 'required|date',
            'check_in_starts_at' => 'nullable|date|required_with:check_in_ends_at',
            'check_in_ends_at' => 'nullable|date|after:check_in_starts_at',
            'format' => 'nullable|in:single_elim,double_elim',
            'dispute_window_hours' => 'nullable|integer|min:0|max:720',
        ]);

        // fill() only touches mass-assignable fields, so a client cannot
        // tamper with organizer_id, slug or status through this endpoint.
        $tournament->fill($data)->save();

        return redirect()->route('tournaments.show', $tournament)->with('success', 'Tournament updated.');
    }

    public function publish(Tournament $tournament)
    {
        $this->authorize('publish', $tournament);

        try {
            $this->lifecycle->publish($tournament);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->recordQuietly(auth()->user(), 'tournament.published', 'tournament', $tournament->id, [
            'tournament_id' => $tournament->id,
        ]);

        return back()->with('success', 'Tournament published — registration is now open.');
    }

    public function closeRegistration(Tournament $tournament)
    {
        $this->authorize('closeRegistration', $tournament);

        try {
            $this->lifecycle->closeRegistration($tournament);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->recordQuietly(auth()->user(), 'tournament.registration_closed', 'tournament', $tournament->id, [
            'tournament_id' => $tournament->id,
        ]);

        return back()->with('success', 'Registration closed.');
    }

    public function start(Tournament $tournament, BracketService $bracket)
    {
        $this->authorize('start', $tournament);

        try {
            $count = $this->lifecycle->start($tournament, $bracket);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->recordQuietly(auth()->user(), 'tournament.started', 'tournament', $tournament->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['matches' => $count],
        ]);

        return back()->with('success', "Bracket generated with {$count} matches. Tournament is LIVE!");
    }

    public function complete(Tournament $tournament)
    {
        $this->authorize('complete', $tournament);

        try {
            $this->lifecycle->complete($tournament);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->recordQuietly(auth()->user(), 'tournament.completed', 'tournament', $tournament->id, [
            'tournament_id' => $tournament->id,
        ]);

        return back()->with('success', 'Tournament marked as finished. Congratulations to the winners!');
    }

    public function cancel(Tournament $tournament)
    {
        $this->authorize('cancel', $tournament);

        try {
            $this->lifecycle->cancel($tournament);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->recordQuietly(auth()->user(), 'tournament.cancelled', 'tournament', $tournament->id, [
            'tournament_id' => $tournament->id,
        ]);

        return back()->with('success', 'Tournament cancelled.');
    }

    /**
     * Mark confirmed-but-unchecked-in teams as no-shows (after the check-in
     * window closes) and promote waitlisted teams into the freed slots.
     */
    public function markNoShows(Tournament $tournament)
    {
        $this->authorize('update', $tournament);

        try {
            $result = $this->participation->markNoShowsAndPromote($tournament);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = "Marked {$result['no_shows']} team(s) as no-show.";

        if ($result['promoted'] > 0) {
            $message .= " Promoted {$result['promoted']} team(s) from the waitlist.";
        }

        $this->audit->recordQuietly(auth()->user(), 'tournament.noshows', 'tournament', $tournament->id, [
            'tournament_id' => $tournament->id,
            'metadata' => $result,
        ]);

        return back()->with('success', $message);
    }

    /**
     * Promote the next waitlisted team into a free slot.
     */
    public function promoteWaitlisted(Tournament $tournament)
    {
        $this->authorize('update', $tournament);

        try {
            $team = $this->participation->promoteNext($tournament);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->recordQuietly(auth()->user(), 'tournament.waitlist_promoted', 'tournament', $tournament->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['team' => $team->name],
        ]);

        return back()->with('success', "{$team->name} promoted from the waitlist.");
    }
}
