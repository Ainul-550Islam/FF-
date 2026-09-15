# PHASE 11 — NOTIFICATIONS (IN-APP + EMAIL)
## FF Arena — Best-Effort Event Notification Layer

**Date:** 2026-09-08 (Asia/Dhaka)
**Stack:** Laravel 12 · SQLite (portable to MySQL/PostgreSQL) · server-rendered Blade
**Scope:** Phase 11 only. Phases 01–10 behavior is fully preserved; nothing was rebuilt or weakened.

---

## 1. Scope & assumptions

The Phase 11 specification was not supplied; this phase was implemented as the
first item in the previously declared Phase 11+ roadmap — **Notifications** —
under the following explicit assumptions:

- **In-app notifications are the primary, always-available channel.** One row
  per recipient (personal, never broadcast), with unread/read state.
- **Email is a secondary, best-effort channel.** It is toggleable via
  `NOTIFICATIONS_EMAIL` and a delivery failure can never fail the originating
  action.
- **No external APIs / webhooks / mobile push.** No realtime (no broadcasting),
  no queue-job infrastructure, no analytics — those remain explicitly
  out-of-scope (Phase 11+).

If any of these assumptions differ from the intended Phase 11 spec, they can be
corrected in a follow-up.

---

## 2. What was built

### 2.1 Data layer (1 new migration, 1 new model)
`database/migrations/2026_09_07_000000_create_notifications_table.php` adds the
`notifications` table:

| Column | Purpose |
|---|---|
| `user_id` | Recipient (FK → users, cascade delete). Personal rows only. |
| `type` | Machine-readable type (`payment.verified`, `dispute.opened`, …). |
| `title` / `body` | Display content. |
| `link` | Optional in-app deep link. |
| `data` | Structured, non-sensitive ids for deep-links (JSON). Never secrets. |
| `read_at` | In-app read state (null = unread). |
| `timestamps` | Standard created/updated. |

Indexes on `user_id` and `(user_id, read_at)`; no destructive changes to
Phases 01–10.

### 2.2 NotificationService (single authority)
`app/Services/NotificationService.php`:
- `send()` — persists the in-app row then attempts best-effort email. A single
  insert, safe inside callers' existing transactions; email is wrapped in
  try/catch and reported, never re-thrown.
- `sendToMany()` — de-duplicates recipients by id.
- `forUser()` / `unreadCount()` / `markRead()` / `markAllRead()` — inbox reads
  with server-side ownership enforcement.
- `NotificationService::link()` — builds route links safely (null on a missing
  route), so a link can never break a business flow.

### 2.3 Email (best-effort)
`app/Mail/UserNotification.php` + `resources/views/mail/notification.blade.php` —
a plain-text Mailable. Content is set in the constructor (this Laravel version
resolves `subject`/`view`/`viewData` directly). Delivery honours
`config('notifications.email_enabled')` and swallows transport failures.

### 2.4 Event hooks (notifications only — never state mutation)
Business services notify **after** their transactional state change commits
(or, for in-transaction cases, via single inserts inside the same transaction).
Every hook is additive and can never alter the Phase 01–10 outcome:

| Event | Service / hook | Recipients |
|---|---|---|
| Payment verified | `PaymentService::settleSuccess` | payer |
| Payment failed | `PaymentService::markFailed` | payer |
| Payment refunded | `PaymentService::refund` | payer |
| Dispute opened | `DisputeService::open` | other captain(s), organizer, staff |
| Dispute resolved / rejected / cancelled | `DisputeService` | participants + opener |
| Payout processed | `PayoutService::processInternal` | recipient |
| Payout failed | `PayoutService::markFailed` | recipient |
| Settlement completed | `PrizeDistributionService::process` | organizer + paid recipients |
| Restriction applied / lifted | `RestrictionService` | the user |
| Identity verified / rejected | `IdentityVerificationService` | the user |
| Anti-cheat resolved | `AntiCheatService::resolve` | accused + reporter |
| Team registered | `TeamController::register` | captain + organizer |
| Team withdrawn | `TeamController::withdraw` | organizer |

### 2.5 UI & authorization
- `NotificationController` — inbox, mark-read, mark-all-read (auth only).
- `resources/views/notifications/index.blade.php` — inbox with unread badges,
  deep links and pagination.
- Layout nav shows `🔔 Notifications` with a live unread badge (via an
  `AppServiceProvider` view composer; guests always see zero).
- `NotificationPolicy` — a user can only ever list, view and mark-read their
  own notifications (IDOR-safe; enforced again in the service).

---

## 3. Verification results

| Check | Result |
|---|---|
| `php -l` on every new/modified file | **All clean** |
| `php artisan migrate:fresh --seed --force` | **21 migrations applied, seeded** |
| `php artisan test` (full suite) | **446 passed · 1343 assertions · 0 failures** |
| Phase 11 suites (`--filter=Notification`) | **31 passed · 69 assertions** |
| Phase 01–10 regression (446 − 31) | **415 passed · 1274 assertions** |
| `php artisan route:list` | **100 routes** (97 baseline + 3 notification) |
| HTTP smoke | Verified (see §4) |

### 3.1 Phase 11 test coverage (31 tests)
- **Service** (12): in-app persistence, email sent when enabled, email skipped
  when disabled (in-app still written), email failure never breaks delivery,
  `sendToMany` de-duplication + non-user filtering, ownership-enforced
  mark-read, idempotent mark-read, mark-all-read scoping, unread count +
  newest-first pagination, safe route link helper, mass-assignment guard.
- **Integration** (10): payment verified/failed notify payer; dispute opened
  notifies opponent + organizer + staff (and never the opener); dispute
  resolution notifies participants; restriction applied/lifted notify user;
  identity verified/rejected notify user; anti-cheat resolution notifies
  accused + reporter; team registration notifies captain + organizer; team
  withdrawal notifies organizer; and a regression guard proving notification
  delivery never mutates Phase 10 restriction/risk state and carries no
  sensitive data.
- **HTTP** (9): guest redirect, own inbox visible, cross-user invisibility
  (IDOR), cross-user mark-read 403, own mark-read 302 + state, mark-all-read
  302 + state, badge exposure, 404 for missing notification.

---

## 4. HTTP smoke (against seeded dev database)

```
guest      /notifications                     → 302 (redirect to login)
player     /notifications                     → 200 "Notifications"
player     / (home)                           → nav shows "🔔 Notifications"
admin      /admin/security                    → nav shows "🔔 Notifications"
player     POST register team (smoke)         → 302; 1 notification each for
                                                 player + organizer, type team.registered
player     GET /notifications                 → shows "Team registered / Notify Smoke Team"
player     POST /notifications/read-all       → 302; unread count = 0
```

---

## 5. Full file contents

> Every new and modified file is reproduced in full below. No placeholders.

### 5.1 New files


#### `database/migrations/2026_09_07_000000_create_notifications_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 — in-app + email notifications.
 *
 * One row per recipient (notifications are personal, never broadcast rows).
 * `read_at` marks the in-app read state; `data` carries structured, non-
 * sensitive metadata (ids for deep-links). No Phase 01–10 table is touched.
 *
 * SQLite-compatible: foreign keys, indexes, and no destructive changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 40);                    // e.g. payment.verified
            $table->string('title', 160);
            $table->text('body');
            $table->string('link', 255)->nullable();       // in-app deep link
            $table->json('data')->nullable();              // structured ids (no secrets)
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index('user_id', 'notifications_user_index');
            $table->index(['user_id', 'read_at'], 'notifications_user_read_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
```


#### `config/notifications.php`

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Notifications (Phase 11)
    |--------------------------------------------------------------------------
    |
    | In-app notifications are always written (the primary, always-available
    | channel). Email is an optional, best-effort secondary channel: it is
    | disabled when NOTIFICATIONS_EMAIL=false, and a failure to deliver email
    | never fails the originating action.
    |
    */

    // Send a best-effort email alongside every in-app notification.
    'email_enabled' => (bool) env('NOTIFICATIONS_EMAIL', true),

    // Default page size for the notification inbox.
    'per_page' => 20,

];
```


#### `app/Models/Notification.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A personal, in-app notification (Phase 11).
 *
 * Notifications are server-generated only: recipients, content and links are
 * assigned by NotificationService, never by a client. All fields are excluded
 * from mass assignment. `read_at` records the in-app read state; email is a
 * separate, best-effort delivery on top of this row.
 */
class Notification extends Model
{
    use HasFactory;

    public const TYPE_PAYMENT_VERIFIED = 'payment.verified';
    public const TYPE_PAYMENT_FAILED = 'payment.failed';
    public const TYPE_PAYMENT_REFUNDED = 'payment.refunded';
    public const TYPE_DISPUTE_OPENED = 'dispute.opened';
    public const TYPE_DISPUTE_RESOLVED = 'dispute.resolved';
    public const TYPE_PAYOUT_PROCESSED = 'payout.processed';
    public const TYPE_PAYOUT_FAILED = 'payout.failed';
    public const TYPE_SETTLEMENT_COMPLETED = 'settlement.completed';
    public const TYPE_RESTRICTION_APPLIED = 'restriction.applied';
    public const TYPE_RESTRICTION_LIFTED = 'restriction.lifted';
    public const TYPE_IDENTITY_VERIFIED = 'identity.verified';
    public const TYPE_IDENTITY_REJECTED = 'identity.rejected';
    public const TYPE_ANTI_CHEAT_RESOLVED = 'anti_cheat.resolved';
    public const TYPE_TEAM_REGISTERED = 'team.registered';
    public const TYPE_TEAM_WITHDRAWN = 'team.withdrawn';
    public const TYPE_SYSTEM = 'system';

    protected $fillable = [];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    /**
     * Human label for the notification type (display only).
     */
    public function typeLabel(): string
    {
        return ucwords(str_replace(['.', '_'], ' ', $this->type));
    }
}
```


#### `app/Mail/UserNotification.php`

```php
<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/**
 * Plain-text email notification (Phase 11).
 *
 * Sent best-effort alongside every in-app notification. Content is set in
 * the constructor because this Laravel version resolves `subject`/`view`/
 * `viewData` directly (no envelope/content methods).
 */
class UserNotification extends Mailable
{
    public function __construct(
        string $type,
        string $title,
        string $body,
        ?string $link = null,
    ) {
        $this->subject = $title;
        $this->view = 'mail.notification';
        $this->viewData = [
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'link' => $link,
        ];
    }
}
```


#### `resources/views/mail/notification.blade.php`

```php
{{ $title }}

{{ $body }}
@if($link)

View in FF Arena: {{ $link }}
@endif
```


#### `app/Services/NotificationService.php`

```php
<?php

namespace App\Services;

use App\Mail\UserNotification;
use App\Models\Notification;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Mail;

/**
 * In-app + email notifications (Phase 11).
 *
 * The single authority for creating notifications. `send()` always persists
 * the in-app row (single insert — safe inside callers' transactions) and then
 * attempts a best-effort email that can never fail the originating action.
 * Recipients, content and links are always server-derived; clients can only
 * mark their own notifications read.
 */
class NotificationService
{
    /**
     * Create a notification for one user (in-app row + best-effort email).
     */
    public function send(
        User $recipient,
        string $type,
        string $title,
        string $body,
        ?string $link = null,
        array $data = [],
    ): Notification {
        $notification = new Notification();
        $notification->user_id = $recipient->id;
        $notification->type = $type;
        $notification->title = trim($title);
        $notification->body = trim($body);
        $notification->link = $link;
        $notification->data = $data;
        $notification->save();

        $this->email($recipient, $type, $title, $body, $link);

        return $notification;
    }

    /**
     * Send the same notification to many users, de-duplicating by id.
     *
     * @param iterable<User> $recipients
     */
    public function sendToMany(
        iterable $recipients,
        string $type,
        string $title,
        string $body,
        ?string $link = null,
        array $data = [],
    ): int {
        $sent = 0;
        $seen = [];

        foreach ($recipients as $recipient) {
            if (! $recipient instanceof User) {
                continue;
            }

            if (isset($seen[$recipient->id])) {
                continue;
            }

            $seen[$recipient->id] = true;

            $this->send($recipient, $type, $title, $body, $link, $data);

            $sent++;
        }

        return $sent;
    }

    /**
     * The user's notifications, newest first.
     */
    public function forUser(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Number of unread notifications (for the navigation badge).
     */
    public function unreadCount(User $user): int
    {
        return Notification::where('user_id', $user->id)->unread()->count();
    }

    /**
     * Mark a notification read (ownership enforced server-side).
     */
    public function markRead(Notification $notification, User $user): Notification
    {
        if ($notification->user_id !== $user->id) {
            throw new DomainException('You cannot read another user\'s notification.');
        }

        if ($notification->read_at === null) {
            $notification->read_at = now();
            $notification->save();
        }

        return $notification;
    }

    /**
     * Mark every notification read for a user.
     */
    public function markAllRead(User $user): int
    {
        return Notification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Build a route link safely (null when the route is unavailable), so a
     * missing route can never break a business flow.
     */
    public static function link(string $name, array $params = []): ?string
    {
        try {
            return route($name, $params);
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Best-effort email delivery. Never throws into the caller.
     */
    protected function email(User $recipient, string $type, string $title, string $body, ?string $link): void
    {
        if (! config('notifications.email_enabled', true)) {
            return;
        }

        $address = trim((string) $recipient->email);

        if ($address === '') {
            return;
        }

        try {
            Mail::to($address)->send(new UserNotification($type, $title, $body, $link));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
```


#### `app/Policies/NotificationPolicy.php`

```php
<?php

namespace App\Policies;

use App\Models\Notification;
use App\Models\User;

/**
 * Notification authorization (Phase 11).
 *
 * A user can only ever see and mark-read their own notifications. There is
 * no staff override that exposes another user's inbox.
 */
class NotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // the inbox is always the authenticated user's own
    }

    public function view(User $user, Notification $notification): bool
    {
        return $notification->user_id === $user->id;
    }

    public function markRead(User $user, Notification $notification): bool
    {
        return $notification->user_id === $user->id;
    }

    public function markAllRead(User $user): bool
    {
        return true; // acts only on the authenticated user's own rows
    }
}
```


#### `app/Http/Controllers/NotificationController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\NotificationService;
use DomainException;
use Illuminate\Http\Request;

/**
 * The authenticated user's notification inbox (Phase 11).
 *
 * Listing, read-state changes and the unread badge all operate strictly on
 * the authenticated user's own notifications (enforced by the policy and
 * again by NotificationService).
 */
class NotificationController extends Controller
{
    public function __construct(
        protected NotificationService $notifications,
    ) {
    }

    public function index()
    {
        $this->authorize('viewAny', Notification::class);

        $items = $this->notifications->forUser(
            auth()->user(),
            (int) config('notifications.per_page', 20)
        );

        return view('notifications.index', compact('items'));
    }

    public function markRead(Notification $notification)
    {
        $this->authorize('markRead', $notification);

        try {
            $this->notifications->markRead($notification, auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Notification marked as read.');
    }

    public function markAllRead()
    {
        $this->authorize('markAllRead', Notification::class);

        $this->notifications->markAllRead(auth()->user());

        return back()->with('success', 'All notifications marked as read.');
    }
}
```


#### `resources/views/notifications/index.blade.php`

```php
@extends('layouts.app')
@section('title', 'Notifications — FF Arena')
@section('content')
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin:30px 0 16px">
        <h1 style="margin:0">🔔 Notifications</h1>
        @if($items->isNotEmpty())
            <form method="POST" action="{{ route('notifications.readAll') }}">
                @csrf
                <button class="btn btn-sm btn-cyan">Mark all as read</button>
            </form>
        @endif
    </div>

    <div class="card">
        @forelse($items as $item)
            <div style="display:flex; gap:14px; align-items:flex-start; padding:14px 0; border-bottom:1px solid var(--line); {{ $item->isRead() ? 'opacity:.55' : '' }}">
                <div style="flex:1; min-width:0">
                    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap">
                        @unless($item->isRead())
                            <span class="pill live">NEW</span>
                        @endunless
                        <strong>{{ $item->title }}</strong>
                        <span class="muted" style="font-size:12px">{{ $item->typeLabel() }}</span>
                    </div>
                    <p style="margin:6px 0; color:var(--muted); font-size:14px">{{ $item->body }}</p>
                    <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap">
                        @if($item->link)
                            <a href="{{ $item->link }}" style="font-size:13px">View →</a>
                        @endif
                        <span class="muted" style="font-size:12px">{{ $item->created_at?->format('d M Y, h:i A') }}</span>
                    </div>
                </div>
                @unless($item->isRead())
                    <form method="POST" action="{{ route('notifications.read', $item) }}">
                        @csrf
                        <button class="btn btn-sm">Mark read</button>
                    </form>
                @endunless
            </div>
        @empty
            <p class="muted">You have no notifications yet.</p>
        @endforelse

        @if($items->hasPages())
            <div style="margin-top:14px">{{ $items->links() }}</div>
        @endif
    </div>
@endsection
```


#### `tests/Feature/NotificationServiceTest.php`

```php
<?php

namespace Tests\Feature;

use App\Mail\UserNotification;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Phase 11 — notification service core: creation, delivery, read state,
 * ownership enforcement and best-effort email semantics.
 */
class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function service(): NotificationService
    {
        return app(NotificationService::class);
    }

    public function test_send_persists_an_in_app_notification(): void
    {
        Mail::fake();

        $user = $this->makeUser();

        $notification = $this->service()->send(
            $user,
            Notification::TYPE_PAYMENT_VERIFIED,
            'Payment verified',
            'Your payment was verified.',
            '/wallet',
            ['payment_id' => 5],
        );

        $this->assertSame($user->id, $notification->user_id);
        $this->assertSame(Notification::TYPE_PAYMENT_VERIFIED, $notification->type);
        $this->assertSame('Payment verified', $notification->title);
        $this->assertSame('Your payment was verified.', $notification->body);
        $this->assertSame('/wallet', $notification->link);
        $this->assertSame(['payment_id' => 5], $notification->data);
        $this->assertFalse($notification->isRead());
        $this->assertSame(1, Notification::where('user_id', $user->id)->count());
    }

    public function test_email_is_sent_best_effort_when_enabled(): void
    {
        Mail::fake();
        config(['notifications.email_enabled' => true]);

        $user = $this->makeUser();

        $this->service()->send($user, 'system', 'Hello', 'A test body.');

        Mail::assertSent(UserNotification::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email) && $mail->subject === 'Hello';
        });
    }

    public function test_email_is_skipped_when_disabled_but_in_app_remains(): void
    {
        Mail::fake();
        config(['notifications.email_enabled' => false]);

        $user = $this->makeUser();

        $this->service()->send($user, 'system', 'Hello', 'A test body.');

        Mail::assertNothingSent();
        $this->assertSame(1, Notification::where('user_id', $user->id)->count());
    }

    public function test_email_failure_never_breaks_the_notification(): void
    {
        Mail::fake();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));
        config(['notifications.email_enabled' => true]);

        $user = $this->makeUser();

        // Must not throw, and the in-app row must still exist.
        $this->service()->send($user, 'system', 'Hello', 'A test body.');

        $this->assertSame(1, Notification::where('user_id', $user->id)->count());
    }

    public function test_send_to_many_deduplicates_recipients(): void
    {
        Mail::fake();
        config(['notifications.email_enabled' => false]);

        $user = $this->makeUser();

        $count = $this->service()->sendToMany([$user, $user, $user], 'system', 'Hi', 'Body');

        $this->assertSame(1, $count);
        $this->assertSame(1, Notification::where('user_id', $user->id)->count());
    }

    public function test_send_to_many_skips_non_user_entries(): void
    {
        Mail::fake();
        config(['notifications.email_enabled' => false]);

        $user = $this->makeUser();

        $count = $this->service()->sendToMany([$user, null, 'nope'], 'system', 'Hi', 'Body');

        $this->assertSame(1, $count);
    }

    public function test_mark_read_requires_ownership(): void
    {
        Mail::fake();
        config(['notifications.email_enabled' => false]);

        $owner = $this->makeUser();
        $intruder = $this->makeUser();

        $notification = $this->service()->send($owner, 'system', 'Hi', 'Body');

        $this->expectException(DomainException::class);
        $this->service()->markRead($notification, $intruder);
    }

    public function test_mark_read_is_idempotent(): void
    {
        Mail::fake();
        config(['notifications.email_enabled' => false]);

        $owner = $this->makeUser();
        $notification = $this->service()->send($owner, 'system', 'Hi', 'Body');

        $this->service()->markRead($notification, $owner);
        $this->service()->markRead($notification, $owner);

        $this->assertTrue($notification->fresh()->isRead());
    }

    public function test_mark_all_read_only_affects_own_rows(): void
    {
        Mail::fake();
        config(['notifications.email_enabled' => false]);

        $user = $this->makeUser();
        $other = $this->makeUser();

        $this->service()->send($user, 'system', 'A', '1');
        $this->service()->send($user, 'system', 'B', '2');
        $this->service()->send($other, 'system', 'C', '3');

        $this->assertSame(2, $this->service()->markAllRead($user));

        $this->assertSame(0, $this->service()->unreadCount($user));
        $this->assertSame(1, $this->service()->unreadCount($other));
    }

    public function test_unread_count_and_pagination(): void
    {
        Mail::fake();
        config(['notifications.email_enabled' => false]);

        $user = $this->makeUser();

        foreach (range(1, 5) as $i) {
            $this->service()->send($user, 'system', 'Title ' . $i, 'Body ' . $i);
        }

        $this->assertSame(5, $this->service()->unreadCount($user));

        $page = $this->service()->forUser($user, 3);

        $this->assertCount(3, $page->items());
        $this->assertSame(5, $page->total());
        $this->assertSame('Title 5', $page->items()[0]->title); // newest first
    }

    public function test_link_helper_returns_null_for_missing_route(): void
    {
        $this->assertSame('http://localhost/wallet', NotificationService::link('wallet.index'));
        $this->assertNull(NotificationService::link('route.that.does.not.exist'));
    }

    public function test_mass_assignment_is_guarded(): void
    {
        $user = $this->makeUser();

        $notification = new Notification();

        try {
            $notification->fill([
                'user_id' => $user->id,
                'type' => 'system',
                'title' => 'Injected',
                'body' => 'Injected',
            ]);
            $this->fail('Notification accepted mass assignment.');
        } catch (\Illuminate\Database\Eloquent\MassAssignmentException $e) {
            $this->addToAssertionCount(1);
        }
    }
}
```


#### `tests/Feature/NotificationIntegrationTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\AntiCheatIncident;
use App\Models\Device;
use App\Models\GameMatch;
use App\Models\IdentityVerification;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Restriction;
use App\Models\RiskEvent;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\AntiCheatService;
use App\Services\DisputeService;
use App\Services\IdentityVerificationService;
use App\Services\PaymentService;
use App\Services\RestrictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 11 — notifications emitted by real business events, without ever
 * mutating the underlying Phase 01–10 state.
 */
class NotificationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function makeTournament(User $organizer, string $status = 'open', array $o = []): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = $o['name'] ?? 'Test Tournament';
        $t->slug = $o['slug'] ?? ('t-' . Str::random(8));
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = $o['entry_fee'] ?? 0;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->rules = null;
        $t->starts_at = now()->addDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->dispute_window_hours = $o['dispute_window_hours'] ?? 24;
        $t->status = $status;
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

    // ------------------------------------------------------------------
    // Payment events
    // ------------------------------------------------------------------

    public function test_payment_verified_notifies_payer(): void
    {
        Mail::fake();

        $organizer = $this->makeUser('organizer');
        $payer = $this->makeUser('player');
        $admin = $this->makeUser('admin');
        $tournament = $this->makeTournament($organizer, 'open', ['entry_fee' => 100]);
        $team = $this->makeTeam($tournament, $payer, Team::STATUS_PENDING);

        $payment = app(PaymentService::class)->createForTeam($tournament, $team, $payer, 'bkash', 'TRX123');

        app(PaymentService::class)->verifyManually($payment, $admin);

        $this->assertTrue(Notification::where('user_id', $payer->id)
            ->where('type', Notification::TYPE_PAYMENT_VERIFIED)->exists());
    }

    public function test_payment_failed_notifies_payer(): void
    {
        Mail::fake();

        $organizer = $this->makeUser('organizer');
        $payer = $this->makeUser('player');
        $admin = $this->makeUser('admin');
        $tournament = $this->makeTournament($organizer, 'open', ['entry_fee' => 100]);
        $team = $this->makeTeam($tournament, $payer, Team::STATUS_PENDING);

        $payment = app(PaymentService::class)->createForTeam($tournament, $team, $payer, 'bkash', 'TRX123');
        app(PaymentService::class)->markFailed($payment, $admin, 'wrong trx');

        $this->assertTrue(Notification::where('user_id', $payer->id)
            ->where('type', Notification::TYPE_PAYMENT_FAILED)->exists());
    }

    // ------------------------------------------------------------------
    // Dispute events
    // ------------------------------------------------------------------

    public function test_dispute_opened_notifies_opponent_organizer_and_staff(): void
    {
        Mail::fake();

        $organizer = $this->makeUser('organizer');
        $moderator = $this->makeUser('moderator');
        $captainA = $this->makeUser('player');
        $captainB = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer, 'live');
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament, $captainB);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $match->winner_team_id = $teamB->id;
        $match->status = GameMatch::STATUS_COMPLETED;
        $match->completed_at = now();
        $match->save();

        app(DisputeService::class)->open($match, $teamA, $captainA, 'wrong_winner', 'We won.');

        $this->assertTrue(Notification::where('user_id', $captainB->id)
            ->where('type', Notification::TYPE_DISPUTE_OPENED)->exists(), 'opponent notified');
        $this->assertTrue(Notification::where('user_id', $organizer->id)
            ->where('type', Notification::TYPE_DISPUTE_OPENED)->exists(), 'organizer notified');
        $this->assertTrue(Notification::where('user_id', $moderator->id)
            ->where('type', Notification::TYPE_DISPUTE_OPENED)->exists(), 'staff notified');
        $this->assertFalse(Notification::where('user_id', $captainA->id)
            ->where('type', Notification::TYPE_DISPUTE_OPENED)->exists(), 'opener not self-notified');
    }

    public function test_dispute_resolution_notifies_participants(): void
    {
        Mail::fake();

        $organizer = $this->makeUser('organizer');
        $moderator = $this->makeUser('moderator');
        $captainA = $this->makeUser('player');
        $captainB = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer, 'live');
        $teamA = $this->makeTeam($tournament, $captainA);
        $teamB = $this->makeTeam($tournament, $captainB);
        $match = $this->makeMatch($tournament, $teamA, $teamB);

        $match->winner_team_id = $teamB->id;
        $match->status = GameMatch::STATUS_COMPLETED;
        $match->completed_at = now();
        $match->save();

        $dispute = app(DisputeService::class)->open($match, $teamA, $captainA, 'wrong_winner', 'We won.');
        app(DisputeService::class)->resolve($dispute, $moderator, $teamB, 'result upheld');

        $this->assertTrue(Notification::where('user_id', $captainA->id)
            ->where('type', Notification::TYPE_DISPUTE_RESOLVED)->exists());
        $this->assertTrue(Notification::where('user_id', $captainB->id)
            ->where('type', Notification::TYPE_DISPUTE_RESOLVED)->exists());
    }

    // ------------------------------------------------------------------
    // Moderation / identity / anti-cheat events
    // ------------------------------------------------------------------

    public function test_restriction_notifies_user_on_apply_and_lift(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin');
        $user = $this->makeUser('player');
        $service = app(RestrictionService::class);

        $restriction = $service->restrict($user, Restriction::TYPE_DISPUTE_BLOCKED, 'dispute abuse', 'manual', $admin);

        $this->assertTrue(Notification::where('user_id', $user->id)
            ->where('type', Notification::TYPE_RESTRICTION_APPLIED)->exists());

        $service->lift($restriction, $admin);

        $this->assertTrue(Notification::where('user_id', $user->id)
            ->where('type', Notification::TYPE_RESTRICTION_LIFTED)->exists());
    }

    public function test_identity_verified_notifies_user(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin');
        $user = $this->makeUser('player');

        app(IdentityVerificationService::class)->verifyManually($user, $admin, 'docs ok');

        $this->assertTrue(Notification::where('user_id', $user->id)
            ->where('type', Notification::TYPE_IDENTITY_VERIFIED)->exists());
    }

    public function test_identity_rejected_notifies_user(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin');
        $user = $this->makeUser('player');

        app(IdentityVerificationService::class)->reject($user, $admin, 'unreadable');

        $this->assertTrue(Notification::where('user_id', $user->id)
            ->where('type', Notification::TYPE_IDENTITY_REJECTED)->exists());
    }

    public function test_anti_cheat_resolution_notifies_accused_and_reporter(): void
    {
        Mail::fake();

        $organizer = $this->makeUser('organizer');
        $moderator = $this->makeUser('moderator');
        $accused = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer);

        $service = app(AntiCheatService::class);

        $incident = $service->openIncident(
            $tournament,
            null,
            null,
            $accused,
            $moderator,
            AntiCheatIncident::SOURCE_STAFF,
            AntiCheatService::CATEGORY_AIMBOT,
            'high',
            'suspicious tracking',
        );

        $service->review($incident, $moderator);
        $service->resolve($incident, $moderator, AntiCheatIncident::STATUS_CLEARED, 'no evidence found');

        $this->assertTrue(Notification::where('user_id', $accused->id)
            ->where('type', Notification::TYPE_ANTI_CHEAT_RESOLVED)->exists());
        $this->assertTrue(Notification::where('user_id', $moderator->id)
            ->where('type', Notification::TYPE_ANTI_CHEAT_RESOLVED)->exists());
    }

    // ------------------------------------------------------------------
    // Team registration events
    // ------------------------------------------------------------------

    public function test_team_registration_notifies_captain_and_organizer(): void
    {
        Mail::fake();

        $organizer = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer);

        $this->actingAs($captain)->post(route('teams.store', $tournament), [
            'name' => 'Notification Team',
            'captain_name' => $captain->name,
            'phone' => '01700000000',
            'game_uid' => 'UIDNOTIF1',
        ])->assertRedirect();

        $this->assertTrue(Notification::where('user_id', $captain->id)
            ->where('type', Notification::TYPE_TEAM_REGISTERED)->exists());
        $this->assertTrue(Notification::where('user_id', $organizer->id)
            ->where('type', Notification::TYPE_TEAM_REGISTERED)->exists());
    }

    public function test_team_withdrawal_notifies_organizer(): void
    {
        Mail::fake();

        $organizer = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($organizer);
        $team = $this->makeTeam($tournament, $captain, Team::STATUS_PENDING);

        $this->actingAs($captain)->post(route('teams.withdraw', [$tournament, $team]))->assertRedirect();

        $this->assertTrue(Notification::where('user_id', $organizer->id)
            ->where('type', Notification::TYPE_TEAM_WITHDRAWN)->exists());
    }

    // ------------------------------------------------------------------
    // Phase 10 state is never mutated by notification delivery
    // ------------------------------------------------------------------

    public function test_restriction_notification_does_not_change_restriction_state(): void
    {
        Mail::fake();

        $admin = $this->makeUser('admin');
        $user = $this->makeUser('player');
        $service = app(RestrictionService::class);

        $restriction = $service->restrict($user, Restriction::TYPE_ACCOUNT_SUSPENDED, 'review', 'manual', $admin);

        $this->assertSame(Restriction::STATUS_ACTIVE, $restriction->fresh()->status);
        $this->assertSame(RiskEvent::SEVERITY_CRITICAL, RiskEvent::where('user_id', $user->id)
            ->where('type', RiskEvent::TYPE_ACCOUNT_RESTRICTED)->first()->severity);

        // The notification carries no raw sensitive data.
        $notification = Notification::where('user_id', $user->id)
            ->where('type', Notification::TYPE_RESTRICTION_APPLIED)->first();

        $this->assertArrayHasKey('restriction_id', $notification->data);
        $this->assertArrayNotHasKey('password', $notification->data);
        $this->assertArrayNotHasKey('token', $notification->data);
    }
}
```


#### `tests/Feature/NotificationHttpTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Phase 11 — HTTP authorization for the notification inbox (IDOR / role
 * escalation), read-state routes and the unread badge.
 */
class NotificationHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player'): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    protected function service(): NotificationService
    {
        return app(NotificationService::class);
    }

    public function test_guest_is_redirected_from_notifications(): void
    {
        $this->get('/notifications')->assertRedirect('/login');
    }

    public function test_authenticated_user_sees_their_inbox(): void
    {
        Mail::fake();
        config(['notifications.email_enabled' => false]);

        $user = $this->makeUser();
        $this->service()->send($user, 'system', 'Hello', 'Body');

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Notifications')
            ->assertSee('Hello');
    }

    public function test_user_cannot_see_another_users_notifications(): void
    {
        Mail::fake();
        config(['notifications.email_enabled' => false]);

        $owner = $this->makeUser();
        $intruder = $this->makeUser();

        $notification = $this->service()->send($owner, 'system', 'Private', 'Secret body.');

        // The intruder's inbox must not contain the owner's notification.
        $this->actingAs($intruder)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertDontSee('Private');
    }

    public function test_user_cannot_mark_another_users_notification_read(): void
    {
        Mail::fake();
        config(['notifications.email_enabled' => false]);

        $owner = $this->makeUser();
        $intruder = $this->makeUser();

        $notification = $this->service()->send($owner, 'system', 'Private', 'Body');

        $this->actingAs($intruder)
            ->post(route('notifications.read', $notification))
            ->assertStatus(403);

        $this->assertFalse($notification->fresh()->isRead());
    }

    public function test_owner_can_mark_read_via_http(): void
    {
        Mail::fake();
        config(['notifications.email_enabled' => false]);

        $owner = $this->makeUser();
        $notification = $this->service()->send($owner, 'system', 'Hello', 'Body');

        $this->actingAs($owner)
            ->from(route('notifications.index'))
            ->post(route('notifications.read', $notification))
            ->assertRedirect(route('notifications.index'));

        $this->assertTrue($notification->fresh()->isRead());
    }

    public function test_owner_can_mark_all_read_via_http(): void
    {
        Mail::fake();
        config(['notifications.email_enabled' => false]);

        $owner = $this->makeUser();
        $this->service()->send($owner, 'system', 'A', '1');
        $this->service()->send($owner, 'system', 'B', '2');

        $this->actingAs($owner)
            ->from(route('notifications.index'))
            ->post(route('notifications.readAll'))
            ->assertRedirect(route('notifications.index'));

        $this->assertSame(0, $this->service()->unreadCount($owner));
    }

    public function test_badge_count_is_exposed_to_layout(): void
    {
        Mail::fake();
        config(['notifications.email_enabled' => false]);

        $owner = $this->makeUser();
        $this->service()->send($owner, 'system', 'A', '1');

        $this->actingAs($owner)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Notifications');
    }

    public function test_non_existent_notification_returns_404(): void
    {
        $this->actingAs($this->makeUser())
            ->post(route('notifications.read', 999999))
            ->assertNotFound();
    }
}
```

### 5.2 Modified files


#### `app/Providers/AppServiceProvider.php`

```php
<?php

namespace App\Providers;

use App\Services\NotificationService;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Expose the authenticated user's unread notification count to the
        // shared layout (Phase 11). Guests always see zero.
        View::composer('layouts.app', function ($view) {
            $user = auth()->user();

            $view->with('unreadNotifications', $user !== null
                ? app(NotificationService::class)->unreadCount($user)
                : 0);
        });
    }
}
```


#### `app/Services/DisputeService.php`

```php
<?php

namespace App\Services;

use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\GameMatch;
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


#### `app/Services/PaymentService.php`

```php
<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Refund;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Payment lifecycle + financial integrity (Phase 08).
 *
 * Single authority for payment intents, state transitions, manual/admin
 * verification, refunds and provider callbacks. Amounts are integer minor
 * units (poisha), always derived from the tournament — never from clients.
 */
class PaymentService
{
    public function __construct(
        protected PaymentGatewayManager $gateways,
        protected WalletService $wallets,
        protected NotificationService $notifications,
    ) {
    }

    /**
     * Create a payment intent for a team's entry fee.
     *
     * Idempotent per team: if the team already has an active (pending /
     * processing / paid / verified) payment, a DomainException is thrown so
     * the caller can redirect to the existing one.
     */
    public function createForTeam(
        Tournament $tournament,
        Team $team,
        User $payer,
        string $method,
        string $trxId,
    ): Payment {
        if (! $team->belongsToTournament($tournament)) {
            throw new DomainException('This team does not belong to this tournament.');
        }

        if (! $tournament->acceptsRegistration()) {
            throw new DomainException('Payment is no longer accepted for this tournament.');
        }

        if ($team->status !== Team::STATUS_PENDING) {
            throw new DomainException('This team is not awaiting payment.');
        }

        $existing = Payment::where('team_id', $team->id)
            ->whereIn('status', Payment::ACTIVE_STATUSES)
            ->first();

        if ($existing !== null) {
            throw new DomainException('This team already has an active payment.');
        }

        // The amount is always derived from the server-side entry fee.
        $minor = $tournament->entryFeeMinor();
        $provider = $this->gateways->defaultProvider();
        $idempotencyKey = Str::uuid();

        return DB::transaction(function () use ($tournament, $team, $payer, $method, $trxId, $minor, $provider, $idempotencyKey) {
            $payment = new Payment();
            $payment->tournament_id = $tournament->id;
            $payment->team_id = $team->id;
            $payment->payer_user_id = $payer->id;
            $payment->amount_minor = $minor;
            $payment->amount = Money::toDecimal($minor);
            $payment->currency = 'BDT';
            $payment->method = $method;
            $payment->trx_id = strtoupper(trim($trxId));
            $payment->provider = $provider;
            $payment->provider_reference = strtoupper(trim($trxId));
            $payment->idempotency_key = $idempotencyKey;
            $payment->status = Payment::STATUS_PENDING;
            $payment->save();

            $this->recordEvent($payment, $payer, PaymentEvent::EVENT_CREATED, $minor);

            // Free-entry tournaments are auto-confirmed (Phase 01–07 demo
            // behaviour preserved).
            if ($minor <= 0) {
                $this->settleSuccess($payment, Payment::STATUS_VERIFIED, $payer);
            }

            return $payment;
        });
    }

    /**
     * Admin/manual verification of a pending payment (demo bKash flow).
     */
    public function verifyManually(Payment $payment, User $admin): Payment
    {
        if (! in_array($payment->status, [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING], true)) {
            throw new DomainException('Only pending payments can be verified.');
        }

        return DB::transaction(function () use ($payment, $admin) {
            $this->settleSuccess($payment, Payment::STATUS_VERIFIED, $admin);

            return $payment;
        });
    }

    /**
     * Mark a pending/processing payment failed.
     */
    public function markFailed(Payment $payment, User $actor, string $reason = ''): Payment
    {
        if (! in_array($payment->status, [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING], true)) {
            throw new DomainException('This payment cannot be failed from its current state.');
        }

        return DB::transaction(function () use ($payment, $actor, $reason) {
            $payment->status = Payment::STATUS_FAILED;
            $payment->save();

            $this->recordEvent($payment, $actor, PaymentEvent::EVENT_FAILED, $payment->amountMinor(), ['reason' => $reason]);

            // Phase 11 — notify the payer.
            $payer = $payment->payer ?? $payment->team?->captain;

            if ($payer !== null) {
                $this->notifications->send(
                    $payer,
                    Notification::TYPE_PAYMENT_FAILED,
                    'Payment failed',
                    'Your entry fee payment for ' . ($payment->tournament?->name ?? 'a tournament') . ' was marked failed.',
                    NotificationService::link('teams.show', [$payment->tournament, $payment->team]),
                    ['payment_id' => $payment->id],
                );
            }

            return $payment;
        });
    }

    /**
     * Cancel a pending/processing payment.
     */
    public function cancel(Payment $payment, User $actor): Payment
    {
        if (! in_array($payment->status, [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING], true)) {
            throw new DomainException('This payment cannot be cancelled from its current state.');
        }

        return DB::transaction(function () use ($payment, $actor) {
            $payment->status = Payment::STATUS_CANCELLED;
            $payment->save();

            $this->recordEvent($payment, $actor, PaymentEvent::EVENT_CANCELLED, $payment->amountMinor());

            return $payment;
        });
    }

    /**
     * Refund a settled payment (full amount only), credit the payer's wallet,
     * and record the refund + ledger + audit trail. Idempotent: a second
     * refund of the same payment is rejected.
     */
    public function refund(Payment $payment, User $admin, string $reason): Refund
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('A refund reason is required.');
        }

        if (! $payment->isRefundable()) {
            throw new DomainException('Only settled payments can be refunded.');
        }

        $minor = $payment->amountMinor();

        return DB::transaction(function () use ($payment, $admin, $reason, $minor) {
            if (Refund::where('payment_id', $payment->id)->exists()) {
                throw new DomainException('This payment has already been refunded.');
            }

            $payment->status = Payment::STATUS_REFUNDED;
            $payment->refunded_at = now();
            $payment->save();

            $refund = new Refund();
            $refund->payment_id = $payment->id;
            $refund->amount_minor = $minor;
            $refund->currency = 'BDT';
            $refund->reason = $reason;
            $refund->processed_by = $admin->id;
            $refund->save();

            $this->recordEvent($payment, $admin, PaymentEvent::EVENT_REFUNDED, $minor, ['reason' => $reason]);

            // Credit the payer's wallet (when a payer account exists). This is
            // the platform-side representation of the refund; external gateway
            // refunds are NOT simulated.
            $payer = $payment->payer ?? $payment->team?->captain;

            if ($payer !== null) {
                $wallet = $this->wallets->walletFor($payer);
                $this->wallets->credit(
                    $wallet,
                    $minor,
                    \App\Models\LedgerEntry::TYPE_REFUND,
                    'Refund for tournament entry fee',
                    $admin,
                    'refund',
                    $refund->id,
                );

                // Phase 11 — notify the payer of the refund.
                $this->notifications->send(
                    $payer,
                    Notification::TYPE_PAYMENT_REFUNDED,
                    'Entry fee refunded',
                    'Your entry fee for ' . ($payment->tournament?->name ?? 'a tournament') . ' was refunded.',
                    NotificationService::link('wallet.index'),
                    ['payment_id' => $payment->id, 'refund_id' => $refund->id],
                );
            }

            return $refund;
        });
    }

    /**
     * Process a provider callback/webhook. Signature is verified against the
     * configured secret, then amount/currency/payment are validated and the
     * state transition applied. Fully idempotent: repeated or replayed
     * callbacks return the current state without double effects.
     */
    public function handleProviderCallback(string $provider, array $payload, string $signature, string $rawBody): Payment
    {
        if (! $this->verifySignature($rawBody, $signature)) {
            throw new DomainException('Invalid webhook signature.');
        }

        $paymentId = (int) ($payload['payment_id'] ?? 0);
        $reference = (string) ($payload['provider_reference'] ?? '');
        $amountMinor = (int) ($payload['amount_minor'] ?? 0);
        $currency = (string) ($payload['currency'] ?? 'BDT');
        $status = (string) ($payload['status'] ?? '');

        $payment = Payment::find($paymentId);

        if ($payment === null) {
            throw new DomainException('Unknown payment.', 404);
        }

        if ($payment->provider !== $provider) {
            throw new DomainException('Provider mismatch.', 404);
        }

        if ($reference !== '' && $payment->provider_reference !== null && $payment->provider_reference !== $reference) {
            throw new DomainException('Provider reference mismatch.', 400);
        }

        if ($payment->currency !== $currency) {
            throw new DomainException('Currency mismatch.', 400);
        }

        if ($payment->amountMinor() !== $amountMinor) {
            throw new DomainException('Amount mismatch.', 400);
        }

        if (! in_array($status, [Payment::STATUS_PAID, Payment::STATUS_FAILED], true)) {
            throw new DomainException('Invalid callback status.', 400);
        }

        // Idempotency: an already-settled payment simply reports its state.
        if ($payment->status === Payment::STATUS_PAID || $payment->status === Payment::STATUS_VERIFIED) {
            $this->recordEvent($payment, null, PaymentEvent::EVENT_CALLBACK, $amountMinor, ['duplicate' => true, 'reference' => $reference]);

            return $payment;
        }

        if (! in_array($payment->status, [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING], true)) {
            throw new DomainException('This payment is no longer actionable.', 400);
        }

        return DB::transaction(function () use ($payment, $status, $amountMinor, $reference) {
            if ($status === Payment::STATUS_PAID) {
                $this->settleSuccess($payment, Payment::STATUS_PAID, null);
            } else {
                $payment->status = Payment::STATUS_FAILED;
                $payment->save();
            }

            $this->recordEvent($payment, null, PaymentEvent::EVENT_CALLBACK, $amountMinor, ['status' => $status, 'reference' => $reference]);

            return $payment;
        });
    }

    /**
     * Verify an HMAC-SHA256 webhook signature against the configured secret.
     */
    public function verifySignature(string $rawBody, string $signature): bool
    {
        $secret = (string) config('services.payments.webhook_secret', '');

        if ($secret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Move a payment into a success state, timestamp it, confirm the team
     * (if still pending) and write the audit event.
     */
    protected function settleSuccess(Payment $payment, string $targetStatus, ?User $actor): void
    {
        $payment->status = $targetStatus;
        $payment->paid_at = now();
        $payment->save();

        $team = $payment->team;
        if ($team !== null && $team->status === Team::STATUS_PENDING) {
            $team->status = Team::STATUS_CONFIRMED;
            $team->save();
        }

        $event = $targetStatus === Payment::STATUS_PAID
            ? PaymentEvent::EVENT_PAID
            : PaymentEvent::EVENT_VERIFIED;

        $this->recordEvent($payment, $actor, $event, $payment->amountMinor());

        // Phase 11 — notify the payer that the payment was accepted.
        $payer = $payment->payer ?? $payment->team?->captain;

        if ($payer !== null) {
            $this->notifications->send(
                $payer,
                Notification::TYPE_PAYMENT_VERIFIED,
                'Payment verified',
                'Your entry fee payment for ' . ($payment->tournament?->name ?? 'a tournament') . ' was verified.',
                NotificationService::link('teams.show', [$payment->tournament, $payment->team]),
                ['payment_id' => $payment->id],
            );
        }
    }

    protected function recordEvent(Payment $payment, ?User $actor, string $event, int $amountMinor, array $metadata = []): void
    {
        $record = new PaymentEvent();
        $record->payment_id = $payment->id;
        $record->actor_id = $actor?->id;
        $record->event = $event;
        $record->amount_minor = $amountMinor;
        $record->currency = 'BDT';
        $record->reference = $payment->provider_reference;
        $record->metadata = $metadata;
        $record->save();
    }
}
```


#### `app/Services/PayoutService.php`

```php
<?php

namespace App\Services;

use App\Exceptions\PayoutReviewRequiredException;
use App\Models\LedgerEntry;
use App\Models\Notification;
use App\Models\Payout;
use App\Models\PayoutEvent;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Payout lifecycle + wallet integration (Phase 09).
 *
 * The single authority for payout state transitions and disbursement.
 * Internal (wallet) payouts credit the recipient's wallet through
 * WalletService inside the same transaction that marks the payout completed,
 * so the payout record and the wallet/ledger can never diverge. External
 * (manual) payouts move to `processing` and are completed by hand — no
 * external success is ever faked.
 *
 * Idempotency: processing an already-completed payout returns the current
 * state; the unique (distribution_id, rank) and idempotency-key constraints
 * are the race-condition backstops.
 */
class PayoutService
{
    public function __construct(
        protected WalletService $wallets,
        protected PayoutGatewayManager $gateways,
        protected FraudRiskService $risk,
        protected NotificationService $notifications,
    ) {
    }

    /**
     * Process an approved payout.
     *
     *  - Internal wallet provider: credit the recipient's wallet (with the
     *    matching ledger entry) and mark the payout completed, atomically.
     *  - Manual provider: move to `processing`; an admin completes it by hand
     *    (completeManually).
     *
     * The recipient's fraud risk is evaluated first (Phase 10): a payout that
     * requires review is HELD (PayoutReviewRequiredException) — it is never
     * silently paid to a flagged recipient, and never auto-confiscated. An
     * authorized admin can process it via processWithOverride().
     */
    public function process(Payout $payout, User $actor): Payout
    {
        $action = $this->risk->evaluatePayout($payout);

        if ($action !== FraudRiskService::ACTION_ALLOW) {
            throw new PayoutReviewRequiredException(
                'This payout requires fraud review before it can be processed (recipient risk action: ' . $action . ').'
            );
        }

        return $this->processInternal($payout, $actor);
    }

    /**
     * Process a payout with an authorized fraud-review override. The override
     * is audited (reason + actor) so the exemption is always explainable.
     */
    public function processWithOverride(Payout $payout, User $actor, string $reason): Payout
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('An override reason is required.');
        }

        $this->recordEvent($payout, $actor, PayoutEvent::EVENT_PROCESSING, $payout->amountMinor(), [
            'override' => true,
            'reason' => $reason,
        ]);

        return $this->processInternal($payout, $actor);
    }

    /**
     * The shared disbursement path (gate already applied).
     */
    protected function processInternal(Payout $payout, User $actor): Payout
    {
        $gateway = $this->gateways->gateway($payout->provider);

        if (! $gateway->isInternal()) {
            return $this->advanceToProcessing($payout, $actor);
        }

        if ($payout->recipient_user_id === null) {
            throw new DomainException('This payout has no recipient.');
        }

        return DB::transaction(function () use ($payout, $actor) {
            $fresh = Payout::query()->where('id', $payout->id)->lockForUpdate()->firstOrFail();

            // Idempotency: a completed payout is simply reported back.
            if ($fresh->status === Payout::STATUS_COMPLETED) {
                return $fresh;
            }

            if (! in_array($fresh->status, [Payout::STATUS_APPROVED, Payout::STATUS_PROCESSING], true)) {
                throw new DomainException('Only approved payouts can be processed.');
            }

            $recipient = $fresh->recipient;

            if ($recipient === null) {
                throw new DomainException('This payout has no recipient.');
            }

            $fresh->status = Payout::STATUS_PROCESSING;
            $fresh->save();

            // The wallet credit and its ledger entry commit atomically with
            // the payout state below — the two can never diverge.
            $wallet = $this->wallets->walletFor($recipient);

            $this->wallets->credit(
                $wallet,
                $fresh->amountMinor(),
                LedgerEntry::TYPE_PAYOUT,
                'Prize payout — ' . ($fresh->tournament?->name ?? 'Tournament') . ' (' . $this->ordinal((int) $fresh->rank) . ' place)',
                $actor,
                'payout',
                $fresh->id,
            );

            $fresh->status = Payout::STATUS_COMPLETED;
            $fresh->processed_by = $actor->id;
            $fresh->processed_at = now();
            $fresh->save();

            $this->recordEvent($fresh, $actor, PayoutEvent::EVENT_COMPLETED, $fresh->amountMinor());

            // Phase 11 — notify the recipient.
            $this->notifications->send(
                $recipient,
                Notification::TYPE_PAYOUT_PROCESSED,
                'Prize payout received',
                'You received ' . $this->moneyLabel($fresh->amountMinor()) . ' for ' . ($fresh->tournament?->name ?? 'a tournament') . '.',
                NotificationService::link('wallet.index'),
                ['payout_id' => $fresh->id, 'amount_minor' => $fresh->amountMinor()],
            );

            return $fresh;
        });
    }

    /**
     * Manually complete an external (non-internal) payout, recording the
     * provider reference. Internal wallet payouts complete automatically
     * during process() and can never be completed by hand.
     */
    public function completeManually(Payout $payout, User $actor, ?string $reference = null): Payout
    {
        $gateway = $this->gateways->gateway($payout->provider);

        if ($gateway->isInternal()) {
            throw new DomainException('Internal wallet payouts complete automatically during processing.');
        }

        return DB::transaction(function () use ($payout, $actor, $reference) {
            $fresh = Payout::query()->where('id', $payout->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status === Payout::STATUS_COMPLETED) {
                return $fresh;
            }

            if ($fresh->status !== Payout::STATUS_PROCESSING) {
                throw new DomainException('Only processing payouts can be marked completed.');
            }

            $fresh->status = Payout::STATUS_COMPLETED;
            $fresh->processed_by = $actor->id;
            $fresh->processed_at = now();

            if ($reference !== null && trim($reference) !== '') {
                $fresh->provider_reference = trim($reference);
            }

            $fresh->save();

            $this->recordEvent($fresh, $actor, PayoutEvent::EVENT_COMPLETED, $fresh->amountMinor(), ['reference' => $reference]);

            return $fresh;
        });
    }

    /**
     * Approve a pending payout.
     */
    public function approve(Payout $payout, User $actor): Payout
    {
        if ($payout->status !== Payout::STATUS_PENDING) {
            throw new DomainException('Only pending payouts can be approved.');
        }

        return DB::transaction(function () use ($payout, $actor) {
            $fresh = Payout::query()->where('id', $payout->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status === Payout::STATUS_APPROVED) {
                return $fresh;
            }

            if ($fresh->status !== Payout::STATUS_PENDING) {
                throw new DomainException('Only pending payouts can be approved.');
            }

            $fresh->status = Payout::STATUS_APPROVED;
            $fresh->approved_by = $actor->id;
            $fresh->save();

            $this->recordEvent($fresh, $actor, PayoutEvent::EVENT_APPROVED, $fresh->amountMinor());

            return $fresh;
        });
    }

    /**
     * Mark a payout failed with a reason.
     */
    public function markFailed(Payout $payout, User $actor, string $reason = ''): Payout
    {
        if (! in_array($payout->status, [Payout::STATUS_PENDING, Payout::STATUS_APPROVED, Payout::STATUS_PROCESSING], true)) {
            throw new DomainException('This payout cannot be failed from its current state.');
        }

        return DB::transaction(function () use ($payout, $actor, $reason) {
            $payout->status = Payout::STATUS_FAILED;
            $payout->failure_reason = $reason !== '' ? $reason : null;
            $payout->save();

            $this->recordEvent($payout, $actor, PayoutEvent::EVENT_FAILED, $payout->amountMinor(), ['reason' => $reason]);

            // Phase 11 — notify the recipient.
            $recipient = $payout->recipient;

            if ($recipient !== null) {
                $this->notifications->send(
                    $recipient,
                    Notification::TYPE_PAYOUT_FAILED,
                    'Prize payout failed',
                    'A prize payout for ' . ($payout->tournament?->name ?? 'a tournament') . ' could not be processed.',
                    NotificationService::link('wallet.index'),
                    ['payout_id' => $payout->id],
                );
            }

            return $payout;
        });
    }

    /**
     * Cancel a payout that has not started paying out.
     */
    public function cancel(Payout $payout, User $actor): Payout
    {
        if (! in_array($payout->status, [Payout::STATUS_PENDING, Payout::STATUS_APPROVED], true)) {
            throw new DomainException('This payout cannot be cancelled from its current state.');
        }

        return DB::transaction(function () use ($payout, $actor) {
            $payout->status = Payout::STATUS_CANCELLED;
            $payout->save();

            $this->recordEvent($payout, $actor, PayoutEvent::EVENT_CANCELLED, $payout->amountMinor());

            return $payout;
        });
    }

    /**
     * Move an external payout into processing (no disbursement yet).
     */
    protected function advanceToProcessing(Payout $payout, User $actor): Payout
    {
        return DB::transaction(function () use ($payout, $actor) {
            $fresh = Payout::query()->where('id', $payout->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status === Payout::STATUS_COMPLETED) {
                return $fresh;
            }

            if (! in_array($fresh->status, [Payout::STATUS_APPROVED, Payout::STATUS_PROCESSING], true)) {
                throw new DomainException('Only approved payouts can be processed.');
            }

            $fresh->status = Payout::STATUS_PROCESSING;
            $fresh->save();

            $this->recordEvent($fresh, $actor, PayoutEvent::EVENT_PROCESSING, $fresh->amountMinor());

            return $fresh;
        });
    }

    /**
     * Append a row to the payout audit trail.
     */
    public function recordEvent(Payout $payout, ?User $actor, string $event, int $amountMinor, array $metadata = []): PayoutEvent
    {
        $record = new PayoutEvent();
        $record->payout_id = $payout->id;
        $record->actor_id = $actor?->id;
        $record->event = $event;
        $record->amount_minor = $amountMinor;
        $record->currency = 'BDT';
        $record->metadata = $metadata;
        $record->save();

        return $record;
    }

    /**
     * Display-only minor-units (poisha) formatter. Integer money only — this
     * never participates in money arithmetic.
     */
    protected function moneyLabel(int $amountMinor): string
    {
        return '৳' . number_format($amountMinor / 100, 2);
    }

    /**
     * English ordinal for display ("1st", "2nd", "3rd", "4th", …).
     */
    protected function ordinal(int $rank): string
    {
        $suffixes = ['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'];

        if (($rank % 100) >= 11 && ($rank % 100) <= 13) {
            return $rank . 'th';
        }

        return $rank . $suffixes[$rank % 10];
    }
}
```


#### `app/Services/PrizeDistributionService.php`

```php
<?php

namespace App\Services;

use App\Models\Dispute;
use App\Models\GameMatch;
use App\Models\Notification;
use App\Models\Payout;
use App\Models\PrizeDistribution;
use App\Models\PrizeSnapshotItem;
use App\Models\PrizeTier;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Money;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Prize distribution workflow (Phase 09).
 *
 * Orchestrates prize-tier configuration, the draft → calculated → approved →
 * processing → completed/failed/cancelled state machine, the immutable prize
 * snapshot and the payout records. Final standings come exclusively from the
 * Phase 06 ScoringService; eligibility is gated on the Phase 05/07 lifecycle
 * and dispute state.
 *
 * Amounts are integer poisha throughout; percentage tiers resolve against the
 * frozen prize pool with integer arithmetic (no floats).
 */
class PrizeDistributionService
{
    public function __construct(
        protected ScoringService $scoring,
        protected PayoutService $payouts,
        protected ReconciliationService $reconciliation,
        protected PayoutGatewayManager $gateways,
        protected NotificationService $notifications,
    ) {
    }

    /**
     * The configured prize tiers for a tournament, ordered by rank.
     *
     * @return Collection<int, PrizeTier>
     */
    public function tiers(Tournament $tournament): Collection
    {
        return $tournament->prizeTiers()->orderBy('position')->get();
    }

    /**
     * The currently live (non-terminal) distribution, or null.
     */
    public function activeDistribution(Tournament $tournament): ?PrizeDistribution
    {
        return $tournament->prizeDistributions()
            ->whereIn('status', PrizeDistribution::ACTIVE_STATUSES)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The most recent distribution (any state), or null.
     */
    public function latestDistribution(Tournament $tournament): ?PrizeDistribution
    {
        return $tournament->prizeDistributions()->orderByDesc('id')->first();
    }

    // ------------------------------------------------------------------
    // Prize configuration
    // ------------------------------------------------------------------

    /**
     * Replace a tournament's prize tiers with a validated set.
     *
     * @param array<int, array{position:int, type:string, value:string}> $rows
     */
    public function saveTiers(Tournament $tournament, array $rows, User $admin): void
    {
        $this->assertTiersEditable($tournament);

        $pool = $tournament->prizePoolMinor();
        $normalized = $this->normalizeTiers($rows, $pool);

        DB::transaction(function () use ($tournament, $normalized) {
            $tournament->prizeTiers()->delete();

            foreach ($normalized as $row) {
                $tier = new PrizeTier();
                $tier->tournament_id = $tournament->id;
                $tier->position = $row['position'];
                $tier->type = $row['type'];
                $tier->amount_minor = $row['amount_minor'];
                $tier->percentage_bp = $row['percentage_bp'];
                $tier->save();
            }
        });
    }

    /**
     * Validate and normalise prize-tier rows into a deterministic,
     * position-ordered structure. Server-authoritative: rejects negative
     * amounts/percentages, duplicate positions, invalid positions, totals
     * over 100% and allocations that exceed the available prize pool.
     *
     * @param array<int, array{position:int, type:string, value:string}> $rows
     * @return array<int, array{position:int, type:string, amount_minor:?int, percentage_bp:?int}>
     */
    public function normalizeTiers(array $rows, int $pool): array
    {
        $seen = [];
        $normalized = [];
        $fixedSum = 0;
        $basisPointsSum = 0;

        foreach ($rows as $row) {
            $position = (int) ($row['position'] ?? 0);
            $type = (string) ($row['type'] ?? '');
            $value = trim((string) ($row['value'] ?? ''));

            // Blank rows are ignored (fixed-row forms submit empty slots).
            if ($value === '') {
                continue;
            }

            if ($position < 1 || $position > PrizeTier::MAX_POSITION) {
                throw new DomainException('Prize positions must be between 1 and ' . PrizeTier::MAX_POSITION . '.');
            }

            if (isset($seen[$position])) {
                throw new DomainException('Duplicate prize position: ' . $position . '.');
            }

            $seen[$position] = true;

            if (! in_array($type, PrizeTier::TYPES, true)) {
                throw new DomainException('Prize type must be fixed or percentage.');
            }

            if ($type === PrizeTier::TYPE_FIXED) {
                $amountMinor = Money::toMinor($value);

                if ($amountMinor <= 0) {
                    throw new DomainException('Fixed prize amounts must be positive.');
                }

                $fixedSum += $amountMinor;

                $normalized[] = [
                    'position' => $position,
                    'type' => $type,
                    'amount_minor' => $amountMinor,
                    'percentage_bp' => null,
                ];
            } else {
                $basisPoints = Money::toBasisPoints($value);

                if ($basisPoints <= 0 || $basisPoints > 10000) {
                    throw new DomainException('Prize percentages must be between 0 and 100.');
                }

                $basisPointsSum += $basisPoints;

                $normalized[] = [
                    'position' => $position,
                    'type' => $type,
                    'amount_minor' => null,
                    'percentage_bp' => $basisPoints,
                ];
            }
        }

        if ($normalized === []) {
            throw new DomainException('At least one prize tier is required.');
        }

        usort($normalized, fn (array $a, array $b) => $a['position'] <=> $b['position']);

        if ($basisPointsSum > 10000) {
            throw new DomainException('Prize percentages cannot total more than 100%.');
        }

        $total = $fixedSum;

        foreach ($normalized as $row) {
            if ($row['type'] === PrizeTier::TYPE_PERCENTAGE) {
                $total += intdiv($pool * $row['percentage_bp'], 10000);
            }
        }

        if ($total > $pool) {
            throw new DomainException(
                'The configured prize allocation exceeds the available prize pool (৳' . Money::toDecimal($pool) . ').'
            );
        }

        return $normalized;
    }

    // ------------------------------------------------------------------
    // Distribution workflow
    // ------------------------------------------------------------------

    /**
     * Calculate the prize distribution: snapshot the tiers and the final
     * standings into immutable rows and move to `calculated`.
     *
     * Idempotent: re-running on an already-calculated distribution returns it
     * unchanged. A draft distribution is recomputed in place.
     */
    public function calculate(Tournament $tournament, User $admin): PrizeDistribution
    {
        $this->assertEligible($tournament);

        return DB::transaction(function () use ($tournament, $admin) {
            // Serialize concurrent calculations against the tournament row.
            Tournament::query()->where('id', $tournament->id)->lockForUpdate()->first();

            $active = $this->activeDistribution($tournament);

            if ($active === null) {
                if ($tournament->prizeDistributions()->where('status', PrizeDistribution::STATUS_COMPLETED)->exists()) {
                    throw new DomainException('This tournament has already been settled.');
                }

                $active = new PrizeDistribution();
                $active->tournament_id = $tournament->id;
                $active->status = PrizeDistribution::STATUS_DRAFT;
                $active->created_by = $admin->id;
                $active->idempotency_key = (string) Str::uuid();
                $active->save();
            } elseif ($active->status !== PrizeDistribution::STATUS_DRAFT) {
                // Already calculated/approved/processing — idempotent no-op.
                return $active;
            }

            $tiers = $this->tiers($tournament);

            if ($tiers->isEmpty()) {
                throw new DomainException('No prize tiers configured. Configure prizes first.');
            }

            $standings = $this->scoring->standings($tournament);

            if ($standings->isEmpty()) {
                throw new DomainException('Final standings are not available for this tournament.');
            }

            $pool = $tournament->prizePoolMinor();
            $tierByPosition = $tiers->keyBy('position');
            $maxPosition = (int) $tiers->max('position');

            // Recalculate a draft in place (snapshot rows are replaced).
            $active->snapshotItems()->delete();

            $total = 0;

            foreach ($standings as $row) {
                $rank = (int) $row->rank;

                if ($rank > $maxPosition) {
                    break;
                }

                $tier = $tierByPosition->get($rank);

                if ($tier === null) {
                    continue;
                }

                $team = $row->team;

                if (! $team instanceof Team || $team->captain_id === null) {
                    throw new DomainException('Ranked team #' . $rank . ' has no captain to receive the prize.');
                }

                $amountMinor = $this->resolveAmount($tier, $pool);

                $item = new PrizeSnapshotItem();
                $item->distribution_id = $active->id;
                $item->tournament_id = $tournament->id;
                $item->position = $rank;
                $item->team_id = $team->id;
                $item->type = $tier->type;
                $item->amount_minor = $amountMinor;
                $item->save();

                $total += $amountMinor;
            }

            $active->pool_minor = $pool;
            $active->total_allocated_minor = $total;
            $active->status = PrizeDistribution::STATUS_CALCULATED;
            $active->save();

            return $active;
        });
    }

    /**
     * Approve a calculated distribution, creating the payout records from the
     * snapshot. Idempotent on an already-approved distribution.
     */
    public function approve(Tournament $tournament, User $admin): PrizeDistribution
    {
        $distribution = $this->activeDistribution($tournament);

        if ($distribution === null) {
            throw new DomainException('No prize distribution exists. Calculate it first.');
        }

        if (in_array($distribution->status, [
            PrizeDistribution::STATUS_APPROVED,
            PrizeDistribution::STATUS_PROCESSING,
            PrizeDistribution::STATUS_COMPLETED,
        ], true)) {
            return $distribution;
        }

        if ($distribution->status !== PrizeDistribution::STATUS_CALCULATED) {
            throw new DomainException('Only a calculated distribution can be approved.');
        }

        return DB::transaction(function () use ($distribution, $admin) {
            $items = $distribution->snapshotItems()->with('team')->orderBy('position')->get();

            if ($items->isEmpty()) {
                throw new DomainException('No prizes were allocated; nothing to approve.');
            }

            $provider = $this->gateways->defaultProvider();

            foreach ($items as $item) {
                if (Payout::where('distribution_id', $distribution->id)->where('rank', $item->position)->exists()) {
                    continue;
                }

                $payout = new Payout();
                $payout->distribution_id = $distribution->id;
                $payout->tournament_id = $distribution->tournament_id;
                $payout->recipient_team_id = $item->team_id;
                $payout->recipient_user_id = $item->team?->captain_id;
                $payout->rank = $item->position;
                $payout->amount_minor = $item->amount_minor;
                $payout->currency = 'BDT';
                $payout->status = Payout::STATUS_APPROVED;
                $payout->payout_method = $provider === 'wallet' ? Payout::METHOD_WALLET : Payout::METHOD_MANUAL;
                $payout->provider = $provider;
                $payout->idempotency_key = (string) Str::uuid();
                $payout->approved_by = $admin->id;
                $payout->save();

                $this->payouts->recordEvent($payout, $admin, \App\Models\PayoutEvent::EVENT_APPROVED, $payout->amountMinor());
            }

            $distribution->status = PrizeDistribution::STATUS_APPROVED;
            $distribution->approved_by = $admin->id;
            $distribution->approved_at = now();
            $distribution->save();

            return $distribution;
        });
    }

    /**
     * Process an approved distribution: disburse every payout, then complete
     * the distribution and freeze the financial settlement.
     *
     * Idempotent: a completed distribution is returned unchanged; a
     * `processing` distribution resumes its remaining payouts. If a payout
     * fails, the payout and the distribution are marked failed.
     */
    public function process(Tournament $tournament, User $admin): PrizeDistribution
    {
        $distribution = $this->activeDistribution($tournament);

        if ($distribution === null) {
            // Idempotency: an already-completed distribution is returned.
            $latest = $this->latestDistribution($tournament);

            if ($latest !== null && $latest->status === PrizeDistribution::STATUS_COMPLETED) {
                return $latest;
            }

            throw new DomainException('No prize distribution exists.');
        }

        if ($distribution->status === PrizeDistribution::STATUS_COMPLETED) {
            return $distribution;
        }

        if ($distribution->status === PrizeDistribution::STATUS_PROCESSING) {
            // Resume — fall through to process remaining payouts.
        } elseif ($distribution->status === PrizeDistribution::STATUS_APPROVED) {
            $distribution->status = PrizeDistribution::STATUS_PROCESSING;
            $distribution->save();
        } else {
            throw new DomainException('Only an approved distribution can be processed.');
        }

        $remaining = $distribution->payouts()
            ->whereIn('status', [Payout::STATUS_PENDING, Payout::STATUS_APPROVED])
            ->orderBy('rank')
            ->get();

        foreach ($remaining as $payout) {
            try {
                $this->payouts->process($payout, $admin);
            } catch (\App\Exceptions\PayoutReviewRequiredException $e) {
                // Phase 10 — a payout held for fraud review stops the run
                // WITHOUT failing it. The distribution stays `processing`
                // until an admin overrides or clears the hold.
                break;
            } catch (DomainException $e) {
                $this->payouts->markFailed($payout, $admin, $e->getMessage());

                DB::transaction(function () use ($distribution, $e) {
                    $distribution->status = PrizeDistribution::STATUS_FAILED;
                    $distribution->failure_reason = 'A payout failed: ' . $e->getMessage();
                    $distribution->save();
                });

                return $distribution;
            }
        }

        // Internal (wallet) payouts complete during processing; manual payouts
        // stay in `processing` until an admin marks them completed by hand.
        $unfinished = $distribution->payouts()
            ->where('status', '!=', Payout::STATUS_COMPLETED)
            ->exists();

        if ($unfinished) {
            return $distribution;
        }

        DB::transaction(function () use ($distribution, $tournament, $admin) {
            $distribution->status = PrizeDistribution::STATUS_COMPLETED;
            $distribution->completed_at = now();
            $distribution->save();

            $this->reconciliation->finalize($tournament, $admin, $distribution);
        });

        // Phase 11 — notify the organizer and every paid recipient that the
        // settlement is final.
        $recipients = [];

        foreach ($distribution->payouts()->with('recipient')->get() as $payout) {
            if ($payout->recipient !== null) {
                $recipients[$payout->recipient->id] = $payout->recipient;
            }
        }

        $organizer = $tournament->organizer;

        if ($organizer !== null) {
            $recipients[$organizer->id] = $organizer;
        }

        $this->notifications->sendToMany(
            $recipients,
            Notification::TYPE_SETTLEMENT_COMPLETED,
            'Prize settlement completed',
            'Prize settlement for ' . $tournament->name . ' has completed.',
            NotificationService::link('tournaments.show', [$tournament]),
            ['tournament_id' => $tournament->id],
        );

        return $distribution;
    }

    /**
     * Cancel a distribution that has not started paying out, cancelling its
     * not-yet-terminal payouts.
     */
    public function cancel(Tournament $tournament, User $admin): PrizeDistribution
    {
        $distribution = $this->activeDistribution($tournament);

        if ($distribution === null) {
            throw new DomainException('No prize distribution exists.');
        }

        if (! in_array($distribution->status, [
            PrizeDistribution::STATUS_DRAFT,
            PrizeDistribution::STATUS_CALCULATED,
            PrizeDistribution::STATUS_APPROVED,
        ], true)) {
            throw new DomainException('This distribution cannot be cancelled from its current state.');
        }

        return DB::transaction(function () use ($distribution) {
            $distribution->payouts()
                ->whereIn('status', [Payout::STATUS_PENDING, Payout::STATUS_APPROVED])
                ->update(['status' => Payout::STATUS_CANCELLED]);

            $distribution->status = PrizeDistribution::STATUS_CANCELLED;
            $distribution->save();

            return $distribution;
        });
    }

    // ------------------------------------------------------------------
    // Eligibility
    // ------------------------------------------------------------------

    /**
     * Assert that a tournament is eligible for prize distribution:
     * finished, not cancelled, all matches resolved (no live/pending/disputed
     * matches) and no actionable disputes. Standings availability is checked
     * in calculate().
     */
    public function assertEligible(Tournament $tournament): void
    {
        if ($tournament->status !== Tournament::STATUS_FINISHED) {
            throw new DomainException('Prize distribution requires a finished tournament.');
        }

        $unresolved = $tournament->matches()
            ->whereNotIn('status', [
                GameMatch::STATUS_COMPLETED,
                GameMatch::STATUS_BYE,
                GameMatch::STATUS_CANCELLED,
            ])
            ->exists();

        if ($unresolved) {
            throw new DomainException('All matches must be completed before prize distribution.');
        }

        if ($tournament->disputes()->whereIn('status', Dispute::ACTIONABLE_STATUSES)->exists()) {
            throw new DomainException('Unresolved disputes must be resolved before prize distribution.');
        }
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Resolve a tier into an integer minor-unit amount against the pool.
     */
    protected function resolveAmount(PrizeTier $tier, int $pool): int
    {
        if ($tier->type === PrizeTier::TYPE_FIXED) {
            return (int) $tier->amount_minor;
        }

        return intdiv($pool * (int) $tier->percentage_bp, 10000);
    }

    /**
     * Prize tiers can only be edited while there is no active distribution or
     * the active distribution is still a draft. Once calculated, the snapshot
     * is authoritative and the tiers are locked.
     */
    protected function assertTiersEditable(Tournament $tournament): void
    {
        $active = $this->activeDistribution($tournament);

        if ($active !== null && $active->status !== PrizeDistribution::STATUS_DRAFT) {
            throw new DomainException('Prize tiers are locked once the distribution is calculated. Cancel it to reconfigure.');
        }
    }
}
```


#### `app/Services/RestrictionService.php`

```php
<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Restriction;
use App\Models\RiskEvent;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Granular, auditable account restrictions (Phase 10).
 *
 * Restrictions are specific and revocable — there is no uncontrolled global
 * "ban" flag. Each restriction records its type, reason, source, actor,
 * start and optional expiry. Enforcement is read by the FraudRiskService
 * gates and RestrictionService::isBlocked(); no controller mutates risk or
 * restriction state directly.
 */
class RestrictionService
{
    public function __construct(
        protected FraudRiskService $risk,
        protected NotificationService $notifications,
    ) {
    }

    /**
     * Apply a restriction to a user.
     */
    public function restrict(
        User $user,
        string $type,
        string $reason,
        string $source = 'manual',
        ?User $actor = null,
        ?Carbon $expiresAt = null,
    ): Restriction {
        if (! in_array($type, Restriction::TYPES, true)) {
            throw new DomainException('Unknown restriction type.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('A restriction reason is required.');
        }

        return DB::transaction(function () use ($user, $type, $reason, $source, $actor, $expiresAt) {
            $restriction = new Restriction();
            $restriction->user_id = $user->id;
            $restriction->type = $type;
            $restriction->reason = $reason;
            $restriction->source = $source;
            $restriction->actor_id = $actor?->id;
            $restriction->starts_at = now();
            $restriction->expires_at = $expiresAt;
            $restriction->status = Restriction::STATUS_ACTIVE;
            $restriction->save();

            $severity = $type === Restriction::TYPE_ACCOUNT_SUSPENDED
                ? RiskEvent::SEVERITY_CRITICAL
                : RiskEvent::SEVERITY_HIGH;

            $this->risk->recordSignal($user, RiskEvent::TYPE_ACCOUNT_RESTRICTED, $severity, 'moderation', [
                'restriction_id' => $restriction->id,
                'type' => $type,
                'reason' => $reason,
            ]);

            // Suspensions also freeze the profile so the status is visible
            // even without reading the restrictions table.
            if ($type === Restriction::TYPE_ACCOUNT_SUSPENDED) {
                $profile = $this->risk->profileFor($user);
                $profile->status = \App\Models\RiskProfile::STATUS_SUSPENDED;
                $profile->restricted_until = $expiresAt;
                $profile->save();
            }

            // Phase 11 — notify the user (email is the channel that still
            // reaches a suspended account).
            $this->notifications->send(
                $user,
                Notification::TYPE_RESTRICTION_APPLIED,
                'Account restriction applied',
                'Your account has been restricted: ' . $reason,
                null,
                ['restriction_id' => $restriction->id, 'type' => $type],
            );

            return $restriction;
        });
    }

    /**
     * Lift a restriction (authorized override). A lifted suspension restores
     * the profile status when no other suspension remains active.
     */
    public function lift(Restriction $restriction, User $actor): Restriction
    {
        if ($restriction->status === Restriction::STATUS_LIFTED) {
            throw new DomainException('This restriction has already been lifted.');
        }

        return DB::transaction(function () use ($restriction, $actor) {
            $restriction->status = Restriction::STATUS_LIFTED;
            $restriction->lifted_by = $actor->id;
            $restriction->lifted_at = now();
            $restriction->save();

            if ($restriction->type === Restriction::TYPE_ACCOUNT_SUSPENDED) {
                $stillSuspended = Restriction::where('user_id', $restriction->user_id)
                    ->where('type', Restriction::TYPE_ACCOUNT_SUSPENDED)
                    ->where('status', Restriction::STATUS_ACTIVE)
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->exists();

                if (! $stillSuspended) {
                    $profile = $this->risk->profileFor($restriction->user);
                    $profile->status = \App\Models\RiskProfile::STATUS_ACTIVE;
                    $profile->restricted_until = null;
                    $profile->save();
                }
            }

            // Phase 11 — notify the user their restriction was lifted.
            $this->notifications->send(
                $restriction->user,
                Notification::TYPE_RESTRICTION_LIFTED,
                'Restriction lifted',
                'A restriction on your account has been lifted.',
                null,
                ['restriction_id' => $restriction->id, 'type' => $restriction->type],
            );

            return $restriction;
        });
    }

    /**
     * The user's active (unexpired, unlifted) restrictions.
     *
     * @return Collection<int, Restriction>
     */
    public function activeRestrictions(User $user): Collection
    {
        return Restriction::where('user_id', $user->id)
            ->where('status', Restriction::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get();
    }

    /**
     * Whether a user is blocked by any active restriction of the given
     * types, or by an account suspension.
     */
    public function isBlocked(User $user, array $types = []): bool
    {
        $types[] = Restriction::TYPE_ACCOUNT_SUSPENDED;
        $types = array_values(array_unique($types));

        $blocked = Restriction::where('user_id', $user->id)
            ->where('status', Restriction::STATUS_ACTIVE)
            ->whereIn('type', $types)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();

        if ($blocked) {
            return true;
        }

        // Profile-level suspension is a backstop.
        $profile = $user->riskProfile()->first();

        return $profile !== null && $profile->isSuspended();
    }
}
```


#### `app/Services/IdentityVerificationService.php`

```php
<?php

namespace App\Services;

use App\Contracts\IdentityVerificationProviderInterface;
use App\Gateways\ManualIdentityProvider;
use App\Models\IdentityVerification;
use App\Models\Notification;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * Identity-verification state machine + provider abstraction (Phase 10).
 *
 *   unverified → pending → verified / rejected / review_required
 *   verified   → expired (lazily, when expires_at passes)
 *
 * The application NEVER fabricates verification: `verified` is only reached
 * through an explicit admin manual review. The default provider (manual)
 * cannot perform automated verification; future real KYC providers plug in
 * behind IdentityVerificationProviderInterface without changing the domain.
 */
class IdentityVerificationService
{
    /**
     * Registered providers, keyed by id.
     *
     * @var array<string, IdentityVerificationProviderInterface>
     */
    protected array $providers = [];

    public function __construct(
        ManualIdentityProvider $manual,
        protected NotificationService $notifications,
    ) {
        $this->providers[$manual->id()] = $manual;
    }

    /**
     * The user's verification record (lazily created as unverified).
     */
    public function recordFor(User $user): IdentityVerification
    {
        $record = $user->identityVerification()->first();

        if ($record !== null) {
            return $record;
        }

        $record = new IdentityVerification();
        $record->user_id = $user->id;
        $record->status = IdentityVerification::STATUS_UNVERIFIED;
        $record->provider = 'manual';
        $record->save();

        return $record;
    }

    /**
     * The effective status, lazily expiring stale verifications.
     */
    public function effectiveStatus(User $user): IdentityVerification
    {
        $record = $this->recordFor($user);

        if ($record->status === IdentityVerification::STATUS_VERIFIED
            && $record->expires_at !== null
            && $record->expires_at->isPast()) {
            $record->status = IdentityVerification::STATUS_EXPIRED;
            $record->save();
        }

        return $record;
    }

    /**
     * Request verification (self-service). An expired/rejected/unverified
     * record moves to pending; a verified record stays verified.
     */
    public function request(User $user): IdentityVerification
    {
        $record = $this->effectiveStatus($user);

        if (in_array($record->status, [IdentityVerification::STATUS_PENDING, IdentityVerification::STATUS_VERIFIED], true)) {
            return $record;
        }

        $record->status = IdentityVerification::STATUS_PENDING;
        $record->provider = 'manual';
        $record->save();

        return $record;
    }

    /**
     * Admin manual verification. This is the ONLY path to `verified` today
     * and is explicitly a human review — never a fabricated provider result.
     */
    public function verifyManually(User $user, User $admin, ?string $notes = null, ?Carbon $expiresAt = null): IdentityVerification
    {
        $record = $this->effectiveStatus($user);

        if (! in_array($record->status, [
            IdentityVerification::STATUS_PENDING,
            IdentityVerification::STATUS_REJECTED,
            IdentityVerification::STATUS_REVIEW_REQUIRED,
            IdentityVerification::STATUS_EXPIRED,
            IdentityVerification::STATUS_UNVERIFIED,
        ], true)) {
            throw new DomainException('This verification cannot be approved from its current state.');
        }

        $record->status = IdentityVerification::STATUS_VERIFIED;
        $record->provider = 'manual';
        $record->reviewed_by = $admin->id;
        $record->verified_at = now();
        $record->expires_at = $expiresAt;
        $record->notes = $notes !== null && trim($notes) !== '' ? trim($notes) : $record->notes;
        $record->save();

        // Phase 11 — notify the user their identity was verified.
        $this->notifications->send(
            $user,
            Notification::TYPE_IDENTITY_VERIFIED,
            'Identity verified',
            'Your identity has been verified.',
            NotificationService::link('wallet.index'),
        );

        return $record;
    }

    /**
     * Admin rejection of a verification.
     */
    public function reject(User $user, User $admin, ?string $notes = null): IdentityVerification
    {
        $record = $this->recordFor($user);

        if ($record->status === IdentityVerification::STATUS_VERIFIED) {
            throw new DomainException('A verified identity cannot be rejected; revoke it instead.');
        }

        $record->status = IdentityVerification::STATUS_REJECTED;
        $record->reviewed_by = $admin->id;
        $record->notes = $notes !== null && trim($notes) !== '' ? trim($notes) : $record->notes;
        $record->save();

        // Phase 11 — notify the user their verification was rejected.
        $this->notifications->send(
            $user,
            Notification::TYPE_IDENTITY_REJECTED,
            'Identity verification rejected',
            'Your identity verification was rejected.',
            NotificationService::link('wallet.index'),
        );

        return $record;
    }

    /**
     * Attempt a provider-driven verification. Honest: the manual provider
     * returns pending and never reports a fabricated success.
     */
    public function attemptViaProvider(User $user, string $providerId): array
    {
        $provider = $this->providers[$providerId] ?? null;

        if ($provider === null) {
            throw new DomainException("Unknown identity provider: {$providerId}");
        }

        return $provider->request($user);
    }
}
```


#### `app/Services/AntiCheatService.php`

```php
<?php

namespace App\Services;

use App\Models\AntiCheatIncident;
use App\Models\GameMatch;
use App\Models\MatchAnomaly;
use App\Models\Notification;
use App\Models\Restriction;
use App\Models\RiskEvent;
use App\Models\Score;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Defensive anti-cheat incidents + match anomaly detection (Phase 10).
 *
 * Anomalies are deterministic, server-side observations and are NEVER
 * auto-labelled "cheating"; the moderation decision is a separate, human
 * workflow. Incidents move through flagged → under_review → cleared /
 * confirmed / restricted / dismissed. Confirmed/restricted outcomes apply
 * auditable restrictions via RestrictionService; cleared/dismissed outcomes
 * are false-positive-safe.
 */
class AntiCheatService
{
    public const CATEGORY_AIMBOT = 'aimbot';
    public const CATEGORY_WALLHACK = 'wallhack';
    public const CATEGORY_SPEED_HACK = 'speed_hack';
    public const CATEGORY_TEAMING = 'teaming';
    public const CATEGORY_SCORE_MANIPULATION = 'score_manipulation';
    public const CATEGORY_OTHER = 'other';

    public const CATEGORIES = [
        self::CATEGORY_AIMBOT,
        self::CATEGORY_WALLHACK,
        self::CATEGORY_SPEED_HACK,
        self::CATEGORY_TEAMING,
        self::CATEGORY_SCORE_MANIPULATION,
        self::CATEGORY_OTHER,
    ];

    public function __construct(
        protected FraudRiskService $risk,
        protected RestrictionService $restrictions,
        protected NotificationService $notifications,
    ) {
    }

    /**
     * Open an anti-cheat incident.
     */
    public function openIncident(
        Tournament $tournament,
        ?GameMatch $match,
        ?Team $team,
        ?User $accusedUser,
        User $reporter,
        string $source,
        string $category,
        string $severity,
        ?string $description = null,
        ?string $evidenceReference = null,
    ): AntiCheatIncident {
        if (! in_array($category, self::CATEGORIES, true)) {
            throw new DomainException('Unknown anti-cheat category.');
        }

        if (! in_array($severity, AntiCheatIncident::SEVERITIES, true)) {
            throw new DomainException('Unknown anti-cheat severity.');
        }

        $incident = new AntiCheatIncident();
        $incident->tournament_id = $tournament->id;
        $incident->match_id = $match?->id;
        $incident->team_id = $team?->id;
        $incident->accused_user_id = $accusedUser?->id;
        $incident->reporter_user_id = $reporter->id;
        $incident->source = in_array($source, [
            AntiCheatIncident::SOURCE_SYSTEM,
            AntiCheatIncident::SOURCE_PARTICIPANT,
            AntiCheatIncident::SOURCE_STAFF,
        ], true) ? $source : AntiCheatIncident::SOURCE_SYSTEM;
        $incident->category = $category;
        $incident->severity = $severity;
        $incident->description = $description !== null && trim($description) !== '' ? trim($description) : null;
        $incident->evidence_reference = $evidenceReference;
        $incident->status = AntiCheatIncident::STATUS_FLAGGED;
        $incident->save();

        return $incident;
    }

    /**
     * Move a flagged incident under review (staff).
     */
    public function review(AntiCheatIncident $incident, User $reviewer): AntiCheatIncident
    {
        if (! $incident->canTransitionTo(AntiCheatIncident::STATUS_UNDER_REVIEW)) {
            throw new DomainException('Only flagged incidents can move under review.');
        }

        $incident->status = AntiCheatIncident::STATUS_UNDER_REVIEW;
        $incident->reviewer_id = $reviewer->id;
        $incident->save();

        return $incident;
    }

    /**
     * Resolve an incident (staff). Confirmed/restricted outcomes apply an
     * auditable restriction; cleared/dismissed are false-positive-safe.
     */
    public function resolve(AntiCheatIncident $incident, User $reviewer, string $resolution, string $resolutionText): AntiCheatIncident
    {
        if (! in_array($resolution, AntiCheatIncident::RESOLUTIONS, true)) {
            throw new DomainException('Unknown incident resolution.');
        }

        if (! $incident->canTransitionTo($resolution)) {
            throw new DomainException('This incident cannot be resolved from its current state.');
        }

        $resolutionText = trim($resolutionText);

        if ($resolutionText === '') {
            throw new DomainException('A resolution reason is required.');
        }

        return DB::transaction(function () use ($incident, $reviewer, $resolution, $resolutionText) {
            $incident->status = $resolution;
            $incident->reviewer_id = $reviewer->id;
            $incident->resolution = $resolutionText;
            $incident->resolved_at = now();
            $incident->save();

            $accused = $incident->accusedUser;

            if ($accused !== null) {
                if ($resolution === AntiCheatIncident::STATUS_CONFIRMED) {
                    $this->risk->recordSignal($accused, RiskEvent::TYPE_ANTI_CHEAT_CONFIRMED, RiskEvent::SEVERITY_HIGH, 'anti_cheat', [
                        'incident_id' => $incident->id,
                        'category' => $incident->category,
                    ], $incident->tournament);

                    $this->restrictions->restrict(
                        $accused,
                        Restriction::TYPE_SCORE_SUBMISSION_BLOCKED,
                        'Confirmed anti-cheat incident #' . $incident->id . ' (' . $incident->category . ')',
                        'anti_cheat',
                        $reviewer,
                        now()->addDays(30),
                    );
                } elseif ($resolution === AntiCheatIncident::STATUS_RESTRICTED) {
                    $this->risk->recordSignal($accused, RiskEvent::TYPE_ANTI_CHEAT_CONFIRMED, RiskEvent::SEVERITY_CRITICAL, 'anti_cheat', [
                        'incident_id' => $incident->id,
                        'category' => $incident->category,
                    ], $incident->tournament);

                    $this->restrictions->restrict(
                        $accused,
                        Restriction::TYPE_TOURNAMENT_PARTICIPATION_BLOCKED,
                        'Restricted for anti-cheat incident #' . $incident->id . ' (' . $incident->category . ')',
                        'anti_cheat',
                        $reviewer,
                    );
                } else {
                    // cleared / dismissed — false-positive-safe close.
                    $this->risk->recordSignal($accused, RiskEvent::TYPE_RISK_FLAG, RiskEvent::SEVERITY_INFO, 'anti_cheat', [
                        'incident_id' => $incident->id,
                        'resolution' => $resolution,
                    ], $incident->tournament);
                }
            }

            // Phase 11 — notify the accused and the reporter of the outcome.
            $link = NotificationService::link('security.incidents.index');

            if ($accused !== null) {
                $this->notifications->send(
                    $accused,
                    Notification::TYPE_ANTI_CHEAT_RESOLVED,
                    'Anti-cheat incident resolved',
                    'An anti-cheat incident about you was resolved: ' . $resolutionText,
                    $link,
                    ['incident_id' => $incident->id, 'resolution' => $resolution],
                );
            }

            $reporter = $incident->reporter;

            if ($reporter !== null && $reporter->id !== $accused?->id) {
                $this->notifications->send(
                    $reporter,
                    Notification::TYPE_ANTI_CHEAT_RESOLVED,
                    'Anti-cheat incident resolved',
                    'An anti-cheat incident you reported was resolved: ' . $resolutionText,
                    $link,
                    ['incident_id' => $incident->id, 'resolution' => $resolution],
                );
            }

            return $incident;
        });
    }

    /**
     * Record a deterministic match anomaly. Never throws for business
     * reasons — observation only.
     */
    public function recordAnomaly(
        GameMatch $match,
        Tournament $tournament,
        string $kind,
        string $severity,
        array $metadata = [],
    ): MatchAnomaly {
        if (! in_array($kind, [
            MatchAnomaly::KIND_ABNORMAL_KILL_RATIO,
            MatchAnomaly::KIND_REPEATED_PATTERN,
            MatchAnomaly::KIND_UNEXPECTED_PARTICIPATION,
        ], true)) {
            throw new DomainException('Unknown anomaly kind.');
        }

        if (! in_array($severity, MatchAnomaly::SEVERITIES, true)) {
            throw new DomainException('Unknown anomaly severity.');
        }

        $anomaly = new MatchAnomaly();
        $anomaly->match_id = $match->id;
        $anomaly->tournament_id = $tournament->id;
        $anomaly->kind = $kind;
        $anomaly->severity = $severity;
        $anomaly->status = $severity;
        $anomaly->metadata = $metadata;
        $anomaly->save();

        // A low-severity risk signal for the participating captains (never
        // an accusation — an observation feeding the review queue).
        foreach ($match->participantTeams() as $team) {
            $captain = $team->captain;
            if ($captain !== null) {
                $this->risk->recordSignal($captain, RiskEvent::TYPE_MATCH_ANOMALY, RiskEvent::SEVERITY_LOW, 'anti_cheat', [
                    'match_id' => $match->id,
                    'anomaly_id' => $anomaly->id,
                    'kind' => $kind,
                ], $tournament);
            }
        }

        return $anomaly;
    }

    /**
     * Deterministic score-submission analysis. Returns the anomalies created.
     * Never throws for business reasons.
     *
     * @return Collection<int, MatchAnomaly>
     */
    public function analyzeScoreSubmission(GameMatch $match, Team $team, int $kills, int $placement): Collection
    {
        $created = new Collection();
        $maxKills = (int) config('antifraud.anomaly.max_kills_per_match', 60);

        if ($kills > $maxKills) {
            $created->push($this->recordAnomaly($match, $match->tournament, MatchAnomaly::KIND_ABNORMAL_KILL_RATIO, MatchAnomaly::SEVERITY_SUSPICIOUS, [
                'team_id' => $team->id,
                'kills' => $kills,
                'threshold' => $maxKills,
            ]));
        }

        $patternThreshold = (int) config('antifraud.anomaly.repeat_pattern_threshold', 3);

        $identical = Score::where('team_id', $team->id)
            ->where('kills', $kills)
            ->where('placement', $placement)
            ->where('id', '!=', Score::where('match_id', $match->id)->where('team_id', $team->id)->value('id'))
            ->count();

        if ($identical >= $patternThreshold) {
            $created->push($this->recordAnomaly($match, $match->tournament, MatchAnomaly::KIND_REPEATED_PATTERN, MatchAnomaly::SEVERITY_ANOMALY, [
                'team_id' => $team->id,
                'kills' => $kills,
                'placement' => $placement,
                'identical_count' => $identical,
            ]));
        }

        return $created;
    }
}
```


#### `app/Http/Controllers/TeamController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Exceptions\RegistrationClosedException;
use App\Models\Notification;
use App\Models\RiskEvent;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\Tournament;
use App\Services\FraudRiskService;
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


#### `routes/web.php`

```php
<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DisputeController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LeaderboardController;
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


#### `resources/views/layouts/app.blade.php`

```php
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
    <nav>
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
                    @if(($unreadNotifications ?? 0) > 0)
                        <span style="background:var(--red); color:#fff; border-radius:999px; padding:0 6px; font-size:11px; font-weight:700; margin-left:4px">{{ $unreadNotifications }}</span>
                    @endif
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
</body>
</html>
```

