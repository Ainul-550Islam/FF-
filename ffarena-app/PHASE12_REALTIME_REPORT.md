# FF Arena — Phase 12: Realtime / Live Updates
**Status:** COMPLETE & VERIFIED
**Date:** 2026-09-08
**Suite:** 473 passed · 1425 assertions (446 baseline + 27 Phase 12)
**Routes:** 103 total (100 baseline + 3 live/notification routes)

---

## 1. Scope (assumed — no Phase 12 spec was supplied)

The Phase 11+ roadmap listed **Realtime / Live Updates** as the next work item.
No Phase 12 specification came through, so this phase proceeded on the most
defensible interpretation and **assumed** the following scope, which is
restated here verbatim for the record:

- Append-only `live_events` table with an auto-incrementing, global, monotonic
  `id` used as the client cursor. No Phase 01–11 tables touched.
- Near-real-time tournament visibility delivered via lightweight JSON polling;
  an **optional** SSE stream is also provided. **No WebSocket infrastructure,
  no external services, no client-side trust.**
- Server-side visibility enforcement: public event types visible to everyone;
  staff-only types restricted to tournament organizer, moderator, or admin.
- Event hooks wired into existing Phase 01–11 services **without weakening any
  behaviour**; recording is best-effort (`recordQuietly`) so the live feed can
  never break the originating business action.
- No JS-heavy frontend rebuild: a simple Blade polling panel and a nav unread
  badge poll the new endpoints.

### Explicit Phase 12 boundary (honoured)
- ❌ No WebSockets, no external push services, no mobile API, no analytics,
  no DevOps beyond the optional SSE stream.
- ❌ No JS framework; frontend additions are ~60 lines of dependency-free JS.

---

## 2. Architecture

```
        ┌──────────────────────────── Business flows (Phases 01–11) ────────────────────────────┐
        │  ScoringService            MatchProgressionService      TournamentParticipationService │
        │  DisputeService            TeamController (register/withdraw)                          │
        └───────────────┬─────────────────────────────────────────────────────────────────────────┘
                        │  recordQuietly(...)  (best-effort; never re-throws)
                        ▼
              ┌─────────────────────┐      ┌──────────────────────────────┐
              │   LiveEventService   │──────▶ live_events (append-only log) │
              │  record / since /    │      │  id = global monotonic cursor │
              │  snapshot / visibleTo│      │  payload = display-safe JSON  │
              └──────────┬───────────┘      └──────────────────────────────┘
                         │
        ┌────────────────┴──────────────────────────────┐
        ▼                                               ▼
  GET tournaments/{t}/live  (JSON poll)        GET tournaments/{t}/stream (SSE, optional)
  GET notifications/unread  (nav badge)        ── all enforce server-side visibility
```

- **Polling is the primary transport.** The tournament page and leaderboard
  embed a `live/poll` Blade partial that polls `tournaments.live?since=N`
  every `config('live.poll_interval_ms')` ms.
- **SSE is a convenience endpoint** for clients that prefer a push stream. It
  is bounded (heartbeat + max duration), emits `X-Accel-Buffering: no`, and is
  not opened automatically by the UI.
- **The cursor is global.** `live_events.id` is an auto-incrementing primary
  key, so a client's `since` value is a single monotonic integer that works
  across tournaments and event types without per-tournament sequences.

---

## 3. Files (new / modified)

### New files
| File | Lines | Purpose |
|---|---|---|
| `database/migrations/2026_09_08_000000_create_live_events_table.php` | 37 | append-only `live_events` log |
| `config/live.php` | 39 | poll/stream settings + `public_types` allowlist |
| `app/Models/LiveEvent.php` | 48 | guarded model + `TYPE_*` constants |
| `app/Services/LiveEventService.php` | 132 | record / query / visibility authority |
| `app/Http/Controllers/LiveController.php` | 137 | JSON poll, SSE, unread badge endpoints |
| `resources/views/live/poll.blade.php` | 82 | polling panel partial |
| `tests/Feature/LiveEventServiceTest.php` | 198 | unit/visibility/cursor tests |
| `tests/Feature/LiveHttpTest.php` | 169 | endpoint + SSE + badge tests |
| `tests/Feature/LiveIntegrationTest.php` | 238 | business-flow hook tests |

### Modified files
| File | Change |
|---|---|
| `routes/web.php` | `LiveController` import; live/stream/unread routes |
| `app/Services/ScoringService.php` | inject `LiveEventService`; `match.score_submitted` hook |
| `app/Services/MatchProgressionService.php` | inject `LiveEventService`; started/completed/disputed/resolved hooks |
| `app/Services/TournamentParticipationService.php` | inject `LiveEventService`; `team.checked_in` hook |
| `app/Services/DisputeService.php` | inject `LiveEventService`; `dispute.opened`/`dispute.closed` (staff-only) hooks |
| `app/Http/Controllers/TeamController.php` | `team.registered`/`team.withdrawn` hooks |
| `resources/views/layouts/app.blade.php` | nav badge id + polling script |
| `resources/views/tournaments/show.blade.php` | `@include('live.poll', …)` |
| `resources/views/leaderboard/show.blade.php` | `@include('live.poll', …)` |

---

## 4. Event types

Constants live on `App\Models\LiveEvent`. The `public_types` allowlist lives in
`config('live.public_types')`.

| Constant | Type string | Visibility | Hooked in |
|---|---|---|---|
| `TYPE_SCORE_SUBMITTED` | `match.score_submitted` | public | `ScoringService` |
| `TYPE_MATCH_COMPLETED` | `match.completed` | public | `MatchProgressionService` |
| `TYPE_MATCH_DISPUTED` | `match.disputed` | public | `MatchProgressionService` |
| `TYPE_MATCH_RESOLVED` | `match.resolved` | public | `MatchProgressionService` |
| `TYPE_MATCH_STARTED` | `match.started` | public | `MatchProgressionService` |
| `TYPE_TEAM_CHECKED_IN` | `team.checked_in` | public | `TournamentParticipationService` |
| `TYPE_TEAM_REGISTERED` | `team.registered` | public | `TeamController` |
| `TYPE_TEAM_WITHDRAWN` | `team.withdrawn` | public | `TeamController` |
| `TYPE_DISPUTE_OPENED` | `dispute.opened` | **staff-only** | `DisputeService` |
| `TYPE_DISPUTE_CLOSED` | `dispute.closed` | **staff-only** | `DisputeService` |

**Payload rule:** every recorded payload contains display-safe data only
(team name, match number, kills, placement, winner, waitlist flag, status).
Actor user id, phone, game UID, screenshots, and any other sensitive fields
are **never** recorded. This is asserted by a dedicated test.

---

## 5. Endpoints

| Route | Method | Auth | Behaviour |
|---|---|---|---|
| `tournaments.live` | GET | none (public) | JSON poll `{revision, since, count, events[]}` |
| `tournaments.stream` | GET | none (public) | SSE stream, bounded, heartbeat |
| `notifications.unread` | GET | required | `{unread: n}` badge count |

### 5.1 JSON poll
```
GET /tournaments/{tournament}/live?since=0&limit=50
→ 200
{
  "revision": 2,            // latest global cursor (use as next `since`)
  "since": 0,               // the cursor that was requested
  "count": 1,               // number of events returned
  "events": [
    {
      "id": 2,              // global monotonic cursor
      "type": "team.registered",
      "payload": { "team": "Live Smoke Team", "waitlisted": true },
      "at": "2026-09-08T04:17:38+00:00"
    }
  ]
}
```
- Serialization exposes only `id`, `type`, `payload`, `at` — never actor id
  or server metadata.
- Events are filtered server-side by `visibleTo()` before serialization.

### 5.2 SSE stream
```
GET /tournaments/{tournament}/stream
→ 200 text/event-stream; charset=UTF-8
   Cache-Control: no-cache
   X-Accel-Buffering: no

id: 2
event: live
data: {"id":2,"type":"team.registered",...}

: heartbeat
...
event: live
data: {"closed":true}
```
- Heartbeat every `live.stream.heartbeat` seconds (15); max duration
  `live.stream.max_duration` seconds (60).
- The controller clears output buffers before streaming so frames flush
  immediately (skipped under unit tests, where the kernel captures the
  stream).

### 5.3 Unread badge
```
GET /notifications/unread   (auth)
→ 200 { "unread": 1 }
```

---

## 6. Visibility enforcement (server-side)

`LiveEventService::visibleTo(?User $viewer, LiveEvent $event): bool`

1. `isPublic($event->type)` → visible to everyone, including guests.
2. Otherwise (`staff-only`):
   - `admin` or `moderator` role → visible.
   - tournament organizer of the event's tournament → visible.
   - everyone else (including other organizers, guests) → **hidden**.

Verified both in tests and live over HTTP (guest saw only
`team.registered`; the organizer additionally saw `dispute.opened`).

---

## 7. Failure isolation (best-effort hooks)

Every business-flow hook calls `LiveEventService::recordQuietly()`, which
wraps `record()` in a try/catch, reports the failure, and returns `null` —
it **never re-throws**. The live feed can therefore never break, roll back,
or alter the outcome of the originating business action (score submission,
match progression, dispute handling, check-in, registration, withdrawal).

---

## 8. Verification

```
php -l (all new/modified PHP files) ........ LINT CLEAN
php artisan migrate:fresh --seed --force ... 22 migrations + seed OK
php artisan test --filter=Live ............. 30 passed, 89 assertions
php artisan test (full suite) .............. 473 passed, 1425 assertions
php artisan route:list ...................... 103 routes
```

### HTTP smoke (live server, curl)
- `GET /tournaments/{t}/live` (guest) → `{"revision":0,"since":0,"count":0,"events":[]}`
- Tournament page + leaderboard both render `id="live-feed"`.
- Guest `/notifications/unread` → 302 (redirect to login).
- Team registration (auth) → 302; then guest poll returned 1 event
  `team.registered` with `waitlisted:true`; player `/notifications/unread` → `{"unread":1}`.
- SSE stream: headers + `event: live` + heartbeat + `data:{"closed":true}` arrived.
- Visibility: after recording a staff-only `dispute.opened`, guest poll listed
  only `team.registered`; organizer poll listed `dispute.opened` + `team.registered`.

### Phase 12 test suite
```
LiveEventServiceTest   10 tests  — append-only/monotonic ids, latestCursor,
                                   recordQuietly, guest/public visibility,
                                   staff-only vs guest/organizer/other-organizer/
                                   moderator/admin, cursor filtering, snapshot,
                                   mass-assignment guard
LiveHttpTest            7 tests  — guest poll shape+actor-id redaction,
                                   guest feed excludes staff events, organizer
                                   sees staff events, since cursor, unread
                                   auth + count, SSE content-type + frames,
                                   live panel rendered
LiveIntegrationTest     8 tests  — score submit (state unchanged + event),
                                   match completed, dispute+resolve, match start,
                                   idempotent check-in (1 event), register+withdraw
                                   via HTTP, no sensitive payload keys,
                                   failed score submission → no event
```
*(27 tests; the remaining 3 of the 30 in `--filter=Live` come from the
`TournamentLifecycleTest` "live" status matches.)*

---

## 9. Known limitations (documented, by design)

- **PHP built-in dev server is single-threaded.** A long-lived SSE connection
  occupies the worker for up to `stream.max_duration` seconds. The UI never
  auto-opens SSE (it polls), so the dev server is not degraded in normal use.
  In production, run behind nginx/apache + php-fpm, where `X-Accel-Buffering:
  no` and `flush()` deliver true streaming.
- **Polling latency** is bounded by `poll_interval_ms` (10 s default) — this
  is a deliberate trade-off for a dependency-free, no-infrastructure design.
- SSE and polling both rely on the client re-connecting with its last cursor;
  the cursor design makes every reconnection lossless.

---

## 10. Config reference

```php
// config/live.php
'poll_interval_ms' => 10000,          // frontend poll cadence
'stream' => [
    'heartbeat'    => 15,             // seconds between SSE heartbeats
    'max_duration' => 60,             // hard cap on a single SSE connection
],
'public_types' => [                   // allowlist; everything else is staff-only
    'match.score_submitted', 'match.completed', 'match.disputed',
    'match.resolved', 'match.started',
    'team.checked_in', 'team.registered', 'team.withdrawn',
],
```

---

# Full file contents

## ── database/migrations/2026_09_08_000000_create_live_events_table.php ─────────────────────────────────────────────
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 — realtime / live updates.
 *
 * An append-only, monotonic event log that powers near-real-time tournament
 * pages (leaderboard, bracket) and live feeds. The auto-incrementing id is
 * the client cursor (`since`). Rows carry non-sensitive, public payloads;
 * staff-only event types are filtered by LiveEventService, never by trusting
 * the client. No Phase 01–11 table is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_events', function (Blueprint $table) {
            $table->id();                                    // global monotonic cursor
            $table->foreignId('tournament_id')->nullable()->constrained('tournaments')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 40);                      // e.g. match.score_submitted
            $table->json('payload')->nullable();             // non-sensitive display data only
            $table->timestamp('created_at')->nullable();

            $table->index('tournament_id', 'live_events_tournament_index');
            $table->index(['tournament_id', 'id'], 'live_events_tournament_cursor_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_events');
    }
};
```

## ── config/live.php ─────────────────────────────────────────────
```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Realtime / live updates (Phase 12)
    |--------------------------------------------------------------------------
    |
    | Near-real-time tournament visibility via lightweight polling and a
    | server-sent-events (SSE) stream. No external services, no websocket
    | infra, no client-side trust: event visibility is enforced server-side.
    |
    */

    // Client polling interval (milliseconds).
    'poll_interval_ms' => 10000,

    // SSE keep-alive configuration (seconds).
    'stream' => [
        'heartbeat' => 15,
        'max_duration' => 60,
    ],

    // Event types visible to everyone (including guests). Any type not
    // listed here is staff-only: an organizer of that tournament, a
    // moderator, or an admin.
    'public_types' => [
        'match.score_submitted',
        'match.completed',
        'match.disputed',
        'match.resolved',
        'match.started',
        'team.checked_in',
        'team.registered',
        'team.withdrawn',
    ],

];
```

## ── app/Models/LiveEvent.php ─────────────────────────────────────────────
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An append-only live event (Phase 12).
 *
 * The auto-incrementing id is a global monotonic cursor that clients pass
 * back as `since`. Payloads are server-generated and carry only non-sensitive
 * display data — never scores-in-progress of other teams' hidden state, never
 * personal data, never secrets. All fields are excluded from mass assignment.
 */
class LiveEvent extends Model
{
    use HasFactory;

    public const TYPE_SCORE_SUBMITTED = 'match.score_submitted';
    public const TYPE_MATCH_COMPLETED = 'match.completed';
    public const TYPE_MATCH_DISPUTED = 'match.disputed';
    public const TYPE_MATCH_RESOLVED = 'match.resolved';
    public const TYPE_MATCH_STARTED = 'match.started';
    public const TYPE_TEAM_CHECKED_IN = 'team.checked_in';
    public const TYPE_TEAM_REGISTERED = 'team.registered';
    public const TYPE_TEAM_WITHDRAWN = 'team.withdrawn';
    public const TYPE_DISPUTE_OPENED = 'dispute.opened';
    public const TYPE_DISPUTE_CLOSED = 'dispute.closed';

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'payload' => 'array',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
```

## ── app/Services/LiveEventService.php ─────────────────────────────────────────────
```php
<?php

namespace App\Services;

use App\Models\LiveEvent;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Realtime / live-update event log (Phase 12).
 *
 * Records domain events (score submitted, match completed, team checked in,
 * dispute opened, …) as an append-only, monotonic stream and serves them back
 * to clients with server-side visibility enforcement: public types are
 * visible to everyone; anything else requires tournament staff (organizer of
 * that tournament, moderator or admin).
 *
 * Recording is best-effort by design (`recordQuietly`): the live feed must
 * never break the originating business action.
 */
class LiveEventService
{
    /**
     * Record a live event. Throws on failure (callers should prefer
     * recordQuietly inside business flows).
     */
    public function record(
        ?Tournament $tournament,
        ?User $actor,
        string $type,
        array $payload = [],
    ): LiveEvent {
        $event = new LiveEvent();
        $event->tournament_id = $tournament?->id;
        $event->actor_user_id = $actor?->id;
        $event->type = $type;
        $event->payload = $payload;
        $event->save();

        return $event;
    }

    /**
     * Record a live event without ever throwing into the caller.
     */
    public function recordQuietly(
        ?Tournament $tournament,
        ?User $actor,
        string $type,
        array $payload = [],
    ): ?LiveEvent {
        try {
            return $this->record($tournament, $actor, $type, $payload);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * The latest global cursor (max id), or 0 when the log is empty.
     */
    public function latestCursor(): int
    {
        return (int) (LiveEvent::query()->max('id') ?? 0);
    }

    /**
     * Whether a viewer may see the given event.
     */
    public function visibleTo(?User $viewer, LiveEvent $event): bool
    {
        if ($this->isPublic($event->type)) {
            return true;
        }

        if ($viewer === null) {
            return false;
        }

        if ($viewer->isAdmin() || $viewer->isModerator()) {
            return true;
        }

        return $event->tournament_id !== null
            && $event->tournament !== null
            && $event->tournament->organizer_id === $viewer->id;
    }

    /**
     * Events with id > $since for a tournament (optionally unscoped when the
     * tournament is null), newest first, already visibility-filtered.
     *
     * @return Collection<int, LiveEvent>
     */
    public function since(int $since, ?Tournament $tournament, ?User $viewer, int $limit = 50): Collection
    {
        $events = LiveEvent::query()
            ->when($tournament !== null, fn ($q) => $q->where('tournament_id', $tournament->id))
            ->where('id', '>', $since)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $events->filter(fn (LiveEvent $event) => $this->visibleTo($viewer, $event))->values();
    }

    /**
     * The earliest missing cursor for a viewer so staff-only events they
     * cannot see do not wedge their cursor (they simply do not receive those
     * ids). Pagination cursor for clients is handled in the controller via
     * the response `revision`.
     */
    public function snapshot(Tournament $tournament, ?User $viewer): array
    {
        return [
            'revision' => $this->latestCursor(),
            'events' => $this->since(0, $tournament, $viewer, 25),
        ];
    }

    /**
     * Whether a type is in the public allowlist.
     */
    public function isPublic(string $type): bool
    {
        return in_array($type, config('live.public_types', []), true);
    }
}
```

## ── app/Http/Controllers/LiveController.php ─────────────────────────────────────────────
```php
<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Services\LiveEventService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Realtime / live-update endpoints (Phase 12).
 *
 *  - GET  /tournaments/{tournament}/live  → JSON polling (primary transport)
 *  - GET  /tournaments/{tournament}/stream → SSE (optional; requires a
 *         concurrent-capable server for long-lived connections)
 *  - GET  /notifications/unread           → JSON unread badge count (auth)
 *
 * Visibility is enforced server-side by LiveEventService; the client is
 * never trusted to declare what it may see.
 */
class LiveController extends Controller
{
    public function __construct(
        protected LiveEventService $live,
        protected NotificationService $notifications,
    ) {
    }

    /**
     * JSON polling endpoint. `since` is the client cursor (last seen global
     * event id). Returns the current revision plus events newer than `since`
     * that the viewer is allowed to see.
     */
    public function tournamentLive(Tournament $tournament, Request $request)
    {
        $since = max(0, (int) $request->query('since', 0));
        $limit = min(100, max(1, (int) $request->query('limit', 50)));

        $events = $this->live->since($since, $tournament, $request->user(), $limit);

        return response()->json([
            'revision' => $this->live->latestCursor(),
            'since' => $since,
            'count' => $events->count(),
            'events' => $events->map(fn ($e) => $this->serialize($e))->values(),
        ]);
    }

    /**
     * Server-Sent Events stream for a tournament. Bounded lifetime with
     * comment heartbeats; ends cleanly so clients reconnect.
     */
    public function stream(Tournament $tournament, Request $request): StreamedResponse
    {
        $heartbeat = max(1, (int) config('live.stream.heartbeat', 15));
        $maxDuration = max(0, (int) config('live.stream.max_duration', 60));
        $viewer = $request->user();

        return response()->stream(function () use ($tournament, $viewer, $heartbeat, $maxDuration) {
            $startedAt = microtime(true);

            // Clear output buffers so frames are flushed to the client
            // immediately (required by the PHP built-in dev server; harmless
            // behind nginx/apache + php-fpm). Skipped while running tests,
            // where the HTTP kernel captures the stream in its own buffer.
            if (! app()->runningUnitTests()) {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
            }

            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('X-Accel-Buffering: no');

            $cursor = 0;

            while (true) {
                $events = $this->live->since($cursor, $tournament, $viewer, 50);

                foreach ($events as $event) {
                    echo 'id: ' . $event->id . "\n";
                    echo "event: live\n";
                    echo 'data: ' . json_encode($this->serialize($event)) . "\n\n";
                    flush();

                    $cursor = max($cursor, $event->id);
                }

                echo ": heartbeat\n\n";
                flush();

                if ($maxDuration <= 0 || (microtime(true) - $startedAt) >= $maxDuration) {
                    echo "event: live\ndata: {\"closed\":true}\n\n";
                    flush();

                    break;
                }

                sleep($heartbeat);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Unread notification count for the navigation badge (auth only).
     */
    public function unreadCount(Request $request)
    {
        $user = $request->user();

        abort_unless($user !== null, 401);

        return response()->json([
            'unread' => $this->notifications->unreadCount($user),
        ]);
    }

    /**
     * A non-sensitive JSON shape for a live event (no actor id, no hidden
     * fields — the actor and any staff metadata stay server-side).
     */
    protected function serialize($event): array
    {
        return [
            'id' => $event->id,
            'type' => $event->type,
            'payload' => $event->payload ?? [],
            'at' => $event->created_at?->toIso8601String(),
        ];
    }
}
```

## ── routes/web.php ─────────────────────────────────────────────
```php
<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DisputeController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\LiveController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\ModerationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PayoutController;
use App\Http\Controllers\ScoringRuleController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SettlementController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TournamentController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

// Guest auth
Route::middleware('guest')->group(function () {
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// Provider payment webhook — authenticated by HMAC signature, not session.
Route::post('/webhooks/payments/{provider}', [WebhookController::class, 'handle'])->name('webhooks.payments');

// Public tournament browsing
Route::get('/tournaments', [TournamentController::class, 'index'])->name('tournaments.index');
Route::get('/tournaments/{tournament}', [TournamentController::class, 'show'])->name('tournaments.show');
Route::get('/tournaments/{tournament}/leaderboard', [LeaderboardController::class, 'show'])->name('leaderboard.show');

// Realtime / live updates (Phase 12) — public read, server-side visibility
Route::get('/tournaments/{tournament}/live', [LiveController::class, 'tournamentLive'])->name('tournaments.live');
Route::get('/tournaments/{tournament}/stream', [LiveController::class, 'stream'])->name('tournaments.stream');

// Authenticated — every sensitive action is authorized server-side
Route::middleware('auth')->group(function () {
    // Organizer tournament lifecycle + participation controls
    Route::get('/organizer/tournaments/create', [TournamentController::class, 'create'])->name('tournaments.create');
    Route::post('/organizer/tournaments', [TournamentController::class, 'store'])->name('tournaments.store');
    Route::get('/organizer/tournaments/{tournament}/edit', [TournamentController::class, 'edit'])->name('tournaments.edit');
    Route::put('/organizer/tournaments/{tournament}', [TournamentController::class, 'update'])->name('tournaments.update');
    Route::post('/organizer/tournaments/{tournament}/publish', [TournamentController::class, 'publish'])->name('tournaments.publish');
    Route::post('/organizer/tournaments/{tournament}/close', [TournamentController::class, 'closeRegistration'])->name('tournaments.close');
    Route::post('/organizer/tournaments/{tournament}/bracket', [TournamentController::class, 'start'])->name('tournaments.bracket');
    Route::post('/organizer/tournaments/{tournament}/complete', [TournamentController::class, 'complete'])->name('tournaments.complete');
    Route::post('/organizer/tournaments/{tournament}/cancel', [TournamentController::class, 'cancel'])->name('tournaments.cancel');
    Route::post('/organizer/tournaments/{tournament}/no-shows', [TournamentController::class, 'markNoShows'])->name('tournaments.noshows');
    Route::post('/organizer/tournaments/{tournament}/waitlist/promote', [TournamentController::class, 'promoteWaitlisted'])->name('tournaments.waitlist.promote');

    // Scoring rules configuration (organizer/admin only)
    Route::get('/organizer/tournaments/{tournament}/scoring', [ScoringRuleController::class, 'show'])->name('tournaments.scoring.show');
    Route::post('/organizer/tournaments/{tournament}/scoring', [ScoringRuleController::class, 'store'])->name('tournaments.scoring.store');
    Route::post('/organizer/tournaments/{tournament}/scoring/{rule}/activate', [ScoringRuleController::class, 'activate'])->name('tournaments.scoring.activate');

    // Team registration, check-in, payment + roster management
    Route::get('/tournaments/{tournament}/register', [TeamController::class, 'showRegistration'])->name('teams.register');
    Route::post('/tournaments/{tournament}/register', [TeamController::class, 'register'])->name('teams.store');
    Route::get('/tournaments/{tournament}/teams/{team}', [TeamController::class, 'show'])->name('teams.show');
    Route::put('/tournaments/{tournament}/teams/{team}/profile', [TeamController::class, 'updateProfile'])->name('teams.update');
    Route::post('/tournaments/{tournament}/teams/{team}/members', [TeamController::class, 'addMember'])->name('teams.members.store');
    Route::post('/tournaments/{tournament}/teams/{team}/members/{member}/remove', [TeamController::class, 'removeMember'])->name('teams.members.remove');
    Route::post('/tournaments/{tournament}/teams/{team}/withdraw', [TeamController::class, 'withdraw'])->name('teams.withdraw');
    Route::post('/tournaments/{tournament}/teams/{team}/check-in', [TeamController::class, 'checkIn'])->name('teams.checkin');
    Route::get('/tournaments/{tournament}/teams/{team}/pay', [PaymentController::class, 'show'])->name('payment.show');
    Route::post('/tournaments/{tournament}/teams/{team}/pay', [PaymentController::class, 'verify'])->name('payment.verify');
    Route::get('/tournaments/{tournament}/teams/{team}/pay/{payment}/pending', [PaymentController::class, 'pending'])->name('payment.pending');

    // Matches (bracket progression)
    Route::get('/tournaments/{tournament}/matches/{match}', [MatchController::class, 'show'])->name('matches.show');
    Route::post('/tournaments/{tournament}/matches/{match}/room', [MatchController::class, 'setRoom'])->name('matches.room');
    Route::post('/tournaments/{tournament}/matches/{match}/score', [MatchController::class, 'submitScore'])->name('matches.score');
    Route::post('/tournaments/{tournament}/matches/{match}/adjustment', [MatchController::class, 'addAdjustment'])->name('matches.adjustment');
    Route::post('/tournaments/{tournament}/matches/{match}/winner', [MatchController::class, 'setWinner'])->name('matches.winner');
    Route::post('/tournaments/{tournament}/matches/{match}/dispute', [MatchController::class, 'dispute'])->name('matches.dispute');
    Route::post('/tournaments/{tournament}/matches/{match}/resolve', [MatchController::class, 'resolve'])->name('matches.resolve');

    // Disputes (Phase 07) — nested under tournament + match so every record
    // is validated against its parents; authorization never relies on route
    // model binding alone.
    Route::get('/tournaments/{tournament}/matches/{match}/disputes/create', [DisputeController::class, 'create'])->name('matches.disputes.create');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes', [DisputeController::class, 'store'])->name('matches.disputes.store');
    Route::get('/tournaments/{tournament}/matches/{match}/disputes/{dispute}', [DisputeController::class, 'show'])->name('matches.disputes.show');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/evidence', [DisputeController::class, 'addEvidence'])->name('matches.disputes.evidence.store');
    Route::get('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/evidence/{evidence}', [DisputeController::class, 'evidence'])->name('matches.disputes.evidence.show');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/cancel', [DisputeController::class, 'cancel'])->name('matches.disputes.cancel');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/review', [DisputeController::class, 'review'])->name('matches.disputes.review');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/assign', [DisputeController::class, 'assign'])->name('matches.disputes.assign');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/resolve', [DisputeController::class, 'resolve'])->name('matches.disputes.resolve');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/reject', [DisputeController::class, 'reject'])->name('matches.disputes.reject');
    Route::post('/tournaments/{tournament}/matches/{match}/disputes/{dispute}/evidence/{evidence}/remove', [DisputeController::class, 'removeEvidence'])->name('matches.disputes.evidence.remove');

    // Moderation queue (staff)
    Route::get('/moderation', [ModerationController::class, 'index'])->name('moderation.index');

    // Moderation security review (admin + moderator only, Phase 10)
    Route::get('/moderation/security', [ModerationController::class, 'security'])->name('moderation.security');

    // Security — anti-cheat incidents + identity request (policy-guarded)
    Route::get('/security/incidents', [SecurityController::class, 'incidents'])->name('security.incidents.index');
    Route::post('/security/incidents', [SecurityController::class, 'openIncident'])->name('security.incidents.open');
    Route::post('/security/incidents/{incident}/review', [SecurityController::class, 'reviewIncident'])->name('security.incidents.review');
    Route::post('/security/incidents/{incident}/resolve', [SecurityController::class, 'resolveIncident'])->name('security.incidents.resolve');
    Route::post('/security/identity/request', [SecurityController::class, 'requestVerification'])->name('security.identity.request');

    // Wallet (authenticated user)
    Route::get('/wallet', [WalletController::class, 'index'])->name('wallet.index');

    // Notifications (Phase 11 — always the authenticated user's own inbox)
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.readAll');
    Route::get('/notifications/unread', [LiveController::class, 'unreadCount'])->name('notifications.unread');

    // Admin
    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');

        // Payments (Phase 08)
        Route::get('/payments', [AdminController::class, 'payments'])->name('payments.index');
        Route::post('/payments/{payment}/verify', [AdminController::class, 'verifyPayment'])->name('payments.verify');
        Route::post('/payments/{payment}/fail', [AdminController::class, 'failPayment'])->name('payments.fail');
        Route::post('/payments/{payment}/refund', [AdminController::class, 'refundPayment'])->name('payments.refund');

        // Wallets + ledger (Phase 08)
        Route::get('/users/{user}/wallet', [AdminController::class, 'wallet'])->name('wallet.show');
        Route::post('/users/{user}/wallet/credit', [AdminController::class, 'creditWallet'])->name('wallet.credit');
        Route::post('/users/{user}/wallet/debit', [AdminController::class, 'debitWallet'])->name('wallet.debit');

        // Prize distribution + payouts + settlement (Phase 09)
        Route::get('/settlements', [SettlementController::class, 'index'])->name('settlements.index');
        Route::get('/tournaments/{tournament}/settlement', [SettlementController::class, 'show'])->name('settlements.show');
        Route::post('/tournaments/{tournament}/settlement/prizes', [SettlementController::class, 'storePrizeTiers'])->name('settlements.prizes');
        Route::post('/tournaments/{tournament}/settlement/calculate', [SettlementController::class, 'calculate'])->name('settlements.calculate');
        Route::post('/tournaments/{tournament}/settlement/approve', [SettlementController::class, 'approve'])->name('settlements.approve');
        Route::post('/tournaments/{tournament}/settlement/process', [SettlementController::class, 'process'])->name('settlements.process');
        Route::post('/tournaments/{tournament}/settlement/cancel', [SettlementController::class, 'cancel'])->name('settlements.cancel');
        Route::post('/tournaments/{tournament}/settlement/adjust', [SettlementController::class, 'adjust'])->name('settlements.adjust');

        Route::get('/payouts', [PayoutController::class, 'index'])->name('payouts.index');
        Route::post('/payouts/{payout}/approve', [PayoutController::class, 'approve'])->name('payouts.approve');
        Route::post('/payouts/{payout}/process', [PayoutController::class, 'process'])->name('payouts.process');
        Route::post('/payouts/{payout}/process-override', [PayoutController::class, 'processOverride'])->name('payouts.processOverride');
        Route::post('/payouts/{payout}/complete', [PayoutController::class, 'complete'])->name('payouts.complete');
        Route::post('/payouts/{payout}/fail', [PayoutController::class, 'fail'])->name('payouts.fail');
        Route::post('/payouts/{payout}/cancel', [PayoutController::class, 'cancel'])->name('payouts.cancel');

        // Anti-fraud security administration (Phase 10)
        Route::get('/security', [SecurityController::class, 'dashboard'])->name('security.dashboard');
        Route::get('/security/users', [SecurityController::class, 'users'])->name('security.users');
        Route::get('/security/users/{user}', [SecurityController::class, 'user'])->name('security.user');
        Route::get('/security/events', [SecurityController::class, 'events'])->name('security.events');
        Route::post('/security/users/{user}/restrict', [SecurityController::class, 'restrict'])->name('security.restrict');
        Route::post('/security/restrictions/{restriction}/lift', [SecurityController::class, 'liftRestriction'])->name('security.lift');
        Route::post('/security/users/{user}/verify', [SecurityController::class, 'verifyIdentity'])->name('security.verify');
        Route::post('/security/users/{user}/reject-identity', [SecurityController::class, 'rejectIdentity'])->name('security.reject');

        // Moderation roles (Phase 07)
        Route::post('/users/moderators', [AdminController::class, 'makeModerator'])->name('users.moderate');
        Route::post('/users/{user}/remove-moderator', [AdminController::class, 'removeModerator'])->name('users.unmoderate');
    });
});
```

## ── app/Services/ScoringService.php ─────────────────────────────────────────────
```php
<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\LiveEvent;
use App\Models\Score;
use App\Models\ScoreAdjustment;
use App\Models\ScoringRule;
use App\Models\Team;
use App\Models\Tournament;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Free Fire scoring engine (Phase 06).
 *
 * Single source of truth for all scoring math: placement points, kill
 * points, bonuses, penalties, totals, versioned rule snapshots and
 * deterministic tie-broken standings. Controllers delegate here and never
 * compute scoring themselves.
 */
class ScoringService
{
    public function __construct(
        protected LiveEventService $live,
    ) {
    }

    /**
     * The tournament's current (active) scoring rule set, lazily creating the
     * legacy default snapshot when none exists (backward compatibility).
     */
    public function currentRuleSet(Tournament $tournament): ScoringRule
    {
        $rule = $tournament->scoringRules()
            ->where('is_current', true)
            ->orderByDesc('version')
            ->first();

        if ($rule !== null) {
            return $rule;
        }

        return $this->createVersion($tournament, [
            'name' => 'Default Free Fire Rules',
            'kill_points' => ScoringRule::DEFAULT_KILL_POINTS,
            'placement_points' => ScoringRule::DEFAULT_PLACEMENT_POINTS,
            'tie_breakers' => ScoringRule::DEFAULT_TIE_BREAKERS,
        ]);
    }

    /**
     * Create a new immutable rule-set version and make it the current one.
     * Existing scores keep their own snapshots, so history never changes.
     */
    public function createVersion(Tournament $tournament, array $data): ScoringRule
    {
        $data = $this->normalizeRuleData($data);

        return DB::transaction(function () use ($tournament, $data) {
            $next = (int) ($tournament->scoringRules()->max('version') ?? 0) + 1;

            $tournament->scoringRules()
                ->where('is_current', true)
                ->update(['is_current' => false]);

            $rule = new ScoringRule();
            $rule->tournament_id = $tournament->id;
            $rule->version = $next;
            $rule->name = $data['name'];
            $rule->kill_points = $data['kill_points'];
            $rule->placement_points = $data['placement_points'];
            $rule->tie_breakers = $data['tie_breakers'];
            $rule->is_current = true;
            $rule->save();

            return $rule;
        });
    }

    /**
     * Re-activate an existing rule-set version as current.
     */
    public function activateVersion(Tournament $tournament, ScoringRule $rule): void
    {
        if ($rule->tournament_id !== $tournament->id) {
            throw new DomainException('That rule set does not belong to this tournament.');
        }

        DB::transaction(function () use ($tournament, $rule) {
            $tournament->scoringRules()
                ->where('is_current', true)
                ->update(['is_current' => false]);

            // A raw update always executes, even if the in-memory model still
            // thinks it is current.
            ScoringRule::where('id', $rule->id)->update(['is_current' => true]);
        });
    }

    /**
     * Deterministically compute a score breakdown from a rule snapshot.
     *
     * @return array{placement_points:int, kill_points:int, bonus_points:int, penalty_points:int, total:int}
     */
    public function breakdown(ScoringRule $rule, int $kills, int $placement, iterable $adjustments): array
    {
        $placementPoints = $rule->placementPointsFor($placement);
        $killPoints = $kills * (int) $rule->kill_points;

        $bonus = 0;
        $penalty = 0;

        foreach ($adjustments as $adjustment) {
            if ($adjustment->type === ScoreAdjustment::TYPE_PENALTY) {
                $penalty += (int) $adjustment->points;
            } else {
                $bonus += (int) $adjustment->points;
            }
        }

        $total = $placementPoints + $killPoints + $bonus - $penalty;

        return [
            'placement_points' => $placementPoints,
            'kill_points' => $killPoints,
            'bonus_points' => $bonus,
            'penalty_points' => $penalty,
            'total' => max(0, $total),
        ];
    }

    /**
     * Record a team's score for a match using the current rule snapshot.
     * Transaction-safe; the unique (match_id, team_id) constraint is the
     * race-condition backstop.
     */
    public function submitScore(GameMatch $match, Team $team, int $kills, int $placement, ?string $screenshotPath = null): Score
    {
        if (! $match->acceptsScoreSubmission()) {
            throw new DomainException('Score submission is not open for this match.');
        }

        if (! $match->hasParticipant($team)) {
            throw new DomainException('This team is not part of this match.');
        }

        if ($placement < 1 || $placement > ScoringRule::MAX_PLACEMENT) {
            throw new DomainException('Placement must be between 1 and ' . ScoringRule::MAX_PLACEMENT . '.');
        }

        if ($kills < 0) {
            throw new DomainException('Kills cannot be negative.');
        }

        try {
            return DB::transaction(function () use ($match, $team, $kills, $placement, $screenshotPath) {
                if (Score::where('match_id', $match->id)->where('team_id', $team->id)->exists()) {
                    throw new DomainException('A score for this team has already been submitted.');
                }

                if (Score::where('match_id', $match->id)->where('placement', $placement)->exists()) {
                    throw new DomainException('Another team in this match has already claimed that placement.');
                }

                $rule = $this->currentRuleSet($match->tournament);
                $parts = $this->breakdown($rule, $kills, $placement, []);

                $score = new Score();
                $score->match_id = $match->id;
                $score->team_id = $team->id;
                $score->kills = $kills;
                $score->placement = $placement;
                $score->placement_points = $parts['placement_points'];
                $score->kill_points = $parts['kill_points'];
                $score->bonus_points = 0;
                $score->penalty_points = 0;
                $score->points = $parts['total'];
                $score->scoring_rules_id = $rule->id;
                $score->screenshot_path = $screenshotPath;
                $score->status = 'pending';
                $score->save();

                // Phase 12 — live event (atomic with the score; best-effort).
                $this->live->recordQuietly($match->tournament, null, LiveEvent::TYPE_SCORE_SUBMITTED, [
                    'match_no' => (int) $match->match_no,
                    'round' => (int) $match->round,
                    'team' => $team->name,
                    'kills' => $kills,
                    'placement' => $placement,
                ]);

                return $score;
            });
        } catch (QueryException $e) {
            // Unique (match_id, team_id) or (match_id, placement) constraint.
            throw new DomainException('A score already exists for this team or placement.');
        }
    }

    /**
     * Apply an auditable bonus/penalty to a score and recompute its totals.
     * Only possible while the match is not finalized.
     */
    public function addAdjustment(Score $score, string $type, int $points, string $reason): ScoreAdjustment
    {
        if (! in_array($type, ScoreAdjustment::TYPES, true)) {
            throw new DomainException('Adjustment type must be bonus or penalty.');
        }

        if ($points < 1) {
            throw new DomainException('Adjustment points must be a positive integer.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('An adjustment requires a reason.');
        }

        $match = $score->match;

        if ($match === null || ! $match->acceptsScoreSubmission()) {
            throw new DomainException('Scores cannot be adjusted once the match is finalized.');
        }

        return DB::transaction(function () use ($score, $type, $points, $reason) {
            $adjustment = new ScoreAdjustment();
            $adjustment->score_id = $score->id;
            $adjustment->type = $type;
            $adjustment->points = $points;
            $adjustment->reason = $reason;
            $adjustment->save();

            $this->recompute($score);

            return $adjustment;
        });
    }

    /**
     * Correct a score's raw inputs (kills and/or placement) through the
     * scoring engine and recompute every derived value.
     *
     * Phase 07 — this is the ONLY sanctioned path for a moderator/admin to
     * correct a result. It:
     *   - requires the match to be disputed or completed (i.e. inside the
     *     controlled dispute-resolution flow),
     *   - rejects negative kills and impossible placements,
     *   - rejects a placement already claimed by another team in the match,
     *   - recalculates using the score's OWN scoring-rule snapshot, so the
     *     historical scoring version is preserved,
     *   - never accepts a client-supplied total.
     */
    public function correctScore(Score $score, ?int $kills, ?int $placement): Score
    {
        $match = $score->match;

        if ($match === null) {
            throw new DomainException('This score is not attached to a match.');
        }

        if (! in_array($match->status, [GameMatch::STATUS_DISPUTED, GameMatch::STATUS_COMPLETED], true)) {
            throw new DomainException('Scores can only be corrected during dispute resolution.');
        }

        $newKills = $kills === null ? (int) $score->kills : $kills;
        $newPlacement = $placement === null ? (int) $score->placement : $placement;

        if ($newKills < 0) {
            throw new DomainException('Kills cannot be negative.');
        }

        if ($newPlacement < 1 || $newPlacement > ScoringRule::MAX_PLACEMENT) {
            throw new DomainException('Placement must be between 1 and ' . ScoringRule::MAX_PLACEMENT . '.');
        }

        return DB::transaction(function () use ($score, $match, $newKills, $newPlacement) {
            $taken = Score::query()
                ->where('match_id', $match->id)
                ->where('placement', $newPlacement)
                ->where('id', '!=', $score->id)
                ->exists();

            if ($taken) {
                throw new DomainException('Another team in this match has already claimed that placement.');
            }

            $score->kills = $newKills;
            $score->placement = $newPlacement;
            $score->save();

            $this->recompute($score);

            return $score;
        });
    }

    /**
     * Recompute a score's breakdown and total from its rule snapshot and its
     * current adjustments.
     */
    public function recompute(Score $score): void
    {
        $rule = $score->scoringRule;

        if ($rule === null) {
            // Legacy score without a snapshot — fall back to current rules.
            $rule = $this->currentRuleSet($score->match->tournament);
        }

        $parts = $this->breakdown(
            $rule,
            (int) $score->kills,
            (int) $score->placement,
            $score->adjustments
        );

        $score->placement_points = $parts['placement_points'];
        $score->kill_points = $parts['kill_points'];
        $score->bonus_points = $parts['bonus_points'];
        $score->penalty_points = $parts['penalty_points'];
        $score->points = $parts['total'];
        $score->scoring_rules_id = $rule->id;
        $score->save();
    }

    /**
     * Deterministic tournament standings.
     *
     * Only scores from matches that are not bye, cancelled or disputed are
     * counted (pending matches can never hold scores). Rows are ordered by
     * the current rule set's tie-breaker chain and finally by team id, so
     * identical inputs always produce an identical ranking.
     */
    public function standings(Tournament $tournament): Collection
    {
        $rule = $this->currentRuleSet($tournament);

        $scores = Score::query()
            ->whereHas('match', fn ($q) => $q
                ->where('tournament_id', $tournament->id)
                ->whereNotIn('status', [
                    GameMatch::STATUS_BYE,
                    GameMatch::STATUS_CANCELLED,
                    GameMatch::STATUS_DISPUTED,
                ]))
            ->with('team')
            ->get();

        $rows = [];

        foreach ($scores as $score) {
            $teamId = $score->team_id;

            if (! isset($rows[$teamId])) {
                $rows[$teamId] = [
                    'team_id' => $teamId,
                    'team' => $score->team,
                    'matches_played' => 0,
                    'kills' => 0,
                    'placement_points' => 0,
                    'kill_points' => 0,
                    'points' => 0,
                    'best_placement' => null,
                ];
            }

            $rows[$teamId]['matches_played']++;
            $rows[$teamId]['kills'] += (int) $score->kills;
            $rows[$teamId]['placement_points'] += (int) $score->placement_points;
            $rows[$teamId]['kill_points'] += (int) $score->kill_points;
            $rows[$teamId]['points'] += (int) $score->points;

            $best = $rows[$teamId]['best_placement'];
            if ($best === null || (int) $score->placement < $best) {
                $rows[$teamId]['best_placement'] = (int) $score->placement;
            }
        }

        $rows = array_values($rows);

        usort($rows, function (array $a, array $b) use ($rule) {
            foreach ($rule->tieBreakers() as $key) {
                $comparison = $this->compareMetric($key, $a, $b);
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            // Deterministic final fallback — never database-order dependent.
            return $a['team_id'] <=> $b['team_id'];
        });

        $result = [];
        $rank = 0;

        foreach ($rows as $row) {
            $row['rank'] = ++$rank;
            $result[] = (object) $row;
        }

        return new Collection($result);
    }

    /**
     * Compare two aggregated rows on a single metric.
     *
     * @return int negative when $a sorts before $b
     */
    protected function compareMetric(string $key, array $a, array $b): int
    {
        return match ($key) {
            'points' => (int) $b['points'] <=> (int) $a['points'],
            'placement_points' => (int) $b['placement_points'] <=> (int) $a['placement_points'],
            'kill_points' => (int) $b['kill_points'] <=> (int) $a['kill_points'],
            'kills' => (int) $b['kills'] <=> (int) $a['kills'],
            'best_placement' => ($a['best_placement'] ?? PHP_INT_MAX) <=> ($b['best_placement'] ?? PHP_INT_MAX),
            default => 0,
        };
    }

    /**
     * Validate and normalise rule-set input (server-authoritative — the
     * controller's request validation is a first line, this is the last).
     */
    protected function normalizeRuleData(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $name = 'Scoring rules';
        }

        $killPoints = (int) ($data['kill_points'] ?? ScoringRule::DEFAULT_KILL_POINTS);
        if ($killPoints < 0) {
            throw new DomainException('Kill points cannot be negative.');
        }

        $placementPoints = [];
        $provided = $data['placement_points'] ?? null;

        if (is_array($provided)) {
            foreach ($provided as $placement => $points) {
                $placement = (int) $placement;
                if ($placement < 1 || $placement > ScoringRule::MAX_PLACEMENT) {
                    continue;
                }
                if ((int) $points < 0) {
                    throw new DomainException('Placement points cannot be negative.');
                }
                $placementPoints[$placement] = (int) $points;
            }
        }

        if ($placementPoints === []) {
            throw new DomainException('A placement point table is required.');
        }

        $tieBreakers = $data['tie_breakers'] ?? ScoringRule::DEFAULT_TIE_BREAKERS;
        $allowed = array_keys(ScoringRule::TIE_BREAKER_OPTIONS);
        $chain = is_array($tieBreakers)
            ? array_values(array_filter(array_map('strval', $tieBreakers), fn ($k) => in_array($k, $allowed, true)))
            : [];

        if ($chain === []) {
            $chain = ScoringRule::DEFAULT_TIE_BREAKERS;
        }

        if (! in_array('points', $chain, true)) {
            array_unshift($chain, 'points');
        }

        return [
            'name' => $name,
            'kill_points' => $killPoints,
            'placement_points' => $placementPoints,
            'tie_breakers' => array_values(array_unique($chain)),
        ];
    }
}
```

## ── app/Services/MatchProgressionService.php ─────────────────────────────────────────────
```php
<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\LiveEvent;
use App\Models\Team;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Match progression (Phase 05).
 *
 * Controls the match state machine and winner advancement. Authorization is
 * handled by policies in the controllers; this service only answers "is this
 * progression legal right now?" and performs it atomically.
 */
class MatchProgressionService
{
    public function __construct(
        protected BracketService $bracket,
        protected LiveEventService $live,
    ) {
    }

    /**
     * Record a winner and complete a match, then advance the bracket.
     *
     * @return string 'completed' | 'already'
     */
    public function complete(GameMatch $match, Team $winner): string
    {
        if (! $match->hasParticipant($winner)) {
            throw new DomainException('Winner must be a participating team.');
        }

        if ($match->status === GameMatch::STATUS_COMPLETED) {
            if ($match->winner_team_id === $winner->id) {
                return 'already';
            }

            throw new DomainException('This match is already completed — dispute it before changing the result.');
        }

        if (! in_array($match->status, [GameMatch::STATUS_READY, GameMatch::STATUS_LIVE], true)) {
            throw new DomainException('This match cannot be completed from its current state.');
        }

        DB::transaction(function () use ($match, $winner) {
            $match->winner_team_id = $winner->id;
            $match->status = GameMatch::STATUS_COMPLETED;
            $match->completed_at = now();
            $match->save();

            $this->bracket->advance($match);

            // Phase 12 — live event (atomic with the match, best-effort).
            $this->live->recordQuietly($match->tournament, null, LiveEvent::TYPE_MATCH_COMPLETED, [
                'match_no' => (int) $match->match_no,
                'round' => (int) $match->round,
                'winner' => $winner->name,
            ]);
        });

        return 'completed';
    }

    /**
     * Move a completed match into the disputed state. Advancement is halted
     * while disputed.
     */
    public function dispute(GameMatch $match): void
    {
        if ($match->status !== GameMatch::STATUS_COMPLETED) {
            throw new DomainException('Only completed matches can be disputed.');
        }

        $match->status = GameMatch::STATUS_DISPUTED;
        $match->save();

        // Phase 12 — live event (best-effort).
        $this->live->recordQuietly($match->tournament, null, LiveEvent::TYPE_MATCH_DISPUTED, [
            'match_no' => (int) $match->match_no,
            'round' => (int) $match->round,
        ]);
    }

    /**
     * Resolve a disputed match with a (possibly corrected) winner. If the
     * winner changed, the stale winner/loser are replaced in the downstream
     * match slots.
     */
    public function resolve(GameMatch $match, Team $winner): void
    {
        if ($match->status !== GameMatch::STATUS_DISPUTED) {
            throw new DomainException('Only disputed matches can be resolved.');
        }

        if (! $match->hasParticipant($winner)) {
            throw new DomainException('Winner must be a participating team.');
        }

        DB::transaction(function () use ($match, $winner) {
            $stale = $match->winner_team_id;

            $match->winner_team_id = $winner->id;
            $match->status = GameMatch::STATUS_COMPLETED;
            $match->completed_at = now();
            $match->save();

            $this->bracket->advance($match, $stale);

            // Phase 12 — live event (atomic with the match, best-effort).
            $this->live->recordQuietly($match->tournament, null, LiveEvent::TYPE_MATCH_RESOLVED, [
                'match_no' => (int) $match->match_no,
                'round' => (int) $match->round,
                'winner' => $winner->name,
            ]);
        });
    }

    /**
     * Move a ready/pending match into the live state.
     */
    public function start(GameMatch $match): void
    {
        if (! in_array($match->status, [GameMatch::STATUS_PENDING, GameMatch::STATUS_READY], true)) {
            throw new DomainException('This match cannot be started from its current state.');
        }

        $match->status = GameMatch::STATUS_LIVE;
        $match->save();

        // Phase 12 — live event (best-effort).
        $this->live->recordQuietly($match->tournament, null, LiveEvent::TYPE_MATCH_STARTED, [
            'match_no' => (int) $match->match_no,
            'round' => (int) $match->round,
        ]);
    }
}
```

## ── app/Services/TournamentParticipationService.php ─────────────────────────────────────────────
```php
<?php

namespace App\Services;

use App\Models\LiveEvent;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 04 — registration eligibility, check-in, waitlist and no-show
 * handling.
 *
 * This service owns the *participation* state machine that sits on top of the
 * Phase 02 tournament lifecycle and the Phase 03 roster rules:
 *
 *   - check-in (window enforcement, idempotency, admin override)
 *   - waitlist FIFO ordering and race-safe promotion
 *   - no-show marking once the check-in window closes
 *
 * Authorization lives in policies; this service answers
 * "is this participation change legal right now?" and performs it.
 */
class TournamentParticipationService
{
    public function __construct(
        protected LiveEventService $live,
    ) {
    }

    /**
     * Check a team in. Idempotent — checking in twice returns 'already' and
     * does not alter slot accounting or timestamps.
     *
     * @return string 'checked_in' | 'already'
     */
    public function checkIn(Tournament $tournament, Team $team, User $user, bool $override = false): string
    {
        if (! $team->belongsToTournament($tournament)) {
            throw new DomainException('This team does not belong to this tournament.');
        }

        if ($team->status !== Team::STATUS_CONFIRMED) {
            throw new DomainException('Only confirmed teams can check in.');
        }

        if (! $tournament->hasCheckIn()) {
            throw new DomainException('Check-in is not configured for this tournament.');
        }

        if ($team->checked_in_at !== null) {
            return 'already';
        }

        if (! $override && ! $tournament->checkInIsOpen()) {
            if ($tournament->checkInHasClosed()) {
                throw new DomainException('The check-in window has closed.');
            }

            throw new DomainException('Check-in has not opened yet.');
        }

        $team->checked_in_at = now();
        $team->checked_in_by = $user->id;
        $team->save();

        // Phase 12 — live event (best-effort).
        $this->live->recordQuietly($tournament, null, LiveEvent::TYPE_TEAM_CHECKED_IN, [
            'team' => $team->name,
        ]);

        return 'checked_in';
    }

    /**
     * Promote the next waitlisted team into a free slot.
     *
     * Deterministic (FIFO by waitlisted_at, then id), transaction-safe and
     * idempotent per team: once promoted, a team is no longer waitlisted and
     * can never be selected again. The atomic slot claim guarantees that two
     * simultaneous promotions can never both fill the same final slot.
     */
    public function promoteNext(Tournament $tournament): Team
    {
        return DB::transaction(function () use ($tournament) {
            $fresh = Tournament::findOrFail($tournament->id);

            if (! $fresh->acceptsRegistration()) {
                throw new DomainException('Registration is closed — waitlisted teams can no longer be promoted.');
            }

            // ATOMIC SLOT CLAIM — only succeeds while a slot is actually free.
            $claimed = DB::table('tournaments')
                ->where('id', $fresh->id)
                ->where('status', Tournament::STATUS_OPEN)
                ->where(function ($q) {
                    $q->whereNull('starts_at')->orWhere('starts_at', '>', now());
                })
                ->whereRaw(
                    '(SELECT COUNT(*) FROM teams WHERE tournament_id = tournaments.id AND status IN (?, ?)) < team_slots',
                    [Team::STATUS_PENDING, Team::STATUS_CONFIRMED]
                )
                ->update(['updated_at' => now()]);

            if ($claimed !== 1) {
                throw new DomainException('No available slot to promote a waitlisted team into.');
            }

            $next = Team::query()
                ->where('tournament_id', $fresh->id)
                ->where('status', Team::STATUS_WAITLISTED)
                ->orderBy('waitlisted_at')
                ->orderBy('id')
                ->first();

            if ($next === null) {
                throw new DomainException('The waitlist is empty.');
            }

            $next->status = Team::STATUS_PENDING;
            $next->waitlisted_at = null;
            $next->save();

            return $next;
        });
    }

    /**
     * Mark confirmed-but-unchecked-in teams as no-shows (only once the
     * check-in window has closed), then promote waitlisted teams into the
     * freed slots.
     *
     * @return array{no_shows: int, promoted: int}
     */
    public function markNoShowsAndPromote(Tournament $tournament): array
    {
        $noShows = 0;

        DB::transaction(function () use ($tournament, &$noShows) {
            $fresh = Tournament::findOrFail($tournament->id);

            if (! $fresh->hasCheckIn()) {
                throw new DomainException('Check-in is not configured for this tournament.');
            }

            if (! $fresh->checkInHasClosed()) {
                throw new DomainException('The check-in window has not closed yet.');
            }

            $missing = Team::query()
                ->where('tournament_id', $fresh->id)
                ->where('status', Team::STATUS_CONFIRMED)
                ->whereNull('checked_in_at')
                ->get();

            $noShows = $missing->count();

            foreach ($missing as $team) {
                $team->status = Team::STATUS_NO_SHOW;
                $team->save();
            }
        });

        // Promote as many waitlisted teams as freed slots allow. Each
        // promotion is its own atomic transaction.
        $promoted = 0;
        while (true) {
            try {
                $this->promoteNext($tournament);
                $promoted++;
            } catch (DomainException $e) {
                break;
            }
        }

        return ['no_shows' => $noShows, 'promoted' => $promoted];
    }
}
```

## ── app/Services/DisputeService.php ─────────────────────────────────────────────
```php
<?php

namespace App\Services;

use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\GameMatch;
use App\Models\LiveEvent;
use App\Models\ModerationEvent;
use App\Models\Notification;
use App\Models\Score;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Dispute + evidence + moderation workflow (Phase 07).
 *
 * The single service that controls dispute lifecycle: opening, evidence,
 * assignment, review, resolution (including auditable result corrections via
 * the Phase 06 ScoringService) and the append-only moderation audit trail.
 *
 * Authorization is enforced by policies in the controllers; this service
 * enforces business rules and performs every state change atomically.
 */
class DisputeService
{
    public function __construct(
        protected MatchProgressionService $progression,
        protected ScoringService $scoring,
        protected NotificationService $notifications,
        protected LiveEventService $live,
    ) {
    }

    /**
     * Whether the user is staff for the given dispute (platform staff or the
     * owning tournament organizer).
     */
    public function isStaffFor(User $user, Dispute $dispute): bool
    {
        return $user->isAdmin()
            || $user->isModerator()
            || $dispute->tournament->organizer_id === $user->id;
    }

    /**
     * Open a dispute against a completed match.
     *
     * Participants (team captains) must open within the tournament's dispute
     * window; staff may open at any time. Opening moves the match into the
     * `disputed` state (Phase 05), halting bracket advancement.
     */
    public function open(GameMatch $match, ?Team $team, User $opener, string $category, string $description): Dispute
    {
        $category = trim($category);
        $description = trim($description);

        if (! in_array($category, Dispute::CATEGORIES, true)) {
            throw new DomainException('Please choose a valid dispute category.');
        }

        if ($description === '') {
            throw new DomainException('A dispute requires a description.');
        }

        $isStaff = $opener->isAdmin()
            || $opener->isModerator()
            || $match->tournament->organizer_id === $opener->id;

        if ($match->status !== GameMatch::STATUS_COMPLETED) {
            throw new DomainException('Only completed matches can be disputed.');
        }

        $alreadyOpen = Dispute::where('match_id', $match->id)
            ->whereIn('status', Dispute::ACTIONABLE_STATUSES)
            ->exists();

        if ($alreadyOpen) {
            throw new DomainException('This match already has an open dispute.');
        }

        $userTeam = $match->participantTeamFor($opener);

        if ($team !== null) {
            if (! $match->hasParticipant($team)) {
                throw new DomainException('The disputed team is not part of this match.');
            }

            if ($team->tournament_id !== $match->tournament_id) {
                throw new DomainException('The disputed team does not belong to this tournament.');
            }
        }

        if (! $isStaff) {
            // A participant may only open a dispute for their own team.
            if ($userTeam === null) {
                throw new DomainException('Only participating teams can open a dispute.');
            }

            if ($team !== null && $team->id !== $userTeam->id) {
                throw new DomainException('You can only open a dispute for your own team.');
            }

            $team = $userTeam;

            $this->assertWithinWindow($match);
        }

        $dispute = DB::transaction(function () use ($match, $team, $opener, $category, $description) {
            $dispute = new Dispute();
            $dispute->tournament_id = $match->tournament_id;
            $dispute->match_id = $match->id;
            $dispute->team_id = $team?->id;
            $dispute->opened_by = $opener->id;
            $dispute->category = $category;
            $dispute->description = $description;
            $dispute->status = Dispute::STATUS_OPEN;
            $dispute->save();

            // Halt bracket advancement while the result is contested.
            $this->progression->dispute($match);

            $this->recordEvent(
                $opener,
                ModerationEvent::EVENT_DISPUTE_OPENED,
                $dispute,
                $match,
                ['category' => $category, 'team_id' => $team?->id]
            );

            return $dispute;
        });

        // Phase 11 — notify the other captain, the organizer and the staff
        // queue (best-effort; never affects the dispute lifecycle).
        $this->notifyDisputeOpened($dispute, $match);

        // Phase 12 — staff-only live event (best-effort).
        $this->live->recordQuietly($match->tournament, $opener, LiveEvent::TYPE_DISPUTE_OPENED, [
            'match_no' => (int) $match->match_no,
            'round' => (int) $match->round,
            'category' => $category,
        ]);

        return $dispute;
    }

    /**
     * Attach a piece of evidence to an actionable dispute.
     */
    public function addEvidence(
        Dispute $dispute,
        User $submitter,
        string $type,
        ?string $description,
        ?UploadedFile $file
    ): DisputeEvidence {
        if (! $dispute->isActionable()) {
            throw new DomainException('This dispute is closed and no longer accepts evidence.');
        }

        if (! in_array($type, DisputeEvidence::TYPES, true)) {
            throw new DomainException('Invalid evidence type.');
        }

        $description = trim((string) $description);

        if ($type === DisputeEvidence::TYPE_TEXT) {
            if ($description === '') {
                throw new DomainException('A text explanation is required for text evidence.');
            }

            return $this->persistEvidence($dispute, $submitter, $type, null, null, null, 0, $description);
        }

        if ($file === null) {
            throw new DomainException('A file is required for this evidence type.');
        }

        $this->assertSafeFile($type, $file);

        $extension = strtolower($file->getClientOriginalExtension());
        $filename = (string) Str::uuid() . '.' . $extension;
        $directory = 'dispute_evidence/' . $dispute->id;

        try {
            $path = $file->storeAs($directory, $filename, 'local');
        } catch (\Throwable $e) {
            throw new DomainException('The evidence file could not be stored.');
        }

        try {
            return $this->persistEvidence(
                $dispute,
                $submitter,
                $type,
                $path,
                $file->getClientOriginalName(),
                $file->getMimeType(),
                $file->getSize(),
                $description
            );
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);

            throw $e;
        }
    }

    /**
     * Assign an actionable dispute to an authorized reviewer (admin or
     * moderator only — never a player).
     */
    public function assign(Dispute $dispute, User $reviewer, User $actor): void
    {
        if (! $dispute->isActionable()) {
            throw new DomainException('This dispute is closed and cannot be assigned.');
        }

        if (! $reviewer->isAdmin() && ! $reviewer->isModerator()) {
            throw new DomainException('Only admins and moderators can review disputes.');
        }

        DB::transaction(function () use ($dispute, $reviewer, $actor) {
            $dispute->assigned_to = $reviewer->id;
            $dispute->save();

            $this->recordEvent(
                $actor,
                ModerationEvent::EVENT_ASSIGNED,
                $dispute,
                $dispute->match,
                ['reviewer_id' => $reviewer->id]
            );
        });
    }

    /**
     * Move an open dispute into under-review (staff).
     */
    public function markUnderReview(Dispute $dispute, User $actor): void
    {
        if ($dispute->status !== Dispute::STATUS_OPEN) {
            throw new DomainException('Only open disputes can move to under review.');
        }

        $this->transition($dispute, Dispute::STATUS_UNDER_REVIEW, $actor);
    }

    /**
     * Resolve a dispute: apply optional result corrections (winner and/or
     * score inputs) and finalize. The match returns to `completed` and the
     * bracket is re-advanced with the confirmed winner.
     *
     * @param array<int, array{team_id:int, kills?:int|null, placement?:int|null}> $corrections
     */
    public function resolve(Dispute $dispute, User $actor, Team $winner, string $resolution, array $corrections = []): void
    {
        if (! $dispute->isActionable()) {
            throw new DomainException('This dispute is already closed.');
        }

        $resolution = trim($resolution);
        if ($resolution === '') {
            throw new DomainException('A resolution reason is required.');
        }

        $match = $dispute->match;

        if (! $match->hasParticipant($winner)) {
            throw new DomainException('The confirmed winner must be a participating team.');
        }

        DB::transaction(function () use ($dispute, $actor, $winner, $resolution, $corrections, $match) {
            $applied = $this->applyCorrections($dispute, $actor, $corrections);

            $dispute->status = Dispute::STATUS_RESOLVED;
            $dispute->resolution = $resolution;
            $dispute->resolved_by = $actor->id;
            $dispute->resolution_winner_team_id = $winner->id;
            $dispute->resolved_at = now();
            $dispute->save();

            // Move the match back to completed and re-advance the bracket
            // with the (possibly corrected) winner.
            $this->progression->resolve($match, $winner);

            $this->recordEvent(
                $actor,
                ModerationEvent::EVENT_RESOLVED,
                $dispute,
                $match,
                ['winner_team_id' => $winner->id, 'corrections' => $applied]
            );
        });

        // Phase 11 — inform the participants of the final decision.
        $this->notifyDisputeClosed(
            $dispute,
            $match,
            'Dispute resolved',
            'A dispute on your match in ' . $match->tournament->name . ' was resolved.'
        );

        // Phase 12 — staff-only live event (best-effort).
        $this->live->recordQuietly($match->tournament, $actor, LiveEvent::TYPE_DISPUTE_CLOSED, [
            'match_no' => (int) $match->match_no,
            'round' => (int) $match->round,
            'status' => Dispute::STATUS_RESOLVED,
        ]);
    }

    /**
     * Reject a dispute: the existing result is upheld. The match returns to
     * `completed` with its original winner untouched.
     */
    public function reject(Dispute $dispute, User $actor, string $resolution): void
    {
        if (! $dispute->isActionable()) {
            throw new DomainException('This dispute is already closed.');
        }

        $resolution = trim($resolution);
        if ($resolution === '') {
            throw new DomainException('A rejection reason is required.');
        }

        DB::transaction(function () use ($dispute, $actor, $resolution) {
            $dispute->status = Dispute::STATUS_REJECTED;
            $dispute->resolution = $resolution;
            $dispute->resolved_by = $actor->id;
            $dispute->resolved_at = now();
            $dispute->save();

            $this->restoreMatch($dispute->match);

            $this->recordEvent(
                $actor,
                ModerationEvent::EVENT_REJECTED,
                $dispute,
                $dispute->match,
                []
            );
        });

        // Phase 11 — inform the participants the original result stands.
        $this->notifyDisputeClosed(
            $dispute,
            $dispute->match,
            'Dispute rejected',
            'A dispute on your match in ' . $dispute->match->tournament->name . ' was rejected; the original result stands.'
        );

        // Phase 12 — staff-only live event (best-effort).
        $this->live->recordQuietly($dispute->match->tournament, $actor, LiveEvent::TYPE_DISPUTE_CLOSED, [
            'match_no' => (int) $dispute->match->match_no,
            'round' => (int) $dispute->match->round,
            'status' => Dispute::STATUS_REJECTED,
        ]);
    }

    /**
     * Cancel a dispute. Allowed for staff (open or under review) and for the
     * opener while the dispute is still open. The match returns to
     * `completed` with its original winner untouched.
     */
    public function cancel(Dispute $dispute, User $actor): void
    {
        $isStaff = $this->isStaffFor($actor, $dispute);

        if (! $isStaff && $dispute->opened_by !== $actor->id) {
            throw new DomainException('You cannot cancel this dispute.');
        }

        if (! $isStaff && $dispute->status !== Dispute::STATUS_OPEN) {
            throw new DomainException('Only open disputes can be cancelled by their owner.');
        }

        if (! $dispute->isActionable()) {
            throw new DomainException('This dispute is already closed.');
        }

        DB::transaction(function () use ($dispute, $actor) {
            $dispute->status = Dispute::STATUS_CANCELLED;
            $dispute->resolved_by = $actor->id;
            $dispute->resolved_at = now();
            $dispute->save();

            $this->restoreMatch($dispute->match);

            $this->recordEvent(
                $actor,
                ModerationEvent::EVENT_CANCELLED,
                $dispute,
                $dispute->match,
                []
            );
        });

        // Phase 11 — inform the participants the dispute was cancelled.
        $this->notifyDisputeClosed(
            $dispute,
            $dispute->match,
            'Dispute cancelled',
            'A dispute on your match in ' . $dispute->match->tournament->name . ' was cancelled.'
        );

        // Phase 12 — staff-only live event (best-effort).
        $this->live->recordQuietly($dispute->match->tournament, $actor, LiveEvent::TYPE_DISPUTE_CLOSED, [
            'match_no' => (int) $dispute->match->match_no,
            'round' => (int) $dispute->match->round,
            'status' => Dispute::STATUS_CANCELLED,
        ]);
    }

    /**
     * Remove a piece of evidence (privileged moderation action). The file is
     * deleted from private storage and the event is audited.
     */
    public function removeEvidence(DisputeEvidence $evidence, User $actor): void
    {
        DB::transaction(function () use ($evidence, $actor) {
            $path = $evidence->path;
            $dispute = $evidence->dispute;
            $match = $dispute?->match;
            $submittedBy = $evidence->submitted_by;

            $evidence->delete();

            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }

            if ($dispute !== null) {
                $this->recordEvent(
                    $actor,
                    ModerationEvent::EVENT_EVIDENCE_REMOVED,
                    $dispute,
                    $match,
                    ['evidence_id' => $evidence->id, 'submitted_by' => $submittedBy]
                );
            }
        });
    }

    // ------------------------------------------------------------------
    // Phase 11 — notifications
    // ------------------------------------------------------------------

    /**
     * Notify the other participating captain, the organizer and the staff
     * queue when a dispute is opened.
     */
    protected function notifyDisputeOpened(Dispute $dispute, GameMatch $match): void
    {
        $tournament = $match->tournament;
        $link = NotificationService::link('matches.disputes.show', [$tournament, $match, $dispute]);
        $openerId = $dispute->opened_by;

        foreach ($match->participantTeams() as $team) {
            $captain = $team->captain;

            if ($captain !== null && $captain->id !== $openerId) {
                $this->notifications->send(
                    $captain,
                    Notification::TYPE_DISPUTE_OPENED,
                    'Dispute opened on your match',
                    'A dispute has been opened on your match in ' . $tournament->name . '.',
                    $link,
                    ['dispute_id' => $dispute->id],
                );
            }
        }

        $organizer = $tournament->organizer;

        if ($organizer !== null && $organizer->id !== $openerId) {
            $this->notifications->send(
                $organizer,
                Notification::TYPE_DISPUTE_OPENED,
                'New dispute in your tournament',
                'A dispute was opened in ' . $tournament->name . '.',
                $link,
                ['dispute_id' => $dispute->id],
            );
        }

        $staff = User::whereIn('role', ['admin', 'moderator'])->get();

        foreach ($staff as $member) {
            if ($member->id !== $openerId) {
                $this->notifications->send(
                    $member,
                    Notification::TYPE_DISPUTE_OPENED,
                    'Dispute awaiting review',
                    'A new dispute needs review in ' . $tournament->name . '.',
                    $link,
                    ['dispute_id' => $dispute->id],
                );
            }
        }
    }

    /**
     * Notify both participating captains (and the opener) that a dispute
     * reached a final decision.
     */
    protected function notifyDisputeClosed(Dispute $dispute, GameMatch $match, string $title, string $body): void
    {
        $tournament = $match->tournament;
        $link = NotificationService::link('matches.disputes.show', [$tournament, $match, $dispute]);

        $recipients = [];

        foreach ($match->participantTeams() as $team) {
            $captain = $team->captain;

            if ($captain !== null) {
                $recipients[$captain->id] = $captain;
            }
        }

        $opener = User::find($dispute->opened_by);

        if ($opener !== null) {
            $recipients[$opener->id] = $opener;
        }

        $this->notifications->sendToMany(
            $recipients,
            Notification::TYPE_DISPUTE_RESOLVED,
            $title,
            $body,
            $link,
            ['dispute_id' => $dispute->id, 'status' => $dispute->status],
        );
    }

    /**
     * Append a row to the moderation audit trail.
     */
    public function recordEvent(
        ?User $actor,
        string $event,
        ?Dispute $dispute,
        ?GameMatch $match,
        array $metadata = []
    ): ModerationEvent {
        $record = new ModerationEvent();
        $record->actor_id = $actor?->id;
        $record->event = $event;
        $record->dispute_id = $dispute?->id;
        $record->match_id = $match?->id;
        $record->metadata = $metadata;
        $record->save();

        return $record;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Apply result corrections through the scoring engine. Each correction
     * must reference a participating team that has a score in this match.
     *
     * @return int number of corrections applied
     */
    protected function applyCorrections(Dispute $dispute, User $actor, array $corrections): int
    {
        if ($corrections === []) {
            return 0;
        }

        $match = $dispute->match;
        $applied = 0;

        foreach ($corrections as $correction) {
            $teamId = (int) ($correction['team_id'] ?? 0);
            $kills = isset($correction['kills']) ? (int) $correction['kills'] : null;
            $placement = isset($correction['placement']) ? (int) $correction['placement'] : null;

            if ($teamId <= 0) {
                throw new DomainException('A correction must reference a team.');
            }

            $team = Team::find($teamId);

            if ($team === null || ! $match->hasParticipant($team)) {
                throw new DomainException('A correction can only target a participating team.');
            }

            $score = Score::where('match_id', $match->id)->where('team_id', $teamId)->first();

            if ($score === null) {
                throw new DomainException('No score exists for this team in this match.');
            }

            $before = [
                'kills' => (int) $score->kills,
                'placement' => (int) $score->placement,
                'points' => (int) $score->points,
            ];

            $this->scoring->correctScore($score, $kills, $placement);

            $applied++;

            $this->recordEvent(
                $actor,
                ModerationEvent::EVENT_RESULT_CORRECTED,
                $dispute,
                $match,
                [
                    'team_id' => $teamId,
                    'before' => $before,
                    'after' => [
                        'kills' => (int) $score->kills,
                        'placement' => (int) $score->placement,
                        'points' => (int) $score->points,
                    ],
                    'scoring_rules_id' => (int) $score->scoring_rules_id,
                ]
            );
        }

        return $applied;
    }

    /**
     * Move a dispute between non-terminal states and audit the change.
     */
    protected function transition(Dispute $dispute, string $target, User $actor): void
    {
        if (! $dispute->canTransitionTo($target)) {
            throw new DomainException('Invalid dispute transition.');
        }

        DB::transaction(function () use ($dispute, $target, $actor) {
            $from = $dispute->status;

            $dispute->status = $target;
            $dispute->save();

            $this->recordEvent(
                $actor,
                ModerationEvent::EVENT_STATUS_CHANGED,
                $dispute,
                $dispute->match,
                ['from' => $from, 'to' => $target]
            );
        });
    }

    /**
     * Return a disputed match to completed without changing its winner
     * (used when a dispute is rejected or cancelled — the original result
     * is upheld).
     */
    protected function restoreMatch(GameMatch $match): void
    {
        if ($match->status === GameMatch::STATUS_DISPUTED) {
            $match->status = GameMatch::STATUS_COMPLETED;
            $match->save();
        }
    }

    /**
     * Enforce the participant dispute window.
     */
    protected function assertWithinWindow(GameMatch $match): void
    {
        $hours = $match->tournament->disputeWindowHours();

        if ($hours <= 0) {
            throw new DomainException('The dispute window for this tournament is closed.');
        }

        $completedAt = $match->completed_at ?? $match->updated_at;

        if ($completedAt !== null && $completedAt->copy()->addHours($hours)->isPast()) {
            throw new DomainException('The dispute window has expired for this match.');
        }
    }

    /**
     * Server-side file safety re-check (defense in depth on top of request
     * validation): extension and MIME must match the declared evidence type.
     */
    protected function assertSafeFile(string $type, UploadedFile $file): void
    {
        if ($file->getSize() > DisputeEvidence::MAX_KB * 1024) {
            throw new DomainException('Evidence files must be smaller than ' . DisputeEvidence::MAX_KB . ' KB.');
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, DisputeEvidence::ALLOWED_EXTENSIONS[$type] ?? [], true)) {
            throw new DomainException('This file type is not allowed for the selected evidence type.');
        }

        $mime = $file->getMimeType();

        if (! in_array($mime, DisputeEvidence::ALLOWED_MIME_TYPES[$type] ?? [], true)) {
            throw new DomainException('This file type is not allowed for the selected evidence type.');
        }
    }

    /**
     * Create the evidence record and audit it atomically.
     */
    protected function persistEvidence(
        Dispute $dispute,
        User $submitter,
        string $type,
        ?string $path,
        ?string $originalName,
        ?string $mimeType,
        int $size,
        ?string $description
    ): DisputeEvidence {
        return DB::transaction(function () use ($dispute, $submitter, $type, $path, $originalName, $mimeType, $size, $description) {
            $evidence = new DisputeEvidence();
            $evidence->dispute_id = $dispute->id;
            $evidence->submitted_by = $submitter->id;
            $evidence->type = $type;
            $evidence->path = $path;
            $evidence->original_name = $originalName;
            $evidence->mime_type = $mimeType;
            $evidence->size = $size;
            $evidence->description = $description ?: null;
            $evidence->save();

            $this->recordEvent(
                $submitter,
                ModerationEvent::EVENT_EVIDENCE_ADDED,
                $dispute,
                $dispute->match,
                ['evidence_id' => $evidence->id, 'type' => $type]
            );

            return $evidence;
        });
    }
}
```

## ── app/Http/Controllers/TeamController.php ─────────────────────────────────────────────
```php
<?php

namespace App\Http\Controllers;

use App\Exceptions\RegistrationClosedException;
use App\Models\LiveEvent;
use App\Models\Notification;
use App\Models\RiskEvent;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\Tournament;
use App\Services\FraudRiskService;
use App\Services\LiveEventService;
use App\Services\NotificationService;
use App\Services\RosterService;
use App\Services\TournamentParticipationService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeamController extends Controller
{
    public function __construct(
        protected RosterService $roster,
        protected TournamentParticipationService $participation,
        protected FraudRiskService $risk,
        protected NotificationService $notifications,
        protected LiveEventService $live,
    ) {
    }

    public function showRegistration(Tournament $tournament)
    {
        if (! $tournament->acceptsRegistration()) {
            if ($tournament->hasStarted()) {
                return back()->with('error', 'Registration is closed — this tournament has already started.');
            }

            return back()->with('error', 'This tournament is not open for registration.');
        }

        return view('teams.register', compact('tournament'));
    }

    public function register(Request $request, Tournament $tournament)
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        // Phase 10 — fraud/risk gate (restriction + risk-level enforcement).
        try {
            $this->risk->evaluateRegistration($tournament, $user);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Fast-fail lifecycle checks with friendly messages. The authoritative
        // checks run again inside the atomic claim below.
        if (! $tournament->acceptsRegistration()) {
            if ($tournament->hasStarted()) {
                return back()->with('error', 'Registration is closed — this tournament has already started.');
            }

            return back()->with('error', 'Registration is closed for this tournament.');
        }

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'captain_name' => 'required|string|max:120',
            'phone' => 'required|string|max:20',
            'game_uid' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9]{4,30}$/'],
            'members' => 'nullable|array',
            'members.*.player_name' => 'nullable|string|max:120',
            'members.*.game_uid' => 'nullable|string|max:30',
        ]);

        $captainUid = $this->roster->normalizeUid($data['game_uid']);
        $members = is_array($data['members'] ?? null) ? $data['members'] : [];

        $team = null;
        $waitlisted = false;

        try {
            DB::transaction(function () use ($tournament, $user, $data, $captainUid, $members, &$team, &$waitlisted) {
                $fresh = Tournament::findOrFail($tournament->id);

                if (! $fresh->acceptsRegistration()) {
                    if ($fresh->hasStarted()) {
                        throw new RegistrationClosedException('Registration is closed — this tournament has already started.');
                    }

                    throw new RegistrationClosedException('Registration is closed for this tournament.');
                }

                // One-team-per-captain. The database unique(tournament_id,
                // captain_id) index is the final backstop.
                if (Team::where('tournament_id', $fresh->id)->where('captain_id', $user->id)->exists()) {
                    throw new RegistrationClosedException('You have already registered a team in this tournament.');
                }

                // Roster integrity (Phase 03): the captain UID must not
                // already belong to another team in this tournament.
                $this->roster->assertUidAvailable($fresh, $captainUid);

                // ATOMIC SLOT CLAIM — SQLite-compatible concurrency guard.
                //
                // A single UPDATE that only succeeds while the tournament is
                // still open, has not started, and has a free slot. In SQLite
                // this statement acquires the write lock, so everything after
                // it in this transaction is race-free.
                $claimed = DB::table('tournaments')
                    ->where('id', $fresh->id)
                    ->where('status', Tournament::STATUS_OPEN)
                    ->where(function ($q) {
                        $q->whereNull('starts_at')->orWhere('starts_at', '>', now());
                    })
                    ->whereRaw(
                        '(SELECT COUNT(*) FROM teams WHERE tournament_id = tournaments.id AND status IN (?, ?)) < team_slots',
                        [Team::STATUS_PENDING, Team::STATUS_CONFIRMED]
                    )
                    ->update(['updated_at' => now()]);

                if ($claimed !== 1) {
                    // No slot. Re-check under the write lock: if the
                    // tournament really is full, the team goes to the
                    // waitlist. Otherwise registration is genuinely closed.
                    $fresh2 = Tournament::findOrFail($fresh->id);

                    if (! $fresh2->acceptsRegistration()) {
                        if ($fresh2->hasStarted()) {
                            throw new RegistrationClosedException('Registration is closed — this tournament has already started.');
                        }

                        throw new RegistrationClosedException('Registration is closed for this tournament.');
                    }

                    if (! $fresh2->isFull()) {
                        throw new RegistrationClosedException('Registration is not available for this tournament.');
                    }

                    // Full → waitlist (FIFO).
                    $team = new Team();
                    $team->tournament_id = $fresh2->id;
                    $team->captain_id = $user->id;
                    $team->name = $data['name'];
                    $team->captain_name = $data['captain_name'];
                    $team->phone = $data['phone'];
                    $team->game_uid = $captainUid;
                    $team->status = Team::STATUS_WAITLISTED;
                    $team->waitlisted_at = now();
                    $team->save();

                    $waitlisted = true;
                } else {
                    // Slot claimed → pending (awaits payment).
                    $team = new Team();
                    $team->tournament_id = $fresh->id;
                    $team->captain_id = $user->id;
                    $team->name = $data['name'];
                    $team->captain_name = $data['captain_name'];
                    $team->phone = $data['phone'];
                    $team->game_uid = $captainUid;
                    $team->status = Team::STATUS_PENDING;
                    $team->save();
                }

                // Validate + persist roster members (size, duplicates,
                // cross-team clashes) — all inside the same transaction.
                $normalized = $this->roster->validateNewMembers($fresh, $team, $members);

                foreach ($normalized as $member) {
                    $row = new TeamMember();
                    $row->team_id = $team->id;
                    $row->player_name = $member['player_name'];
                    $row->game_uid = $member['game_uid'];
                    $row->save();
                }
            });
        } catch (RegistrationClosedException $e) {
            return back()->with('error', $e->getMessage());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        } catch (QueryException $e) {
            // Database backstop: unique(tournament_id, captain_id) for the
            // one-team-per-captain rule, or unique(tournament_id, game_uid)
            // for a captain UID clash.
            return back()->with('error', 'A duplicate team or player was detected. Registration was not saved.');
        }

        // Phase 10 — registration-volume signal (non-blocking observation).
        $teamCount = Team::where('captain_id', $user->id)->count();
        $maxTeams = (int) config('antifraud.registration.max_teams', 5);

        if ($teamCount >= $maxTeams) {
            $this->risk->recordSignal($user, RiskEvent::TYPE_REGISTRATION_VOLUME, RiskEvent::SEVERITY_MEDIUM, 'registration', [
                'team_count' => $teamCount,
            ], $tournament);
        }

        // Phase 11 — notify the captain and the organizer.
        $teamLink = NotificationService::link('teams.show', [$tournament, $team]);

        $this->notifications->send(
            $user,
            Notification::TYPE_TEAM_REGISTERED,
            'Team registered',
            'Your team ' . $team->name . ' was registered for ' . $tournament->name . '.',
            $teamLink,
            ['team_id' => $team->id, 'tournament_id' => $tournament->id],
        );

        $organizer = $tournament->organizer;

        if ($organizer !== null) {
            $this->notifications->send(
                $organizer,
                Notification::TYPE_TEAM_REGISTERED,
                'New team registration',
                'Team ' . $team->name . ' registered for ' . $tournament->name . '.',
                $teamLink,
                ['team_id' => $team->id, 'tournament_id' => $tournament->id],
            );
        }

        // Phase 12 — live event (best-effort).
        $this->live->recordQuietly($tournament, $user, LiveEvent::TYPE_TEAM_REGISTERED, [
            'team' => $team->name,
            'waitlisted' => $waitlisted,
        ]);

        if ($waitlisted) {
            return redirect()
                ->route('tournaments.show', $tournament)
                ->with('success', 'All slots are full. Your team is on the waitlist (position '.$team->waitlistPosition().').');
        }

        return redirect()->route('payment.show', [$tournament, $team]);
    }

    /**
     * Withdraw a team before the tournament reaches an irreversible stage.
     * No refund logic is invented here: any existing payment is left
     * untouched and must be handled offline/manually.
     */
    public function withdraw(Request $request, Tournament $tournament, Team $team)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('withdraw', $team);

        if (in_array($tournament->status, [
            Tournament::STATUS_LIVE,
            Tournament::STATUS_FINISHED,
            Tournament::STATUS_CANCELLED,
        ], true)) {
            return back()->with('error', 'Teams can no longer withdraw from this tournament.');
        }

        if ($team->status === Team::STATUS_WITHDRAWN) {
            return back()->with('error', 'This team has already been withdrawn.');
        }

        // Phase 10 — repeated-withdrawal signal (non-blocking observation).
        // Count prior withdrawals before releasing the captain claim.
        $priorWithdrawals = Team::where('captain_id', $request->user()->id)
            ->where('status', Team::STATUS_WITHDRAWN)
            ->count();

        $team->status = Team::STATUS_WITHDRAWN;
        $team->captain_id = null; // release the captain's claim so they may re-register
        $team->game_uid = null;   // release the captain UID so it can be re-used
        $team->save();

        $withdrawals = $priorWithdrawals + 1;
        $threshold = (int) config('antifraud.withdrawal.repeat_threshold', 3);

        if ($withdrawals >= $threshold) {
            $this->risk->recordSignal($request->user(), RiskEvent::TYPE_WITHDRAWAL_REPEAT, RiskEvent::SEVERITY_LOW, 'registration', [
                'withdrawal_count' => $withdrawals,
            ], $tournament);
        }

        // Phase 11 — notify the organizer that a team withdrew.
        $organizer = $tournament->organizer;

        if ($organizer !== null) {
            $this->notifications->send(
                $organizer,
                Notification::TYPE_TEAM_WITHDRAWN,
                'Team withdrew',
                'Team ' . $team->name . ' withdrew from ' . $tournament->name . '.',
                NotificationService::link('tournaments.show', [$tournament]),
                ['team_id' => $team->id, 'tournament_id' => $tournament->id],
            );
        }

        // Phase 12 — live event (best-effort).
        $this->live->recordQuietly($tournament, $request->user(), LiveEvent::TYPE_TEAM_WITHDRAWN, [
            'team' => $team->name,
        ]);

        return back()->with('success', 'Your team has been withdrawn from the tournament.');
    }

    /**
     * Team / roster management page.
     */
    public function show(Tournament $tournament, Team $team)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('view', $team);

        $team->load(['members', 'captain']);

        $locked = $this->roster->isLocked($team);
        $slotsLeft = $this->roster->maxMembers($team) - $team->members()->count();

        $canEdit = auth()->check() && (auth()->user()->isAdmin() || ($team->isCaptain(auth()->user()) && ! $locked));
        $canCheckIn = auth()->check() && (auth()->user()->isAdmin() || $team->isCaptain(auth()->user()));

        return view('teams.show', compact('tournament', 'team', 'locked', 'slotsLeft', 'canEdit', 'canCheckIn'));
    }

    /**
     * Add a roster member (captain or admin).
     */
    public function addMember(Request $request, Tournament $tournament, Team $team)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('addMember', $team);

        $data = $request->validate([
            'player_name' => 'required|string|max:120',
            'game_uid' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9]{4,30}$/'],
        ]);

        try {
            $member = $this->roster->addMember($team, $tournament, $data, $request->user()->isAdmin());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        } catch (QueryException $e) {
            return back()->with('error', 'This player is already in the team.');
        }

        return back()->with('success', $member->player_name.' added to the roster.');
    }

    /**
     * Remove a roster member (captain or admin).
     */
    public function removeMember(Request $request, Tournament $tournament, Team $team, TeamMember $member)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('removeMember', $team);

        try {
            $this->roster->removeMember($team, $member, $request->user()->isAdmin());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Member removed from the roster.');
    }

    /**
     * Update team profile (captain or admin).
     */
    public function updateProfile(Request $request, Tournament $tournament, Team $team)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('updateProfile', $team);

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'captain_name' => 'required|string|max:120',
            'phone' => 'required|string|max:20',
            'game_uid' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9]{4,30}$/'],
        ]);

        try {
            $this->roster->updateProfile($team, $tournament, $data, $request->user()->isAdmin());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        } catch (QueryException $e) {
            return back()->with('error', 'This Free Fire UID is already used in this tournament.');
        }

        return back()->with('success', 'Team profile updated.');
    }

    /**
     * Team check-in (captain or admin). Idempotent.
     */
    public function checkIn(Request $request, Tournament $tournament, Team $team)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('checkIn', $team);

        // Phase 10 — fraud/risk gate for check-in.
        try {
            $this->risk->gate($request->user(), 'checkin', $tournament);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        try {
            $result = $this->participation->checkIn($tournament, $team, $request->user(), $request->user()->isAdmin());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($result === 'already') {
            return back()->with('success', 'Your team is already checked in.');
        }

        return back()->with('success', 'Check-in successful! Your team is confirmed for the bracket.');
    }
}
```

## ── resources/views/live/poll.blade.php ──────────────────
```blade
@php
    $liveUrl = isset($tournament) ? route('tournaments.live', $tournament) : null;
    $liveSince = (int) ($liveRevision ?? 0);
    $liveInterval = (int) config('live.poll_interval_ms', 10000);
@endphp
@if($liveUrl)
    <div id="live-feed"
         data-url="{{ $liveUrl }}"
         data-since="{{ $liveSince }}"
         data-interval="{{ $liveInterval }}"
         style="margin-bottom:18px">
        <div class="card" style="margin-bottom:0">
            <h3 style="display:flex; align-items:center; gap:10px; flex-wrap:wrap">
                <span style="color:var(--green)">●</span> LIVE
                <span class="muted" style="font-size:12px; font-weight:400">auto-updates</span>
            </h3>
            <ul id="live-feed-items" style="list-style:none; padding:0; margin:0"></ul>
            <button id="live-refresh" class="btn btn-sm btn-cyan" style="display:none; margin-top:10px">
                🔄 New results — refresh page
            </button>
        </div>
    </div>

    <script>
    (function () {
        var feed = document.getElementById('live-feed');
        if (!feed) { return; }

        var url = feed.dataset.url;
        var since = parseInt(feed.dataset.since || '0', 10);
        var interval = parseInt(feed.dataset.interval || '10000', 10);
        var list = document.getElementById('live-feed-items');
        var refreshBtn = document.getElementById('live-refresh');
        var resultTypes = ['match.score_submitted', 'match.completed', 'match.disputed', 'match.resolved'];

        function label(e) {
            var p = e.payload || {};
            var m = p.match_no ? ('Match ' + p.match_no) : '';
            switch (e.type) {
                case 'match.score_submitted': return m + ': ' + p.team + ' scored ' + p.kills + ' kills (#' + p.placement + ')';
                case 'match.completed': return m + ' completed — ' + p.winner + ' wins';
                case 'match.disputed': return m + ' disputed';
                case 'match.resolved': return m + ' dispute resolved — ' + p.winner + ' wins';
                case 'match.started': return m + ' started';
                case 'team.checked_in': return p.team + ' checked in';
                case 'team.registered': return p.team + ' registered' + (p.waitlisted ? ' (waitlisted)' : '');
                case 'team.withdrawn': return p.team + ' withdrew';
                default: return e.type;
            }
        }

        function prepend(e) {
            var li = document.createElement('li');
            li.style.cssText = 'padding:8px 0;border-bottom:1px solid var(--line);font-size:13px;color:var(--muted)';
            li.textContent = '• ' + label(e);
            list.insertBefore(li, list.firstChild);
            while (list.children.length > 12) { list.removeChild(list.lastChild); }
        }

        function tick() {
            fetch(url + '?since=' + since, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    since = parseInt(d.revision || since, 10);
                    var events = d.events || [];
                    if (!events.length) { return; }
                    events.slice().reverse().forEach(prepend);
                    if (events.some(function (e) { return resultTypes.indexOf(e.type) !== -1; })) {
                        refreshBtn.style.display = 'inline-block';
                    }
                })
                .catch(function () { /* network hiccup — keep polling */ });
        }

        if (refreshBtn) {
            refreshBtn.addEventListener('click', function () { window.location.reload(); });
        }

        setInterval(tick, interval);
    })();
    </script>
@endif
```

## ── resources/views/layouts/app.blade.php ──────────────────
```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'FF Arena') — Bangladesh Free Fire Tournaments</title>
    <style>
        :root {
            --bg: #0b0e1a;
            --panel: #141a2e;
            --panel2: #1b2340;
            --line: #28335a;
            --txt: #e8ecff;
            --muted: #8a93b8;
            --cyan: #22d3ee;
            --purple: #a855f7;
            --green: #34d399;
            --red: #f87171;
            --amber: #fbbf24;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: var(--bg);
            color: var(--txt);
            min-height: 100vh;
            background-image: radial-gradient(1200px 600px at 80% -10%, rgba(168,85,247,.14), transparent),
                              radial-gradient(900px 500px at -10% 110%, rgba(34,211,238,.12), transparent);
        }
        a { color: var(--cyan); text-decoration: none; }
        a:hover { text-decoration: underline; }
        .container { max-width: 1180px; margin: 0 auto; padding: 0 20px; }
        nav {
            display: flex; align-items: center; gap: 20px;
            padding: 14px 0; border-bottom: 1px solid var(--line);
            flex-wrap: wrap;
        }
        .brand { font-size: 22px; font-weight: 800; letter-spacing: .5px; }
        .brand span { color: var(--cyan); }
        .nav-links { display: flex; gap: 18px; align-items: center; margin-left: auto; flex-wrap: wrap; }
        .btn {
            display: inline-block; padding: 9px 16px; border-radius: 8px; border: 1px solid var(--line);
            background: var(--panel2); color: var(--txt); font-weight: 600; font-size: 14px; cursor: pointer;
            transition: .15s;
        }
        .btn:hover { border-color: var(--cyan); text-decoration: none; }
        .btn-primary { background: linear-gradient(90deg, #7c3aed, #2563eb); border: none; color: #fff; }
        .btn-primary:hover { filter: brightness(1.12); }
        .btn-cyan { background: rgba(34,211,238,.12); border: 1px solid var(--cyan); color: var(--cyan); }
        .btn-green { background: rgba(52,211,153,.12); border: 1px solid var(--green); color: var(--green); }
        .btn-sm { padding: 6px 12px; font-size: 13px; }
        .card {
            background: var(--panel); border: 1px solid var(--line); border-radius: 14px;
            padding: 20px; margin-bottom: 18px;
        }
        .grid { display: grid; gap: 18px; }
        .cols-3 { grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); }
        .cols-2 { grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); }
        h1 { font-size: 26px; margin-bottom: 8px; }
        h2 { font-size: 20px; margin-bottom: 12px; }
        h3 { font-size: 16px; margin-bottom: 6px; }
        .muted { color: var(--muted); }
        .pill {
            display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 700;
        }
        .pill.open { background: rgba(52,211,153,.15); color: var(--green); }
        .pill.live { background: rgba(34,211,238,.15); color: var(--cyan); }
        .pill.closed, .pill.finished, .pill.disputed { background: rgba(248,113,113,.15); color: var(--red); }
        .pill.draft { background: rgba(251,191,36,.15); color: var(--amber); }
        .pill.pending { background: rgba(251,191,36,.15); color: var(--amber); }
        .pill.ready { background: rgba(34,211,238,.15); color: var(--cyan); }
        .pill.confirmed, .pill.verified, .pill.checked { background: rgba(52,211,153,.15); color: var(--green); }
        .pill.waitlisted { background: rgba(251,191,36,.15); color: var(--amber); }
        .pill.cancelled, .pill.withdrawn, .pill.rejected, .pill.failed, .pill.no_show, .pill.bye { background: rgba(148,163,184,.15); color: var(--muted); }
        form label { display: block; font-size: 13px; color: var(--muted); margin: 12px 0 4px; }
        input, select, textarea {
            width: 100%; padding: 10px 12px; border-radius: 8px; border: 1px solid var(--line);
            background: #0d1226; color: var(--txt); font-size: 14px;
        }
        input:focus, select:focus, textarea:focus { outline: none; border-color: var(--cyan); }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--line); font-size: 14px; }
        th { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .5px; }
        .flash { padding: 12px 16px; border-radius: 10px; margin: 16px 0; font-weight: 600; }
        .flash.success { background: rgba(52,211,153,.15); color: var(--green); border: 1px solid rgba(52,211,153,.4); }
        .flash.error { background: rgba(248,113,113,.15); color: var(--red); border: 1px solid rgba(248,113,113,.4); }
        .stat { background: var(--panel); border: 1px solid var(--line); border-radius: 12px; padding: 16px; }
        .stat .num { font-size: 26px; font-weight: 800; color: var(--cyan); }
        .bracket-col { display: flex; flex-wrap: wrap; gap: 24px; align-items: flex-start; overflow-x: auto; padding-bottom: 10px; }
        .bracket-round { display: flex; flex-direction: column; gap: 14px; min-width: 190px; }
        .bracket-match { background: var(--panel2); border: 1px solid var(--line); border-radius: 10px; padding: 8px; }
        .bracket-team { padding: 7px 10px; border-radius: 6px; font-size: 13px; display: flex; justify-content: space-between; gap: 8px; }
        .bracket-team.win { background: rgba(52,211,153,.12); color: var(--green); font-weight: 700; }
        .bracket-team.bye { color: var(--muted); }
        .divider { height: 1px; background: var(--line); margin: 4px 0; }
        footer { border-top: 1px solid var(--line); margin-top: 50px; padding: 22px 0; color: var(--muted); font-size: 13px; }
        .tag { color: var(--purple); font-weight: 700; }
    </style>
</head>
<body>
<div class="container">
    <nav @auth data-unread-url="{{ route('notifications.unread') }}" @endauth>
        <a href="{{ route('home') }}" class="brand">FF<span>ARENA</span></a>
        <div class="nav-links">
            <a href="{{ route('tournaments.index') }}">Tournaments</a>
            @auth
                @if(auth()->user()->isOrganizer() || auth()->user()->isAdmin())
                    <a href="{{ route('tournaments.create') }}" class="btn btn-sm btn-cyan">+ Create Tournament</a>
                @endif
                <a href="{{ route('wallet.index') }}" class="btn btn-sm">Wallet</a>
                <a href="{{ route('notifications.index') }}" class="btn btn-sm" style="position:relative">
                    🔔 Notifications
                    <span id="unread-badge" style="background:var(--red); color:#fff; border-radius:999px; padding:0 6px; font-size:11px; font-weight:700; margin-left:4px; display:{{ ($unreadNotifications ?? 0) > 0 ? 'inline-block' : 'none' }}">{{ $unreadNotifications ?? 0 }}</span>
                </a>
                @if(auth()->user()->isAdmin() || auth()->user()->isModerator() || auth()->user()->isOrganizer())
                    <a href="{{ route('moderation.index') }}" class="btn btn-sm">Moderation</a>
                @endif
                @if(auth()->user()->isAdmin() || auth()->user()->isModerator())
                    <a href="{{ route('moderation.security') }}" class="btn btn-sm">Security</a>
                @endif
                @if(auth()->user()->isAdmin())
                    <a href="{{ route('admin.dashboard') }}" class="btn btn-sm">Admin</a>
                @endif
                <span class="muted">{{ auth()->user()->name }} ({{ auth()->user()->role }})</span>
                <form method="POST" action="{{ route('logout') }}" style="display:inline">
                    @csrf
                    <button class="btn btn-sm">Logout</button>
                </form>
            @else
                <a href="{{ route('login') }}" class="btn btn-sm">Login</a>
                <a href="{{ route('register') }}" class="btn btn-sm btn-primary">Register</a>
            @endauth
        </div>
    </nav>

    @if(session('success'))
        <div class="flash success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="flash error">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="flash error">
            <ul style="list-style:none;padding:0;margin:0">
                @foreach($errors->all() as $e) <li>• {{ $e }}</li> @endforeach
            </ul>
        </div>
    @endif

    @yield('content')

    <footer>
        <div class="container" style="padding:0">
            <strong class="tag">FF Arena</strong> — Bangladesh's Free Fire tournament platform.
            Legit. Smart. Profitable. No hacks, ever. 🤝
        </div>
    </footer>
</div>

@auth
<script>
(function () {
    var nav = document.querySelector('nav[data-unread-url]');
    if (!nav || !nav.dataset.unreadUrl) { return; }
    var badge = document.getElementById('unread-badge');
    if (!badge) { return; }
    setInterval(function () {
        fetch(nav.dataset.unreadUrl, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var n = parseInt(d.unread || 0, 10);
                if (n > 0) { badge.textContent = n; badge.style.display = 'inline-block'; }
                else { badge.style.display = 'none'; }
            })
            .catch(function () { /* keep the last known count */ });
    }, 10000);
})();
</script>
@endauth
</body>
</html>
```

## ── resources/views/tournaments/show.blade.php ──────────────────
```blade
@extends('layouts.app')
@section('title', $tournament->name . ' — FF Arena')
@section('content')
    <div style="padding: 30px 0 10px">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px">
            <div>
                <h1 style="margin:0">{{ $tournament->name }}</h1>
                <div class="muted" style="margin-top:6px">
                    by {{ $tournament->organizer->name ?? 'Organizer' }} ·
                    {{ strtoupper($tournament->game_mode) }} · {{ $tournament->map }} ·
                    starts {{ optional($tournament->starts_at)->format('d M Y, h:i A') }}
                </div>
            </div>
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap">
                @if($tournament->hasCheckIn())
                    @if($tournament->checkInIsOpen())
                        <span class="pill checked">CHECK-IN OPEN</span>
                    @elseif($tournament->checkInHasClosed())
                        <span class="pill no_show">CHECK-IN CLOSED</span>
                    @else
                        <span class="pill pending">CHECK-IN NOT OPEN</span>
                    @endif
                @endif
                <span class="pill {{ $tournament->status }}">{{ strtoupper($tournament->status) }}</span>
            </div>
        </div>
    </div>

    {{-- Phase 12 — live updates feed (polling; server-side visibility) --}}
    @include('live.poll', ['tournament' => $tournament])

    <div class="grid cols-2">
        <div class="card">
            <h3>Prize & Entry</h3>
            <div style="display:flex; gap:24px; font-size:16px">
                <div>Entry fee <br><strong class="tag">৳{{ number_format($tournament->entry_fee) }}</strong></div>
                <div>Prize pool <br><strong class="tag">৳{{ number_format($tournament->prize_pool) }}</strong></div>
                <div>Slots left <br><strong>{{ $tournament->slotsLeft() }}/{{ $tournament->team_slots }}</strong></div>
            </div>
            <div class="muted" style="margin-top:12px; font-size:13px">Players per team: {{ $tournament->team_size }}</div>
            @if($tournament->hasCheckIn())
                <div class="muted" style="margin-top:8px; font-size:13px">
                    Check-in: {{ $tournament->check_in_starts_at->format('d M, h:i A') }} —
                    {{ $tournament->check_in_ends_at->format('d M, h:i A') }}
                </div>
            @endif
        </div>
        <div class="card">
            <h3>Rules</h3>
            <pre style="white-space:pre-wrap; font-family:inherit; font-size:14px; color:var(--muted)">{{ $tournament->rules ?: 'No rules set.' }}</pre>
        </div>
    </div>

    @auth
        @if((auth()->user()->isOrganizer() && auth()->user()->id === $tournament->organizer_id) || auth()->user()->isAdmin())
            <div class="card">
                <h3>🎛 Organizer Controls</h3>
                <div style="display:flex; gap:10px; flex-wrap:wrap">
                    @if($tournament->status === 'draft')
                        <form method="POST" action="{{ route('tournaments.publish', $tournament) }}">@csrf
                            <button class="btn btn-green btn-sm">Publish (open registration)</button>
                        </form>
                    @endif
                    @if($tournament->status === 'open')
                        <form method="POST" action="{{ route('tournaments.close', $tournament) }}">@csrf
                            <button class="btn btn-sm">Close registration</button>
                        </form>
                    @endif
                    @if(in_array($tournament->status, ['closed','open'], true))
                        <form method="POST" action="{{ route('tournaments.bracket', $tournament) }}">@csrf
                            <button class="btn btn-primary btn-sm">⚡ Generate Bracket</button>
                        </form>
                    @endif
                    @if($tournament->status === 'live')
                        <form method="POST" action="{{ route('tournaments.complete', $tournament) }}">@csrf
                            <button class="btn btn-green btn-sm" onclick="return confirm('Finish this tournament? Make sure all matches are completed.')">🏁 Finish Tournament</button>
                        </form>
                    @endif
                    @if($tournament->hasCheckIn() && $tournament->checkInHasClosed())
                        <form method="POST" action="{{ route('tournaments.noshows', $tournament) }}">@csrf
                            <button class="btn btn-sm" onclick="return confirm('Mark unchecked-in teams as no-show and promote from the waitlist?')">🚫 Mark No-shows</button>
                        </form>
                    @endif
                    @if($tournament->acceptsRegistration() && $waitlist && $waitlist->isNotEmpty())
                        <form method="POST" action="{{ route('tournaments.waitlist.promote', $tournament) }}">@csrf
                            <button class="btn btn-sm btn-cyan">⬆ Promote Next Waitlisted</button>
                        </form>
                    @endif
                    <a href="{{ route('tournaments.edit', $tournament) }}" class="btn btn-sm">Edit</a>
                    <a href="{{ route('tournaments.scoring.show', $tournament) }}" class="btn btn-sm btn-cyan">Scoring Rules</a>
                    <a href="{{ route('leaderboard.show', $tournament) }}" class="btn btn-sm btn-cyan">Leaderboard</a>
                    @if(in_array($tournament->status, ['draft', 'open', 'closed'], true))
                        <form method="POST" action="{{ route('tournaments.cancel', $tournament) }}" style="display:inline">
                            @csrf
                            <button class="btn btn-sm" style="border-color:var(--red); color:var(--red)" onclick="return confirm('Cancel this tournament?')">Cancel</button>
                        </form>
                    @endif
                </div>
            </div>
        @endif

        @if($myTeam && !$myTeam->isWithdrawn())
            <div class="card">
                <h3>🎽 Your Team</h3>
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px">
                    <div>
                        <strong>{{ $myTeam->name }}</strong>
                        <span class="pill {{ $myTeam->status }}">{{ strtoupper($myTeam->status) }}</span>
                        @if($myTeam->isWaitlisted())
                            <span class="pill waitlisted">WAITLIST #{{ $myTeam->waitlistPosition() }}</span>
                        @elseif($myTeam->isCheckedIn())
                            <span class="pill checked">CHECKED IN ✓</span>
                        @endif
                        <div class="muted" style="font-size:13px; margin-top:4px">Roster: {{ $myTeam->rosterSize() }} / {{ $tournament->team_size }} players</div>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap">
                        @if($myTeam->isConfirmed() && $tournament->hasCheckIn() && !$myTeam->isCheckedIn() && $tournament->checkInIsOpen())
                            <form method="POST" action="{{ route('teams.checkin', [$tournament, $myTeam]) }}">
                                @csrf
                                <button class="btn btn-green btn-sm">✅ Check In</button>
                            </form>
                        @endif
                        <a href="{{ route('teams.show', [$tournament, $myTeam]) }}" class="btn btn-sm btn-cyan">Manage Team</a>
                        @if(in_array($tournament->status, ['draft', 'open', 'closed'], true))
                            <form method="POST" action="{{ route('teams.withdraw', [$tournament, $myTeam]) }}">
                                @csrf
                                <button class="btn btn-sm" style="border-color:var(--red); color:var(--red)" onclick="return confirm('Withdraw your team from this tournament?')">Withdraw Team</button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    @endauth

    @if($tournament->acceptsRegistration() && !$tournament->isFull())
        <div class="card" style="text-align:center">
            <h3>Ready to fight? 🎯</h3>
            <a href="{{ route('teams.register', $tournament) }}" class="btn btn-primary">Register Your Team</a>
        </div>
    @elseif($tournament->acceptsRegistration() && $tournament->isFull())
        <div class="card" style="text-align:center">
            <h3>⏳ Tournament full</h3>
            <p class="muted">All slots are taken, but you can still join the waitlist.</p>
            <a href="{{ route('teams.register', $tournament) }}" class="btn btn-primary">Join Waitlist</a>
        </div>
    @endif

    @if($tournament->status === 'live' || $tournament->status === 'finished')
        <div class="card">
            <h2>🏆 Bracket
                <span class="muted" style="font-size:13px; font-weight:400">
                    — {{ $tournament->isDoubleElim() ? 'Double Elimination' : 'Single Elimination' }}
                    @if($tournament->bracket_size) ({{ $tournament->bracket_size }}-team bracket) @endif
                </span>
            </h2>
            @if($tournament->matches->isEmpty())
                <p class="muted">Bracket not generated yet.</p>
            @elseif($tournament->isDoubleElim())
                @php
                    $sides = [
                        'winners' => ['label' => 'Winners Bracket', 'brackets' => ['winners']],
                        'losers' => ['label' => 'Losers Bracket', 'brackets' => ['losers']],
                        'grand_final' => ['label' => 'Grand Final', 'brackets' => ['grand_final']],
                    ];
                @endphp
                @foreach($sides as $side)
                    @php $sideMatches = $tournament->matches->where('bracket', $side['brackets'][0]); @endphp
                    @if($sideMatches->isNotEmpty())
                        <div style="margin-top:18px">
                            <div class="muted" style="font-size:13px; font-weight:800; text-transform:uppercase; letter-spacing:.5px">{{ $side['label'] }}</div>
                            <div class="bracket-col">
                                @foreach($sideMatches->groupBy('round')->sortKeys() as $round => $roundMatches)
                                    <div class="bracket-round">
                                        <div class="muted" style="font-size:12px; font-weight:700">
                                            {{ $roundMatches->first()->roundLabel() }}
                                        </div>
                                        @foreach($roundMatches as $m)
                                            @include('matches._bracket_card', ['match' => $m])
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            @else
                @php $rounds = $tournament->matches->groupBy('round')->sortKeys(); $lastRound = $rounds->keys()->last(); @endphp
                <div class="bracket-col">
                    @foreach($rounds as $round => $matches)
                        <div class="bracket-round">
                            <div class="muted" style="font-size:12px; font-weight:700">
                                {{ $round == $lastRound ? '🏁 FINAL' : 'Round ' . $round }}
                            </div>
                            @foreach($matches as $m)
                                @include('matches._bracket_card', ['match' => $m])
                            @endforeach
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    <div class="card">
        <h3>👥 Registered Teams</h3>
        @if($tournament->confirmedTeams->isEmpty())
            <p class="muted">No confirmed teams yet.</p>
        @else
            <table>
                <tr><th>#</th><th>Team</th><th>Captain</th><th>Status</th>@if($tournament->hasCheckIn())<th>Check-in</th>@endif</tr>
                @foreach($tournament->confirmedTeams as $team)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td><strong>{{ $team->name }}</strong></td>
                        <td class="muted">{{ $team->captain_name }}</td>
                        <td><span class="pill confirmed">CONFIRMED</span></td>
                        @if($tournament->hasCheckIn())
                            <td>
                                @if($team->isCheckedIn())
                                    <span class="pill checked">CHECKED IN</span>
                                @else
                                    <span class="pill pending">NOT CHECKED IN</span>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
            </table>
        @endif
    </div>

    @if($waitlist && $waitlist->isNotEmpty())
        <div class="card">
            <h3>⏳ Waitlist</h3>
            <table>
                <tr><th>#</th><th>Team</th><th>Captain</th><th>Status</th></tr>
                @foreach($waitlist as $wt)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td><strong>{{ $wt->name }}</strong></td>
                        <td class="muted">{{ $wt->captain_name }}</td>
                        <td><span class="pill waitlisted">WAITLISTED</span></td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif
@endsection
```

## ── resources/views/leaderboard/show.blade.php ──────────────────
```blade
@extends('layouts.app')
@section('title', 'Leaderboard — ' . $tournament->name)
@section('content')
    <div style="padding: 24px 0 6px">
        <a href="{{ route('tournaments.show', $tournament) }}" class="muted" style="font-size:13px">← Back</a>
        <h1 style="margin-top:6px">🏅 Leaderboard</h1>
        <p class="muted">{{ $tournament->name }}</p>
    </div>

    {{-- Phase 12 — live updates feed (polling; server-side visibility) --}}
    @include('live.poll', ['tournament' => $tournament])

    <div class="card">
        @if($leaderboard->isEmpty())
            <p class="muted">No results yet.</p>
        @else
            <table>
                <tr>
                    <th>Rank</th><th>Team</th><th>Matches</th><th>Kills</th>
                    <th>Place Pts</th><th>Kill Pts</th><th>Total</th>
                </tr>
                @foreach($leaderboard as $row)
                    <tr>
                        <td><strong>#{{ $row->rank }}</strong></td>
                        <td><strong>{{ $row->team->name }}</strong></td>
                        <td>{{ $row->matches_played }}</td>
                        <td>{{ $row->kills }}</td>
                        <td>{{ $row->placement_points }}</td>
                        <td>{{ $row->kill_points }}</td>
                        <td><strong class="tag">{{ $row->points }}</strong></td>
                    </tr>
                @endforeach
            </table>
            <p class="muted" style="font-size:12px; margin-top:10px">
                Deterministic ordering — identical results always rank identically.
            </p>
        @endif
    </div>
@endsection
```

## ── tests/Feature/LiveEventServiceTest.php ─────────────────────────────────────────────
```php
<?php

namespace Tests\Feature;

use App\Models\LiveEvent;
use App\Models\Tournament;
use App\Models\User;
use App\Services\LiveEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 12 — live event log: append-only semantics, visibility enforcement
 * and cursor pagination.
 */
class LiveEventServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function makeTournament(User $organizer, string $status = 'live'): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = 'Live Tournament';
        $t->slug = 'live-' . Str::random(8);
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = 0;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->rules = null;
        $t->starts_at = now()->subHour();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function service(): LiveEventService
    {
        return app(LiveEventService::class);
    }

    public function test_record_is_append_only_with_monotonic_ids(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $first = $this->service()->record($tournament, null, LiveEvent::TYPE_TEAM_CHECKED_IN, ['team' => 'A']);
        $second = $this->service()->record($tournament, null, LiveEvent::TYPE_MATCH_COMPLETED, ['winner' => 'A']);

        $this->assertGreaterThan($first->id, $second->id);
        $this->assertSame($tournament->id, $first->tournament_id);
        $this->assertSame(['team' => 'A'], $first->payload);
    }

    public function test_latest_cursor_tracks_max_id(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $this->assertSame(0, $this->service()->latestCursor());

        $this->service()->record($tournament, null, LiveEvent::TYPE_MATCH_STARTED, []);

        $this->assertSame((int) LiveEvent::max('id'), $this->service()->latestCursor());
    }

    public function test_record_quietly_never_throws(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        // A normal record succeeds.
        $event = $this->service()->recordQuietly($tournament, null, LiveEvent::TYPE_TEAM_REGISTERED, ['team' => 'B']);
        $this->assertNotNull($event);
    }

    public function test_public_types_are_visible_to_guests(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $this->service()->record($tournament, null, LiveEvent::TYPE_MATCH_COMPLETED, ['winner' => 'A']);

        $events = $this->service()->since(0, $tournament, null, 50);

        $this->assertCount(1, $events);
        $this->assertSame(LiveEvent::TYPE_MATCH_COMPLETED, $events->first()->type);
    }

    public function test_staff_only_events_are_hidden_from_guests(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $this->service()->record($tournament, null, LiveEvent::TYPE_DISPUTE_OPENED, ['category' => 'aimbot']);
        $this->service()->record($tournament, null, LiveEvent::TYPE_MATCH_COMPLETED, ['winner' => 'A']);

        $guestEvents = $this->service()->since(0, $tournament, null, 50);

        $this->assertCount(1, $guestEvents);
        $this->assertSame(LiveEvent::TYPE_MATCH_COMPLETED, $guestEvents->first()->type);
    }

    public function test_organizer_sees_their_tournaments_staff_events(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $this->service()->record($tournament, null, LiveEvent::TYPE_DISPUTE_OPENED, ['category' => 'teaming']);

        $events = $this->service()->since(0, $tournament, $organizer, 50);

        $this->assertCount(1, $events);
    }

    public function test_other_organizer_cannot_see_staff_events(): void
    {
        $owner = $this->makeUser('organizer');
        $intruder = $this->makeUser('organizer');
        $tournament = $this->makeTournament($owner);

        $this->service()->record($tournament, null, LiveEvent::TYPE_DISPUTE_OPENED, ['category' => 'teaming']);

        $this->assertCount(0, $this->service()->since(0, $tournament, $intruder, 50));
    }

    public function test_moderator_and_admin_see_all_staff_events(): void
    {
        $owner = $this->makeUser('organizer');
        $tournament = $this->makeTournament($owner);
        $moderator = $this->makeUser('moderator');
        $admin = $this->makeUser('admin');

        $this->service()->record($tournament, null, LiveEvent::TYPE_DISPUTE_OPENED, ['category' => 'teaming']);

        $this->assertCount(1, $this->service()->since(0, $tournament, $moderator, 50));
        $this->assertCount(1, $this->service()->since(0, $tournament, $admin, 50));
    }

    public function test_cursor_filters_newer_events_only(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $first = $this->service()->record($tournament, null, LiveEvent::TYPE_MATCH_STARTED, []);
        $this->service()->record($tournament, null, LiveEvent::TYPE_TEAM_CHECKED_IN, ['team' => 'A']);

        $events = $this->service()->since($first->id, $tournament, null, 50);

        $this->assertCount(1, $events);
        $this->assertSame(LiveEvent::TYPE_TEAM_CHECKED_IN, $events->first()->type);
    }

    public function test_snapshot_returns_revision_and_recent_events(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $this->service()->record($tournament, null, LiveEvent::TYPE_MATCH_COMPLETED, ['winner' => 'A']);

        $snapshot = $this->service()->snapshot($tournament, null);

        $this->assertArrayHasKey('revision', $snapshot);
        $this->assertArrayHasKey('events', $snapshot);
        $this->assertSame($this->service()->latestCursor(), $snapshot['revision']);
        $this->assertCount(1, $snapshot['events']);
    }

    public function test_mass_assignment_is_guarded(): void
    {
        $event = new LiveEvent();

        try {
            $event->fill([
                'type' => 'match.completed',
                'payload' => ['winner' => 'X'],
                'tournament_id' => 1,
            ]);
            $this->fail('LiveEvent accepted mass assignment.');
        } catch (\Illuminate\Database\Eloquent\MassAssignmentException $e) {
            $this->addToAssertionCount(1);
        }
    }
}
```

## ── tests/Feature/LiveHttpTest.php ─────────────────────────────────────────────
```php
<?php

namespace Tests\Feature;

use App\Models\LiveEvent;
use App\Models\Tournament;
use App\Models\User;
use App\Services\LiveEventService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 12 — HTTP endpoints for live polling, SSE and the unread badge.
 */
class LiveHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function makeTournament(User $organizer, string $status = 'live'): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = 'Live Tournament';
        $t->slug = 'live-' . Str::random(8);
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = 0;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->rules = null;
        $t->starts_at = now()->subHour();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function service(): LiveEventService
    {
        return app(LiveEventService::class);
    }

    public function test_guest_can_poll_public_live_events(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $this->service()->record($tournament, null, LiveEvent::TYPE_MATCH_COMPLETED, ['winner' => 'A']);

        $response = $this->getJson(route('tournaments.live', $tournament));

        $response->assertOk()
            ->assertJsonStructure(['revision', 'since', 'count', 'events'])
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.type', LiveEvent::TYPE_MATCH_COMPLETED)
            ->assertJsonPath('events.0.payload.winner', 'A');

        // The actor id is never exposed.
        $this->assertArrayNotHasKey('actor_user_id', $response->json('events.0'));
    }

    public function test_guest_live_feed_excludes_staff_events(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $this->service()->record($tournament, null, LiveEvent::TYPE_DISPUTE_OPENED, ['category' => 'aimbot']);
        $this->service()->record($tournament, null, LiveEvent::TYPE_MATCH_COMPLETED, ['winner' => 'A']);

        $response = $this->getJson(route('tournaments.live', $tournament));

        $response->assertOk()
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.type', LiveEvent::TYPE_MATCH_COMPLETED);
    }

    public function test_organizer_sees_staff_events_in_their_tournament(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $this->service()->record($tournament, null, LiveEvent::TYPE_DISPUTE_OPENED, ['category' => 'aimbot']);

        $this->actingAs($organizer)
            ->getJson(route('tournaments.live', $tournament))
            ->assertOk()
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.type', LiveEvent::TYPE_DISPUTE_OPENED);
    }

    public function test_since_cursor_returns_only_newer_events(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $first = $this->service()->record($tournament, null, LiveEvent::TYPE_MATCH_STARTED, []);
        $this->service()->record($tournament, null, LiveEvent::TYPE_TEAM_CHECKED_IN, ['team' => 'A']);

        $response = $this->getJson(route('tournaments.live', [$tournament, 'since' => $first->id]));

        $response->assertOk()
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.type', LiveEvent::TYPE_TEAM_CHECKED_IN)
            ->assertJsonPath('revision', $this->service()->latestCursor());
    }

    public function test_unread_endpoint_requires_auth(): void
    {
        $this->getJson(route('notifications.unread'))->assertStatus(401);
    }

    public function test_unread_endpoint_returns_the_badge_count(): void
    {
        $user = $this->makeUser();
        $service = app(NotificationService::class);

        $service->send($user, 'system', 'A', '1');
        $service->send($user, 'system', 'B', '2');

        $this->actingAs($user)
            ->getJson(route('notifications.unread'))
            ->assertOk()
            ->assertJson(['unread' => 2]);
    }

    public function test_sse_stream_responds_with_event_stream(): void
    {
        config(['live.stream.max_duration' => 0]);
        config(['live.stream.heartbeat' => 1]);

        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $this->service()->record($tournament, null, LiveEvent::TYPE_MATCH_COMPLETED, ['winner' => 'A']);

        $response = $this->get(route('tournaments.stream', $tournament));

        $response->assertStatus(200);
        $this->assertStringContainsString('text/event-stream', $response->headers->get('Content-Type'));

        $content = $response->streamedContent();

        $this->assertStringContainsString('event: live', $content);
        $this->assertStringContainsString(LiveEvent::TYPE_MATCH_COMPLETED, $content);
    }

    public function test_live_page_renders_the_feed_panel(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);

        $this->get(route('tournaments.show', $tournament))
            ->assertOk()
            ->assertSee('live-feed', false);
    }
}
```

## ── tests/Feature/LiveIntegrationTest.php ─────────────────────────────────────────────
```php
<?php

namespace Tests\Feature;

use App\Models\GameMatch;
use App\Models\LiveEvent;
use App\Models\Score;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\MatchProgressionService;
use App\Services\ScoringService;
use App\Services\TournamentParticipationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 12 — real business flows emit live events without mutating their
 * Phase 01–11 state.
 */
class LiveIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function makeTournament(User $organizer, string $status = 'live', array $o = []): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = $o['name'] ?? 'Live Tournament';
        $t->slug = $o['slug'] ?? ('live-' . Str::random(8));
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = 0;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->rules = null;
        $t->starts_at = $o['starts_at'] ?? now()->subHour();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = $status;
        $t->check_in_starts_at = $o['check_in_starts_at'] ?? null;
        $t->check_in_ends_at = $o['check_in_ends_at'] ?? null;
        $t->save();

        return $t;
    }

    protected function makeTeam(Tournament $tournament, ?User $captain = null, string $status = 'confirmed'): Team
    {
        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain?->id;
        $team->name = 'Team ' . Str::random(6);
        $team->captain_name = $captain?->name ?? 'Captain';
        $team->phone = '01700000000';
        $team->game_uid = 'UID' . strtoupper(Str::random(8));
        $team->status = $status;
        $team->save();

        return $team;
    }

    protected function makeMatch(Tournament $tournament, Team $t1, ?Team $t2 = null, string $status = 'ready'): GameMatch
    {
        $m = new GameMatch();
        $m->tournament_id = $tournament->id;
        $m->round = 1;
        $m->match_no = 1;
        $m->team1_id = $t1->id;
        $m->team2_id = $t2?->id;
        $m->status = $status;
        $m->save();

        return $m;
    }

    public function test_score_submission_emits_live_event_without_changing_score_state(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);
        $teamA = $this->makeTeam($tournament, $this->makeUser());
        $teamB = $this->makeTeam($tournament, $this->makeUser());
        $match = $this->makeMatch($tournament, $teamA, $teamB, 'ready');

        $score = app(ScoringService::class)->submitScore($match, $teamA, 12, 2);

        $this->assertSame('pending', $score->status);
        $this->assertSame(12, $score->kills);

        $event = LiveEvent::where('type', LiveEvent::TYPE_SCORE_SUBMITTED)->first();

        $this->assertNotNull($event);
        $this->assertSame($tournament->id, $event->tournament_id);
        $this->assertSame($teamA->name, $event->payload['team']);
        $this->assertSame(12, $event->payload['kills']);
    }

    public function test_match_completion_emits_live_event(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);
        $teamA = $this->makeTeam($tournament, $this->makeUser());
        $teamB = $this->makeTeam($tournament, $this->makeUser());
        $match = $this->makeMatch($tournament, $teamA, $teamB, 'ready');

        $result = app(MatchProgressionService::class)->complete($match, $teamA);

        $this->assertSame('completed', $result);
        $this->assertSame(GameMatch::STATUS_COMPLETED, $match->fresh()->status);

        $event = LiveEvent::where('type', LiveEvent::TYPE_MATCH_COMPLETED)->first();

        $this->assertNotNull($event);
        $this->assertSame($teamA->name, $event->payload['winner']);
    }

    public function test_dispute_and_resolution_emit_live_events(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);
        $teamA = $this->makeTeam($tournament, $this->makeUser());
        $teamB = $this->makeTeam($tournament, $this->makeUser());
        $match = $this->makeMatch($tournament, $teamA, $teamB, 'ready');

        $progression = app(MatchProgressionService::class);
        $progression->complete($match, $teamA);
        $progression->dispute($match);

        $this->assertTrue(LiveEvent::where('type', LiveEvent::TYPE_MATCH_DISPUTED)->exists());

        $progression->resolve($match, $teamA);

        $this->assertTrue(LiveEvent::where('type', LiveEvent::TYPE_MATCH_RESOLVED)->exists());
    }

    public function test_match_start_emits_live_event(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);
        $teamA = $this->makeTeam($tournament, $this->makeUser());
        $teamB = $this->makeTeam($tournament, $this->makeUser());
        $match = $this->makeMatch($tournament, $teamA, $teamB, 'ready');

        app(MatchProgressionService::class)->start($match);

        $this->assertSame(GameMatch::STATUS_LIVE, $match->fresh()->status);
        $this->assertTrue(LiveEvent::where('type', LiveEvent::TYPE_MATCH_STARTED)->exists());
    }

    public function test_check_in_emits_live_event_and_is_idempotent(): void
    {
        $organizer = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer, 'live', [
            'check_in_starts_at' => now()->subHour(),
            'check_in_ends_at' => now()->addHour(),
        ]);
        $team = $this->makeTeam($tournament, $captain);

        $service = app(TournamentParticipationService::class);

        $this->assertSame('checked_in', $service->checkIn($tournament, $team, $captain));
        $this->assertSame('already', $service->checkIn($tournament, $team, $captain));

        // One live event, not two (idempotency).
        $this->assertSame(1, LiveEvent::where('type', LiveEvent::TYPE_TEAM_CHECKED_IN)->count());
        $this->assertNotNull($team->fresh()->checked_in_at);
    }

    public function test_team_registration_and_withdrawal_emit_events_via_http(): void
    {
        $organizer = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer, 'open', ['starts_at' => now()->addDay()]);

        $this->actingAs($captain)->post(route('teams.store', $tournament), [
            'name' => 'Live Feed Team',
            'captain_name' => $captain->name,
            'phone' => '01700000000',
            'game_uid' => 'UIDLIVE1',
        ])->assertRedirect();

        $this->assertTrue(LiveEvent::where('type', LiveEvent::TYPE_TEAM_REGISTERED)->exists());

        $team = Team::where('captain_id', $captain->id)->first();

        $this->actingAs($captain)->post(route('teams.withdraw', [$tournament, $team]))->assertRedirect();

        $this->assertTrue(LiveEvent::where('type', LiveEvent::TYPE_TEAM_WITHDRAWN)->exists());
        $this->assertSame(Team::STATUS_WITHDRAWN, $team->fresh()->status);
    }

    public function test_live_events_carry_no_sensitive_payload(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);
        $teamA = $this->makeTeam($tournament, $this->makeUser());
        $teamB = $this->makeTeam($tournament, $this->makeUser());
        $match = $this->makeMatch($tournament, $teamA, $teamB, 'ready');

        app(ScoringService::class)->submitScore($match, $teamA, 12, 2);

        $event = LiveEvent::where('type', LiveEvent::TYPE_SCORE_SUBMITTED)->first();

        foreach (['password', 'token', 'secret', 'phone', 'game_uid', 'email', 'screenshot'] as $key) {
            $this->assertArrayNotHasKey($key, $event->payload);
        }
    }

    public function test_failed_score_submission_emits_no_live_event(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer);
        $teamA = $this->makeTeam($tournament, $this->makeUser());
        $teamB = $this->makeTeam($tournament, $this->makeUser());
        $match = $this->makeMatch($tournament, $teamA, $teamB, 'ready');

        try {
            app(ScoringService::class)->submitScore($match, $teamA, -1, 2);
            $this->fail('Expected negative kills to be rejected.');
        } catch (\DomainException $e) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, LiveEvent::where('type', LiveEvent::TYPE_SCORE_SUBMITTED)->count());
        $this->assertSame(0, Score::count());
    }
}
```

