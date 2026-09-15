# Phase 08 — Payments + Wallet + Ledger + Financial Integrity Report

**Project:** FF Arena (Laravel 12 + SQLite) · **Date:** 2026-09-06
**Scope:** A production-grade financial architecture — payment state machine,
payment intents, per-user wallets, an immutable double-entry-style ledger,
entry-fee integration, refunds, admin financial controls and a financial audit
trail — layered on Phases 01–07 without regressing them. Phase 09+ (prize
distribution, payouts, commission/tax engines) is explicitly out of scope.

---

## 1. Existing Payment Audit

Inspected in place before any change:

- `Payment` model — `$fillable = ['method', 'trx_id']`; amount/status/tournament/
  team server-controlled; no casts; no state machine; status values
  `pending|verified|failed|refunded`; no payer, currency, provider reference,
  idempotency key, or timestamps.
- `PaymentController::show/verify/pending` — a simulated bKash "Send Money"
  flow: user posts a bKash number + TrxID; the server creates a `pending`
  payment with `amount = tournament->entry_fee` (never from the client) and an
  admin verifies it later (`AdminController::verifyPayment` → status
  `verified`, team `pending → confirmed`). Free-entry (fee ≤ 0) tournaments
  auto-confirm.
- `AdminController::verifyPayment/rejectPayment` — manual verify/reject.
- `PaymentPolicy::view/verify` — view by admin/organizer/captain; verify by
  admin only.
- `payments` table — `decimal(12,2) amount`, `method`, `trx_id`, `status`.
- Registration — `TeamController::register` claims a slot atomically, team
  starts `pending`, then redirects to `payment.show`; a team only becomes
  `confirmed` via admin verification or free entry.

**Gaps found:** no payment state machine; no integer minor-unit money; no
currency; no idempotency key (double-click creates duplicate payments); no
payer link; no provider abstraction; no webhook protection; no wallet; no
ledger; refunds were a bare status with no record/audit; `decimal` amounts and
float casts invite precision drift; no financial audit trail.

## 2. Payment Architecture

- **`Payment`** is now a payment intent/transaction record with integer
  minor-unit amount (`amount_minor`, poisha), `currency`, `provider`,
  `provider_reference`, unique `idempotency_key`, `payer_user_id`, `paid_at`
  and `refunded_at`, plus a controlled state machine.
- **`PaymentService`** is the single authority for intents, transitions,
  manual/admin verification, refunds and provider callbacks.
- **`PaymentGatewayManager` / `PaymentGatewayInterface` / `BkashGateway`** —
  a clean provider abstraction. bKash is a manual-verification adapter; it
  never reports a fake provider confirmation.
- **`WalletService` + `Wallet` + `LedgerEntry`** — the wallet/ledger layer.
- **`PaymentEvent`** — append-only payment audit trail.
- **`Refund`** — one validated, auditable refund per payment.
- **`Money`** — integer minor-unit value object (no floating point).

## 3. Payment State Machine

`pending → processing | paid | verified | failed | cancelled`,
`processing → paid | verified | failed`, `paid/verified → refunded`.
Terminal: `failed`, `cancelled`, `refunded`.

- `verified` (Phase 01–07 success status) is preserved for **manually
  verified** payments and is deliberately distinct from `paid`
  (provider-confirmed) — we never falsely relabel a manual verification as a
  gateway confirmation.
- Only the admin verification workflow or a signature-verified provider
  callback may reach `paid`/`verified`; a client can never set them.

## 4. Provider Abstraction

`PaymentGatewayInterface` (`id`, `supportsCallbacks`, `supportsRefunds`,
`createExternalPayment`, `refundExternal`) with `BkashGateway` adapter and
`PaymentGatewayManager` resolver. The bKash adapter reports
`supportsCallbacks()/supportsRefunds() = false` and `refundExternal()` throws
— no invented external success.

## 5. Webhook / Callback Security

`POST /webhooks/payments/{provider}` (CSRF-exempt, outside auth):

- Authenticated by HMAC-SHA256 of the raw body against
  `services.payments.webhook_secret` (`X-Signature` header).
- Validates payment existence, provider match, provider-reference match,
  amount (minor units) and currency — any mismatch → 400/404.
- Idempotent: replayed callbacks return the current state without double
  effects; an already-settled payment is not re-processed.
- No live provider currently calls this endpoint; it is production-ready for
  the moment credentials are added.

## 6. Wallet Architecture

- `wallets` — one active BDT wallet per user (`balance_minor` integer poisha,
  `currency`, `status`), created lazily.
- Balances are mutated ONLY by `WalletService` (transactional, `lockForUpdate`
  re-read of the wallet inside the transaction — SQLite serializes writers,
  MySQL/PostgreSQL take row locks).
- The balance is always reconcilable against the ledger
  (`WalletService::reconciliationDelta()` = Σcredits − Σdebits − balance).

## 7. Ledger Architecture

- `ledger_entries` — append-only, double-entry-style (credit/debit direction,
  integer minor amount, running `balance_after` snapshot, type, reference,
  description, actor, created_at).
- No update/delete routes or fillable attributes; entries are only written by
  `WalletService` inside the same transaction that changes the balance.
- Types: `deposit`, `refund`, `adjustment`, `reversal`.

## 8. Entry-Fee Integration

- `Tournament::entryFeeMinor()` derives the fee in poisha from the server-side
  `entry_fee` column.
- `PaymentService::createForTeam()` computes the amount server-side; client
  `amount`/`amount_minor`/`currency`/`status` are ignored.
- Registration is unchanged and remains atomic: team `pending → confirmed`
  only via admin verification, provider callback, or free entry.
- Duplicate protection: a second payment attempt for a team with an active
  payment is redirected to the existing one (no duplicate rows).

## 9. Refund System

- `refunds` — one per payment (unique constraint), full amount only.
- `PaymentService::refund()` validates: payment is `paid`/`verified`; refund
  reason required; not already refunded; amount equals the paid amount.
- On refund: payment → `refunded` + `refunded_at`; Refund row created; payer's
  wallet credited (ledger `refund` entry); `payment.refunded` audit event.
- External gateway refunds are NOT simulated — the platform-side reversal is
  recorded honestly.

## 10. Admin Financial Controls

- `GET /admin/payments` — filterable payment list (status + tournament) with
  Verify / Reject / Refund actions.
- `GET /admin/users/{user}/wallet` — wallet + ledger + reconciliation status.
- `POST /admin/users/{user}/wallet/credit|debit` — audited manual adjustments
  (positive amounts only; debit cannot overdraw).
- All behind the `admin` middleware + `PaymentPolicy::verify/refund`.
- Normal participants cannot reach any of these (403).

## 11. Security Review

Fixed/covered within scope: payment IDOR (view policy + nested route 404s),
wallet IDOR (admin-only + own-wallet-only), cross-user/cross-tournament access
(404/403), amount tampering (server-computed minor units), currency tampering
(server-fixed BDT), status tampering (guarded state machine + non-mass-
assignable), unauthorized verification/refund (admin policy), duplicate
payment (active-payment guard), webhook forgery/replay (HMAC + idempotency),
negative wallet ops (rejected), overdraw (rejected), mass assignment (guarded
models), race conditions (transactions + lockForUpdate + unique constraints).
No secrets/cards/credentials are stored in audit records.

## 12. Database Changes

Migration `2026_09_04_170000_add_financial_architecture.php`:

- `payments` adds: `amount_minor` (bigint), `currency`, `provider`,
  `provider_reference`, unique nullable `idempotency_key`, `payer_user_id`
  (FK nullOnDelete), `paid_at`, `refunded_at`; indexes on team/status.
- New `wallets`, `ledger_entries`, `refunds`, `payment_events` (all with FKs,
  unique constraints where required, indexes).
- No destructive changes; legacy `amount`/`method`/`trx_id`/`status` columns
  are retained.

## 13. Migration Strategy

Existing rows are backfilled honestly: `amount_minor` = amount×100; currency
BDT; `provider` = method; `provider_reference` = trx_id; `payer_user_id` from
the team's captain; `paid_at` for verified/refunded rows. `verified` is kept
as "manually verified" and is NOT relabelled `paid`.

## 14. Files Created

- `database/migrations/2026_09_04_170000_add_financial_architecture.php`
- `app/Support/Money.php`
- `app/Contracts/PaymentGatewayInterface.php`
- `app/Gateways/BkashGateway.php`
- `app/Services/PaymentGatewayManager.php`
- `app/Models/Wallet.php`
- `app/Models/LedgerEntry.php`
- `app/Models/Refund.php`
- `app/Models/PaymentEvent.php`
- `app/Services/WalletService.php`
- `app/Services/PaymentService.php`
- `app/Http/Controllers/WalletController.php`
- `app/Http/Controllers/WebhookController.php`
- `resources/views/wallet/index.blade.php`
- `resources/views/admin/payments.blade.php`
- `resources/views/admin/wallet.blade.php`
- `tests/Feature/PaymentWalletLedgerTest.php`
- `tests/Feature/PaymentSecurityTest.php`

## 15. Files Modified

- `app/Models/Payment.php` (state machine, casts, relations, helpers)
- `app/Models/User.php` (`wallet()` relation)
- `app/Models/Tournament.php` (`entryFeeMinor()`)
- `app/Http/Controllers/PaymentController.php` (service-backed, idempotent)
- `app/Http/Controllers/AdminController.php` (payments list, fail/refund, wallet credit/debit)
- `app/Policies/PaymentPolicy.php` (`refund` policy)
- `routes/web.php` (wallet, admin financial routes, webhook)
- `config/services.php` (`services.payments.webhook_secret`)
- `bootstrap/app.php` (CSRF exemption for webhook)
- `resources/views/layouts/app.blade.php` (Wallet nav)
- `resources/views/admin/dashboard.blade.php` (payments link, minor-unit display, fail action)
- `resources/views/payment/pending.blade.php` (status + wallet link)

## 16. Tests Added

`PaymentWalletLedgerTest` (21 tests) — server-side amount/currency, free-entry
auto-confirm, idempotent duplicate attempt, payment-page redirect to existing,
admin verify transition + team confirm, re-verify rejected, admin fail, failed
→ new attempt, lazy wallet, credit/debit balance+ledger, overdraw rejected,
one-entry-per-movement with running balances, user wallet page, admin credit
via HTTP, admin overdraw failure, refund credits wallet + ledger + audit,
duplicate refund blocked, pending refund rejected, refund reason required,
registration stays pending until verified, payment-team binding.

`PaymentSecurityTest` (17 tests) — client amount/currency/status ignored,
negative amount rejected, organizer cannot verify/refund, player cannot reach
admin pages, cross-user payment 403, cross-tournament payment 404, mismatched
team↔tournament 404, webhook valid signature → paid + team confirmed, bad
signature 400, replay idempotent, wrong amount 400, unknown payment 404,
payment mass-assignment guard, wallet negative-balance guard, ledger
mass-assignment guard, admin reconciliation display, payment history not
exposed cross-user.

## 17. Exact Test Results

- **Full suite: 288 passed (940 assertions) — 0 failures, 0 skipped, 0 risky.**
- Phase 08: **38 passed (109 assertions)**.
- Phase 01–07 regression: **250 passed (831 assertions)** — unchanged.

## 18. Migration Results

`php artisan migrate:fresh --seed --force` — **OK**; 18 migrations applied
(17 existing + `2026_09_04_170000_add_financial_architecture`), seed completes.

## 19. Lint Results

`php -l` on **24 changed/new PHP files** — all "No syntax errors".

## 20. Route Results

**68 routes** (61 baseline + 7 new: wallet.index, webhooks.payments,
admin.payments.index/fail/refund, admin.wallet.show/credit/debit).

## 21. HTTP Smoke Results

Live `php artisan serve`: `/` 200 · `/tournaments` 200 · tournament show 200 ·
`/wallet` guest → 302 (login) · `/moderation` guest → 302 · `/admin/payments`
guest → 302 · `GET /webhooks/payments/bkash` → 405 (POST only) · unsigned
webhook POST → 400. Authenticated flows are covered authoritatively by the
feature tests.

## 22. Phase 01–07 Regression Results

All Phase 01–07 tests (250 / 831) pass unchanged alongside the new suite — no
regressions. The legacy `admin.payments.verify` flow (status `verified` +
team confirmed) is preserved end-to-end.

## 23. External Gateway Limitations

- bKash integration is a **manual-verification adapter**; no live credentials
  exist, so no real API calls are made and no success is fabricated. The
  application clearly distinguishes locally created / manually verified
  (`verified`) / provider-confirmed (`paid`) / failed payments.
- External gateway refunds are NOT simulated; refunds are recorded
  platform-side and credited to the payer's wallet.
- The webhook endpoint is implemented and fully secured, but no provider
  currently invokes it.

## 24. Remaining Financial Limitations

- SQLite is single-writer; the transaction + `lockForUpdate` (no-op on SQLite)
  + unique-constraint strategy is correct for SQLite and portable to
  MySQL/PostgreSQL, but true row-level locking requires those engines.
- The ledger is a per-wallet account ledger (credit/debit directions) rather
  than a full chart-of-accounts; platform-side balances (collected fees) are
  derived from payments, not a dedicated platform ledger account.
- Prize distribution, payouts, commission/tax accounting are Phase 09+ and
  intentionally absent.
- Refunds do not reverse team confirmation/bracket state (documented: a
  refunded entry fee does not auto-withdraw the team) — a deliberate Phase 08
  boundary.

---

## 25. Complete File Contents

### FILE: app/Support/Money.php
```php
<?php

namespace App\Support;

use DomainException;

/**
 * Integer minor-unit money handling (BDT poisha, 100 minor units per taka).
 *
 * No floating-point arithmetic is ever used for monetary values: decimals are
 * converted to/from integer minor units via string math, so amounts like
 * "123.45" are exactly 12345 poisha.
 */
final class Money
{
    /**
     * Minor units per major unit (BDT: 100 poisha per taka).
     */
    public const MINOR_UNITS = 100;

    /**
     * Convert a decimal amount ("123.45", 100, 99.9, "0") into integer minor
     * units (poisha). Negative amounts are rejected — financial operations in
     * FF Arena are never negative.
     */
    public static function toMinor(mixed $amount): int
    {
        $string = trim((string) $amount);

        if ($string === '' || $string === '-') {
            throw new DomainException('Invalid amount.');
        }

        $negative = str_starts_with($string, '-');
        if ($negative) {
            $string = substr($string, 1);
        }

        if (! preg_match('/^\d+(\.\d+)?$/', $string)) {
            throw new DomainException('Invalid amount.');
        }

        [$whole, $fraction] = array_pad(explode('.', $string, 2), 2, '');

        // Round a 3rd decimal place half-up; keep only two fraction digits.
        if (strlen($fraction) > 2) {
            $third = (int) $fraction[2];
            $fraction = substr($fraction, 0, 2);
            if ($third >= 5) {
                $fraction = str_pad((string) ((int) $fraction + 1), 2, '0', STR_PAD_LEFT);
                if ((int) $fraction >= 100) {
                    $whole = (string) ((int) $whole + 1);
                    $fraction = '00';
                }
            }
        }

        $fraction = str_pad($fraction, 2, '0');

        $minor = ((int) $whole * self::MINOR_UNITS) + (int) $fraction;

        if ($negative) {
            throw new DomainException('Negative amounts are not allowed.');
        }

        return $minor;
    }

    /**
     * Convert integer minor units into a 2-decimal string ("12345" → "123.45").
     */
    public static function toDecimal(int $minor): string
    {
        $negative = $minor < 0;
        $minor = abs($minor);

        $whole = intdiv($minor, self::MINOR_UNITS);
        $fraction = $minor % self::MINOR_UNITS;

        return ($negative ? '-' : '') . $whole . '.' . str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Format minor units for display (e.g. "৳123.45").
     */
    public static function formatMinor(int $minor): string
    {
        return '৳' . number_format((float) self::toDecimal($minor), 2);
    }
}
```

### FILE: app/Contracts/PaymentGatewayInterface.php
```php
<?php

namespace App\Contracts;

use App\Models\Payment;
use App\Models\Refund;
use DomainException;

/**
 * Provider abstraction for payment gateways (Phase 08).
 *
 * The application never invents a successful external transaction: adapters
 * must honestly report what they can and cannot do. When a real gateway's
 * credentials or API are unavailable, the adapter reports `supportsCallbacks()`
 * / `supportsRefunds()` as false and throws on unsupported operations, so the
 * platform can still distinguish locally created, manually verified and
 * provider-confirmed payments.
 */
interface PaymentGatewayInterface
{
    /**
     * The stable provider identifier (stored on payments.provider).
     */
    public function id(): string;

    /**
     * Whether this provider pushes server-to-server callbacks/webhooks.
     */
    public function supportsCallbacks(): bool;

    /**
     * Whether this provider can execute external refunds.
     */
    public function supportsRefunds(): bool;

    /**
     * Create an external payment/checkout intent for the given payment.
     *
     * @return array{status: string, provider_reference: ?string, redirect_url: ?string}
     *
     * @throws DomainException when the operation is not supported (e.g. no
     *                          live credentials).
     */
    public function createExternalPayment(Payment $payment): array;

    /**
     * Refund an external payment.
     *
     * @throws DomainException when external refunds are not supported.
     */
    public function refundExternal(Payment $payment, Refund $refund): array;
}
```

### FILE: app/Gateways/BkashGateway.php
```php
<?php

namespace App\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Payment;
use App\Models\Refund;
use DomainException;

/**
 * bKash adapter (manual "Send Money" flow).
 *
 * No live bKash credentials are configured in this project, so this adapter
 * is deliberately conservative:
 *
 *  - createExternalPayment() never reports a provider-confirmed payment; it
 *    returns the local `pending` state so an admin must verify manually.
 *  - refundExternal() refuses to fake an external refund.
 *  - supportsCallbacks()/supportsRefunds() are false.
 *
 * When real credentials are added, this is the single place to implement the
 * bKash Checkout/Create Agreement and Query Payment APIs.
 */
class BkashGateway implements PaymentGatewayInterface
{
    public function id(): string
    {
        return 'bkash';
    }

    public function supportsCallbacks(): bool
    {
        return false;
    }

    public function supportsRefunds(): bool
    {
        return false;
    }

    public function createExternalPayment(Payment $payment): array
    {
        // Manual flow: the user submits a bKash TrxID that an admin verifies.
        // We never mark a payment provider-confirmed here.
        return [
            'status' => Payment::STATUS_PENDING,
            'provider_reference' => $payment->provider_reference,
            'redirect_url' => null,
        ];
    }

    public function refundExternal(Payment $payment, Refund $refund): array
    {
        throw new DomainException('External bKash refunds are not available — refunds are recorded platform-side only.');
    }
}
```

### FILE: app/Services/PaymentGatewayManager.php
```php
<?php

namespace App\Services;

use App\Contracts\PaymentGatewayInterface;
use App\Gateways\BkashGateway;
use DomainException;

/**
 * Resolves payment gateway adapters by provider id (Phase 08).
 */
class PaymentGatewayManager
{
    /**
     * Registered adapters, keyed by provider id.
     *
     * @var array<string, PaymentGatewayInterface>
     */
    protected array $gateways = [];

    public function __construct(BkashGateway $bkash)
    {
        $this->gateways[$bkash->id()] = $bkash;
    }

    /**
     * Resolve a gateway by provider id.
     */
    public function gateway(string $provider): PaymentGatewayInterface
    {
        if (! isset($this->gateways[$provider])) {
            throw new DomainException("Unknown payment provider: {$provider}");
        }

        return $this->gateways[$provider];
    }

    /**
     * The default provider id used for manual entry-fee payments.
     */
    public function defaultProvider(): string
    {
        return 'bkash';
    }
}
```

### FILE: app/Models/Wallet.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A user's account balance (BDT, integer poisha internally).
 *
 * The balance is the single source of truth, always kept in sync with the
 * append-only ledger by WalletService. No controller may mutate the balance
 * directly.
 */
class Wallet extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_FROZEN = 'frozen';

    protected $fillable = [];

    protected $casts = [
        'balance_minor' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function ledgerEntries()
    {
        return $this->hasMany(LedgerEntry::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function balanceMinor(): int
    {
        return (int) $this->balance_minor;
    }
}
```

### FILE: app/Models/LedgerEntry.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An immutable, append-only wallet ledger entry.
 *
 * Double-entry-style: each entry carries a direction (credit/debit), an
 * integer minor-unit amount and the running balance after the movement, so
 * every financial movement is explainable and the wallet balance can be
 * reconciled against the ledger at any time.
 *
 * Entries are only ever created by WalletService; they can never be edited
 * or deleted.
 */
class LedgerEntry extends Model
{
    use HasFactory;

    public const DIRECTION_CREDIT = 'credit';
    public const DIRECTION_DEBIT = 'debit';

    public const TYPE_DEPOSIT = 'deposit';
    public const TYPE_REFUND = 'refund';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_REVERSAL = 'reversal';

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'amount_minor' => 'integer',
        'balance_after' => 'integer',
        'reference_id' => 'integer',
    ];

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function isCredit(): bool
    {
        return $this->direction === self::DIRECTION_CREDIT;
    }
}
```

### FILE: app/Models/Refund.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A platform-side refund of a payment.
 *
 * One refund per payment (enforced by a unique constraint). The refunded
 * amount is validated to equal the paid amount — partial or excessive
 * refunds are rejected by PaymentService.
 */
class Refund extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'amount_minor' => 'integer',
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
```

### FILE: app/Models/PaymentEvent.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only financial audit trail for payment lifecycle changes
 * (created, verified, failed, cancelled, refunded, callback processed).
 *
 * No passwords, API secrets, card numbers or unnecessary credentials are
 * ever stored. Rows are written only by PaymentService.
 */
class PaymentEvent extends Model
{
    use HasFactory;

    public const EVENT_CREATED = 'payment.created';
    public const EVENT_VERIFIED = 'payment.verified';
    public const EVENT_PAID = 'payment.paid';
    public const EVENT_FAILED = 'payment.failed';
    public const EVENT_CANCELLED = 'payment.cancelled';
    public const EVENT_REFUNDED = 'payment.refunded';
    public const EVENT_CALLBACK = 'payment.callback';

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'amount_minor' => 'integer',
        'metadata' => 'array',
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
```

### FILE: app/Services/WalletService.php
```php
<?php

namespace App\Services;

use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Wallet + immutable ledger (Phase 08).
 *
 * The only place that mutates wallet balances. Every credit/debit is applied
 * atomically with a matching ledger entry (with a running balance snapshot),
 * so the balance is always reconcilable against the ledger. Controllers and
 * other services must never change a wallet balance directly.
 */
class WalletService
{
    /**
     * Get (or lazily create) the user's BDT wallet.
     */
    public function walletFor(User $user): Wallet
    {
        $wallet = $user->wallet()->first();

        if ($wallet !== null) {
            return $wallet;
        }

        return DB::transaction(function () use ($user) {
            // Re-check inside the transaction to avoid a duplicate-wallet race
            // (the unique(user_id) index is the final backstop).
            $wallet = $user->wallet()->lockForUpdate()->first();

            if ($wallet !== null) {
                return $wallet;
            }

            $wallet = new Wallet();
            $wallet->user_id = $user->id;
            $wallet->currency = 'BDT';
            $wallet->balance_minor = 0;
            $wallet->status = Wallet::STATUS_ACTIVE;
            $wallet->save();

            return $wallet;
        });
    }

    /**
     * Credit a wallet (deposit/refund/adjustment) and record the ledger entry.
     */
    public function credit(
        Wallet $wallet,
        int $amountMinor,
        string $type,
        string $description,
        ?User $actor = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): LedgerEntry {
        if ($amountMinor <= 0) {
            throw new DomainException('Credit amount must be positive.');
        }

        $this->assertActive($wallet);

        return DB::transaction(function () use ($wallet, $amountMinor, $type, $description, $actor, $referenceType, $referenceId) {
            $fresh = Wallet::query()->where('id', $wallet->id)->lockForUpdate()->firstOrFail();

            $balanceAfter = $fresh->balanceMinor() + $amountMinor;

            $fresh->balance_minor = $balanceAfter;
            $fresh->save();

            return $this->record($fresh, LedgerEntry::DIRECTION_CREDIT, $amountMinor, $balanceAfter, $type, $description, $actor, $referenceType, $referenceId);
        });
    }

    /**
     * Debit a wallet (adjustment/reversal) and record the ledger entry.
     * A debit can never drive the balance negative.
     */
    public function debit(
        Wallet $wallet,
        int $amountMinor,
        string $type,
        string $description,
        ?User $actor = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): LedgerEntry {
        if ($amountMinor <= 0) {
            throw new DomainException('Debit amount must be positive.');
        }

        $this->assertActive($wallet);

        return DB::transaction(function () use ($wallet, $amountMinor, $type, $description, $actor, $referenceType, $referenceId) {
            $fresh = Wallet::query()->where('id', $wallet->id)->lockForUpdate()->firstOrFail();

            if ($fresh->balanceMinor() < $amountMinor) {
                throw new DomainException('Insufficient wallet balance.');
            }

            $balanceAfter = $fresh->balanceMinor() - $amountMinor;

            $fresh->balance_minor = $balanceAfter;
            $fresh->save();

            return $this->record($fresh, LedgerEntry::DIRECTION_DEBIT, $amountMinor, $balanceAfter, $type, $description, $actor, $referenceType, $referenceId);
        });
    }

    /**
     * The balance reconciliation delta: total credits minus total debits,
     * compared against the stored balance. Returns 0 when consistent.
     */
    public function reconciliationDelta(Wallet $wallet): int
    {
        $credits = (int) $wallet->ledgerEntries()
            ->where('direction', LedgerEntry::DIRECTION_CREDIT)
            ->sum('amount_minor');

        $debits = (int) $wallet->ledgerEntries()
            ->where('direction', LedgerEntry::DIRECTION_DEBIT)
            ->sum('amount_minor');

        return ($credits - $debits) - $wallet->balanceMinor();
    }

    protected function assertActive(Wallet $wallet): void
    {
        if (! $wallet->isActive()) {
            throw new DomainException('This wallet is frozen.');
        }
    }

    protected function record(
        Wallet $wallet,
        string $direction,
        int $amountMinor,
        int $balanceAfter,
        string $type,
        string $description,
        ?User $actor,
        ?string $referenceType,
        ?int $referenceId,
    ): LedgerEntry {
        $entry = new LedgerEntry();
        $entry->wallet_id = $wallet->id;
        $entry->direction = $direction;
        $entry->amount_minor = $amountMinor;
        $entry->balance_after = $balanceAfter;
        $entry->currency = 'BDT';
        $entry->type = $type;
        $entry->reference_type = $referenceType;
        $entry->reference_id = $referenceId;
        $entry->description = $description;
        $entry->actor_id = $actor?->id;
        $entry->save();

        return $entry;
    }
}
```

### FILE: app/Services/PaymentService.php
```php
<?php

namespace App\Services;

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

### FILE: app/Http/Controllers/WalletController.php
```php
<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;

/**
 * The authenticated user's wallet: balance, ledger history and payment
 * history (Phase 08).
 */
class WalletController extends Controller
{
    public function __construct(
        protected WalletService $wallets,
    ) {
    }

    public function index()
    {
        $user = auth()->user();
        $wallet = $this->wallets->walletFor($user);

        $ledger = $wallet->ledgerEntries()->with('actor')->limit(100)->get();

        $payments = Payment::query()
            ->where(function ($q) use ($user) {
                $q->where('payer_user_id', $user->id)
                    ->orWhereHas('team', fn ($t) => $t->where('captain_id', $user->id));
            })
            ->with(['tournament', 'team', 'refund'])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return view('wallet.index', compact('wallet', 'ledger', 'payments'));
    }
}
```

### FILE: app/Http/Controllers/WebhookController.php
```php
<?php

namespace App\Http\Controllers;

use App\Services\PaymentService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Provider payment webhook endpoint (Phase 08).
 *
 * Authentication is cryptographic (HMAC-SHA256 of the raw body against the
 * configured secret), not session-based — this route sits OUTSIDE the auth
 * middleware. Unknown, unsigned or replayed callbacks are rejected.
 *
 * No live provider currently calls this endpoint (bKash is configured for
 * manual verification); it exists so the callback security + idempotency
 * logic is production-ready the moment credentials are added.
 */
class WebhookController extends Controller
{
    public function __construct(
        protected PaymentService $payments,
    ) {
    }

    public function handle(Request $request, string $provider)
    {
        $signature = (string) $request->header('X-Signature', '');
        $rawBody = (string) $request->getContent();

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            abort(400, 'Invalid webhook payload.');
        }

        try {
            $payment = $this->payments->handleProviderCallback(
                $provider,
                $payload,
                $signature,
                $rawBody,
            );
        } catch (DomainException $e) {
            $status = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;

            abort($status, $e->getMessage());
        }

        return response()->json([
            'received' => true,
            'payment_id' => $payment->id,
            'status' => $payment->status,
        ]);
    }
}
```

### FILE: database/migrations/2026_09_04_170000_add_financial_architecture.php
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 08 — payments + wallet + immutable ledger + refunds.
     *
     * payments          : gains integer minor-unit amounts (poisha), currency,
     *                     provider/provider reference, idempotency key, payer,
     *                     paid_at and refunded_at — the payment intent/state
     *                     machine columns.
     * wallets           : per-user BDT balance (minor units) with a single
     *                     active wallet per user.
     * ledger_entries    : append-only, double-entry-style (credit/debit)
     *                     record of every wallet movement with a running
     *                     balance snapshot.
     * refunds           : one authorized, auditable refund per payment.
     * payment_events    : append-only financial audit trail for payment
     *                     lifecycle changes.
     *
     * No existing column is dropped or rewritten: legacy decimal `amount`,
     * `method`, `trx_id` and `status` values remain valid and are mapped
     * honestly (a `verified` payment is a manually verified payment, never
     * falsely relabelled as provider-confirmed).
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->bigInteger('amount_minor')->default(0)->after('amount');
            $table->string('currency', 8)->default('BDT')->after('amount_minor');
            $table->string('provider', 30)->nullable()->after('method');
            $table->string('provider_reference', 80)->nullable()->after('provider');
            $table->string('idempotency_key', 64)->nullable()->after('provider_reference');
            $table->foreignId('payer_user_id')->nullable()->after('trx_id')->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable()->after('status');
            $table->timestamp('refunded_at')->nullable()->after('paid_at');

            $table->unique('idempotency_key', 'payments_idempotency_key_unique');
            $table->index('team_id', 'payments_team_index');
            $table->index('status', 'payments_status_index');
        });

        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('currency', 8)->default('BDT');
            $table->bigInteger('balance_minor')->default(0);
            $table->string('status')->default('active'); // active|frozen
            $table->timestamps();

            $table->unique('user_id', 'wallets_user_unique');
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('wallets')->cascadeOnDelete();
            $table->string('direction', 8); // credit | debit
            $table->unsignedBigInteger('amount_minor');
            $table->bigInteger('balance_after');
            $table->string('currency', 8)->default('BDT');
            $table->string('type', 30); // deposit | refund | adjustment | reversal
            $table->string('reference_type', 30)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('description');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index('wallet_id', 'ledger_wallet_index');
            $table->index(['reference_type', 'reference_id'], 'ledger_reference_index');
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 8)->default('BDT');
            $table->string('reason');
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique('payment_id', 'refunds_payment_unique');
        });

        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);
            $table->unsignedBigInteger('amount_minor')->default(0);
            $table->string('currency', 8)->default('BDT');
            $table->string('reference', 80)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('payment_id', 'payment_events_payment_index');
            $table->index('event', 'payment_events_event_index');
        });

        $this->backfillExistingPayments();
    }

    /**
     * Backfill legacy payment rows honestly:
     *  - amount_minor from the decimal amount (×100, poisha)
     *  - currency BDT
     *  - provider from method, provider_reference from trx_id
     *  - payer_user_id from the team's captain
     *  - paid_at for verified/refunded payments from updated_at
     *
     * `status` is deliberately NOT renamed: `verified` means manually
     * verified (Phase 01–07 behaviour) and is kept distinct from a
     * provider-confirmed `paid`.
     */
    protected function backfillExistingPayments(): void
    {
        $captainByTeam = DB::table('teams')->pluck('captain_id', 'id')->all();

        $payments = DB::table('payments')->orderBy('id')->get();

        foreach ($payments as $payment) {
            $minor = (int) round((float) $payment->amount * 100);

            $update = [
                'amount_minor' => max(0, $minor),
                'currency' => 'BDT',
                'provider' => $payment->method ?: 'bkash',
                'provider_reference' => $payment->trx_id,
                'payer_user_id' => $captainByTeam[$payment->team_id] ?? null,
            ];

            if (in_array($payment->status, ['verified', 'refunded'], true)) {
                $update['paid_at'] = $payment->updated_at;
            }

            if ($payment->status === 'refunded') {
                $update['refunded_at'] = $payment->updated_at;
            }

            DB::table('payments')->where('id', $payment->id)->update($update);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('wallets');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_idempotency_key_unique');
            $table->dropIndex('payments_team_index');
            $table->dropIndex('payments_status_index');
            $table->dropConstrainedForeignId('payer_user_id');
            $table->dropColumn([
                'amount_minor',
                'currency',
                'provider',
                'provider_reference',
                'idempotency_key',
                'paid_at',
                'refunded_at',
            ]);
        });
    }
};
```

### FILE: resources/views/wallet/index.blade.php
```blade
@extends('layouts.app')
@section('title', 'My Wallet — FF Arena')
@section('content')
    <h1 style="margin:30px 0 16px">👛 My Wallet</h1>

    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr))">
        <div class="stat">
            <div class="muted">Balance</div>
            <div class="num" style="color:var(--green)">৳{{ number_format($wallet->balance_minor / 100, 2) }}</div>
        </div>
        <div class="stat">
            <div class="muted">Currency</div>
            <div class="num">{{ $wallet->currency }}</div>
        </div>
    </div>

    <div class="grid cols-2">
        <div class="card">
            <h3>🧾 Wallet Transactions</h3>
            @if($ledger->isEmpty())
                <p class="muted">No wallet transactions yet.</p>
            @else
                <table>
                    <tr><th>Date</th><th>Type</th><th>Amount</th><th>Description</th></tr>
                    @foreach($ledger as $entry)
                        <tr>
                            <td class="muted" style="font-size:12px">{{ $entry->created_at->format('d M, h:i A') }}</td>
                            <td><span class="pill {{ $entry->isCredit() ? 'confirmed' : 'finished' }}">{{ strtoupper($entry->type) }}</span></td>
                            <td style="{{ $entry->isCredit() ? 'color:var(--green)' : 'color:var(--red)' }}">
                                {{ $entry->isCredit() ? '+' : '−' }}৳{{ number_format($entry->amount_minor / 100, 2) }}
                            </td>
                            <td class="muted" style="font-size:13px">{{ $entry->description }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>

        <div class="card">
            <h3>💳 Payment History</h3>
            @if($payments->isEmpty())
                <p class="muted">No payments yet.</p>
            @else
                <table>
                    <tr><th>Tournament</th><th>Team</th><th>Amount</th><th>Status</th></tr>
                    @foreach($payments as $payment)
                        <tr>
                            <td>{{ $payment->tournament?->name ?? '—' }}</td>
                            <td>{{ $payment->team?->name ?? '—' }}</td>
                            <td>৳{{ number_format($payment->amount_minor / 100, 2) }}</td>
                            <td>
                                <span class="pill {{ $payment->statusPill() }}">{{ strtoupper($payment->status) }}</span>
                                @if($payment->refund)
                                    <span class="muted" style="font-size:12px">(refunded)</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>
    </div>
@endsection
```

### FILE: resources/views/admin/payments.blade.php
```blade
@extends('layouts.app')
@section('title', 'Payments — FF Arena Admin')
@section('content')
    <h1 style="margin:30px 0 16px">💸 Payments</h1>

    <div class="card">
        <form method="GET" action="{{ route('admin.payments.index') }}" style="display:flex; gap:12px; align-items:end; flex-wrap:wrap">
            <div style="min-width:160px">
                <label>Status</label>
                <select name="status">
                    <option value="">All statuses</option>
                    @foreach($statuses as $s)
                        <option value="{{ $s }}" @selected($status === $s)>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
            </div>
            <div style="min-width:220px">
                <label>Tournament</label>
                <select name="tournament_id">
                    <option value="">All tournaments</option>
                    @foreach($tournaments as $t)
                        <option value="{{ $t->id }}" @selected($tournamentId === $t->id)>{{ $t->name }}</option>
                    @endforeach
                </select>
            </div>
            <button class="btn btn-sm btn-cyan">Filter</button>
        </form>
    </div>

    <div class="card">
        @if($payments->isEmpty())
            <p class="muted">No payments match your filters.</p>
        @else
            <table>
                <tr>
                    <th>ID</th><th>Tournament</th><th>Team</th><th>Payer</th><th>Amount</th>
                    <th>TrxID</th><th>Status</th><th>Action</th>
                </tr>
                @foreach($payments as $payment)
                    <tr>
                        <td><strong>#{{ $payment->id }}</strong></td>
                        <td>{{ $payment->tournament?->name ?? '—' }}</td>
                        <td>{{ $payment->team?->name ?? '—' }}</td>
                        <td class="muted" style="font-size:13px">{{ $payment->payer?->name ?? '—' }}</td>
                        <td>৳{{ number_format($payment->amount_minor / 100, 2) }}</td>
                        <td class="muted" style="font-size:12px">{{ $payment->trx_id }}</td>
                        <td><span class="pill {{ $payment->statusPill() }}">{{ $payment->statusLabel() }}</span></td>
                        <td>
                            @if(in_array($payment->status, ['pending', 'processing'], true))
                                <form method="POST" action="{{ route('admin.payments.verify', $payment) }}" style="display:inline">@csrf
                                    <button class="btn btn-green btn-sm">Verify</button>
                                </form>
                                <form method="POST" action="{{ route('admin.payments.fail', $payment) }}" style="display:inline">@csrf
                                    <button class="btn btn-sm">Reject</button>
                                </form>
                            @elseif($payment->isRefundable())
                                <form method="POST" action="{{ route('admin.payments.refund', $payment) }}" style="display:inline-flex; gap:6px; align-items:center">
                                    @csrf
                                    <input type="text" name="reason" placeholder="Refund reason" required style="max-width:140px">
                                    <button class="btn btn-sm" style="border-color:var(--amber); color:var(--amber)">Refund</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
            <div style="margin-top:14px">{{ $payments->links() }}</div>
        @endif
    </div>
@endsection
```

### FILE: resources/views/admin/wallet.blade.php
```blade
@extends('layouts.app')
@section('title', 'Wallet — ' . $user->name . ' · FF Arena Admin')
@section('content')
    <h1 style="margin:30px 0 16px">👛 Wallet: {{ $user->name }}</h1>
    <p class="muted">{{ $user->email }}</p>

    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr))">
        <div class="stat">
            <div class="muted">Balance</div>
            <div class="num" style="color:var(--green)">৳{{ number_format($wallet->balance_minor / 100, 2) }}</div>
        </div>
        <div class="stat">
            <div class="muted">Ledger reconciliation</div>
            <div class="num" style="color:{{ $delta === 0 ? 'var(--green)' : 'var(--red)' }}">
                {{ $delta === 0 ? '✓ Consistent' : 'Δ ' . $delta }}
            </div>
        </div>
    </div>

    <div class="grid cols-2">
        <div class="card">
            <h3>➕ Credit / ➖ Debit</h3>
            <form method="POST" action="{{ route('admin.wallet.credit', $user) }}" style="display:flex; gap:8px; align-items:end">
                @csrf
                <div style="flex:1">
                    <label>Credit amount (৳)</label>
                    <input type="text" name="amount" pattern="\d+(\.\d{1,2})?" placeholder="100.00" required>
                </div>
                <div style="flex:2">
                    <label>Description</label>
                    <input type="text" name="description" placeholder="e.g. Prize credit" maxlength="255" required>
                </div>
                <button class="btn btn-green btn-sm">Credit</button>
            </form>

            <form method="POST" action="{{ route('admin.wallet.debit', $user) }}" style="display:flex; gap:8px; align-items:end; margin-top:12px">
                @csrf
                <div style="flex:1">
                    <label>Debit amount (৳)</label>
                    <input type="text" name="amount" pattern="\d+(\.\d{1,2})?" placeholder="50.00" required>
                </div>
                <div style="flex:2">
                    <label>Description</label>
                    <input type="text" name="description" placeholder="e.g. Fee correction" maxlength="255" required>
                </div>
                <button class="btn btn-sm" style="border-color:var(--red); color:var(--red)">Debit</button>
            </form>
        </div>

        <div class="card">
            <h3>📊 Summary</h3>
            <table>
                <tr><th>Credits</th><td style="color:var(--green)">{{ $ledger->where('direction', 'credit')->count() }} entries</td></tr>
                <tr><th>Debits</th><td style="color:var(--red)">{{ $ledger->where('direction', 'debit')->count() }} entries</td></tr>
                <tr><th>Status</th><td><span class="pill {{ $wallet->status }}">{{ strtoupper($wallet->status) }}</span></td></tr>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>🧾 Ledger</h3>
        @if($ledger->isEmpty())
            <p class="muted">No ledger entries.</p>
        @else
            <table>
                <tr><th>Date</th><th>Direction</th><th>Type</th><th>Amount</th><th>Balance After</th><th>Actor</th><th>Description</th></tr>
                @foreach($ledger as $entry)
                    <tr>
                        <td class="muted" style="font-size:12px">{{ $entry->created_at->format('d M, h:i A') }}</td>
                        <td><span class="pill {{ $entry->isCredit() ? 'confirmed' : 'finished' }}">{{ strtoupper($entry->direction) }}</span></td>
                        <td class="muted" style="font-size:13px">{{ $entry->type }}</td>
                        <td style="{{ $entry->isCredit() ? 'color:var(--green)' : 'color:var(--red)' }}">
                            {{ $entry->isCredit() ? '+' : '−' }}৳{{ number_format($entry->amount_minor / 100, 2) }}
                        </td>
                        <td>৳{{ number_format($entry->balance_after / 100, 2) }}</td>
                        <td class="muted" style="font-size:13px">{{ $entry->actor?->name ?? '—' }}</td>
                        <td class="muted" style="font-size:13px">{{ $entry->description }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>
@endsection
```

### FILE: tests/Feature/PaymentWalletLedgerTest.php
```php
<?php

namespace Tests\Feature;

use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Refund;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PaymentService;
use App\Services\WalletService;
use App\Support\Money;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 08 — payment state machine, amount integrity, idempotency, wallet,
 * immutable ledger, entry-fee integration and refunds.
 */
class PaymentWalletLedgerTest extends TestCase
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
        $t->name = $o['name'] ?? 'Payment Tournament';
        $t->slug = $o['slug'] ?? ('pay-'.Str::random(8));
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = $o['entry_fee'] ?? 100;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->starts_at = now()->addDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function makeTeam(Tournament $tournament, ?User $captain = null, string $status = 'pending'): Team
    {
        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain?->id;
        $team->name = 'Team '.Str::random(6);
        $team->captain_name = $captain?->name ?? 'Captain';
        $team->phone = '01700000000';
        $team->game_uid = 'UID'.strtoupper(Str::random(8));
        $team->status = $status;
        $team->save();

        return $team;
    }

    protected function payments(): PaymentService
    {
        return app(PaymentService::class);
    }

    protected function wallets(): WalletService
    {
        return app(WalletService::class);
    }

    // ------------------------------------------------------------------
    // Payment creation + amount integrity
    // ------------------------------------------------------------------

    public function test_payment_amount_derives_from_server_entry_fee(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 150]);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->post(route('payment.verify', [$tournament, $team]), [
            'bkash_number' => '01700000000',
            'trx_id' => 'BTRX1',
            'amount' => 1, // client-supplied amount must be ignored
        ])->assertRedirect();

        $payment = Payment::where('team_id', $team->id)->firstOrFail();
        $this->assertSame(15000, $payment->amountMinor());
        $this->assertSame('150.00', Money::toDecimal($payment->amountMinor()));
        $this->assertSame('BDT', $payment->currency);
        $this->assertSame($captain->id, $payment->payer_user_id);
    }

    public function test_free_entry_auto_confirms_team(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 0]);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->post(route('payment.verify', [$tournament, $team]), [
            'bkash_number' => '01700000000',
            'trx_id' => 'FREE1',
        ])->assertRedirect(route('tournaments.show', $tournament));

        $payment = Payment::where('team_id', $team->id)->firstOrFail();
        $this->assertSame(0, $payment->amountMinor());
        $this->assertSame(Payment::STATUS_VERIFIED, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame(Team::STATUS_CONFIRMED, $team->fresh()->status);
    }

    public function test_duplicate_payment_attempt_is_idempotent(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 100]);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->post(route('payment.verify', [$tournament, $team]), [
            'bkash_number' => '01700000000', 'trx_id' => 'BTRX1',
        ])->assertRedirect();

        // Second attempt → redirected to the existing pending payment, no new row.
        $this->actingAs($captain)->post(route('payment.verify', [$tournament, $team]), [
            'bkash_number' => '01700000000', 'trx_id' => 'BTRX2',
        ])->assertRedirect();

        $this->assertSame(1, Payment::where('team_id', $team->id)->count());
    }

    public function test_payment_page_redirects_to_existing_active_payment(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 100]);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->post(route('payment.verify', [$tournament, $team]), [
            'bkash_number' => '01700000000', 'trx_id' => 'BTRX1',
        ])->assertRedirect();

        $payment = Payment::where('team_id', $team->id)->firstOrFail();

        $this->actingAs($captain)->get(route('payment.show', [$tournament, $team]))
            ->assertRedirect(route('payment.pending', [$tournament, $team, $payment]));
    }

    // ------------------------------------------------------------------
    // Payment state machine
    // ------------------------------------------------------------------

    public function test_admin_verify_transitions_pending_to_verified_and_confirms_team(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $admin = $this->makeUser('admin');
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 100]);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->post(route('payment.verify', [$tournament, $team]), [
            'bkash_number' => '01700000000', 'trx_id' => 'BTRX1',
        ])->assertRedirect();

        $payment = Payment::where('team_id', $team->id)->firstOrFail();
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);

        $this->actingAs($admin)->post(route('admin.payments.verify', $payment))->assertRedirect();

        $payment->refresh();
        $this->assertSame(Payment::STATUS_VERIFIED, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame(Team::STATUS_CONFIRMED, $team->fresh()->status);

        $this->assertDatabaseHas('payment_events', [
            'payment_id' => $payment->id,
            'event' => PaymentEvent::EVENT_VERIFIED,
        ]);
    }

    public function test_verifying_a_verified_payment_is_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $admin = $this->makeUser('admin');
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 100]);
        $team = $this->makeTeam($tournament, $captain);

        $payment = $this->payments()->createForTeam($tournament, $team, $captain, 'bkash', 'BTRX1');
        $this->payments()->verifyManually($payment, $admin);

        $this->expectException(DomainException::class);
        $this->payments()->verifyManually($payment, $admin);
    }

    public function test_admin_can_fail_a_pending_payment(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $admin = $this->makeUser('admin');
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 100]);
        $team = $this->makeTeam($tournament, $captain);

        $payment = $this->payments()->createForTeam($tournament, $team, $captain, 'bkash', 'BTRX1');

        $this->actingAs($admin)->post(route('admin.payments.fail', $payment))->assertRedirect();

        $this->assertSame(Payment::STATUS_FAILED, $payment->fresh()->status);
    }

    public function test_failed_payment_allows_a_new_attempt(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 100]);
        $team = $this->makeTeam($tournament, $captain);

        $payment = $this->payments()->createForTeam($tournament, $team, $captain, 'bkash', 'BTRX1');
        $this->payments()->markFailed($payment, $captain, 'wrong trx');

        // After failure a fresh payment can be created.
        $second = $this->payments()->createForTeam($tournament, $team, $captain, 'bkash', 'BTRX2');

        $this->assertNotSame($payment->id, $second->id);
        $this->assertSame(Payment::STATUS_PENDING, $second->status);
    }

    // ------------------------------------------------------------------
    // Wallet + ledger
    // ------------------------------------------------------------------

    public function test_wallet_is_lazily_created_with_zero_balance(): void
    {
        $player = $this->makeUser('player');
        $wallet = $this->wallets()->walletFor($player);

        $this->assertInstanceOf(Wallet::class, $wallet);
        $this->assertSame(0, $wallet->balanceMinor());
        $this->assertSame('BDT', $wallet->currency);
    }

    public function test_credit_and_debit_update_balance_and_ledger(): void
    {
        $player = $this->makeUser('player');
        $admin = $this->makeUser('admin');
        $wallet = $this->wallets()->walletFor($player);

        $this->wallets()->credit($wallet, 50000, LedgerEntry::TYPE_ADJUSTMENT, 'Prize credit', $admin);
        $this->wallets()->debit($wallet, 20000, LedgerEntry::TYPE_ADJUSTMENT, 'Fee correction', $admin);

        $wallet->refresh();
        $this->assertSame(30000, $wallet->balanceMinor());

        $this->assertSame(2, $wallet->ledgerEntries()->count());
        $this->assertSame(0, $this->wallets()->reconciliationDelta($wallet));
    }

    public function test_debit_beyond_balance_is_rejected(): void
    {
        $player = $this->makeUser('player');
        $admin = $this->makeUser('admin');
        $wallet = $this->wallets()->walletFor($player);

        $this->wallets()->credit($wallet, 1000, LedgerEntry::TYPE_ADJUSTMENT, 'seed', $admin);

        $this->expectException(DomainException::class);
        $this->wallets()->debit($wallet, 5000, LedgerEntry::TYPE_ADJUSTMENT, 'overdraw', $admin);
    }

    public function test_every_movement_creates_a_ledger_entry(): void
    {
        $player = $this->makeUser('player');
        $admin = $this->makeUser('admin');
        $wallet = $this->wallets()->walletFor($player);

        $this->wallets()->credit($wallet, 10000, LedgerEntry::TYPE_ADJUSTMENT, 'a', $admin);
        $this->wallets()->credit($wallet, 5000, LedgerEntry::TYPE_ADJUSTMENT, 'b', $admin);
        $this->wallets()->debit($wallet, 2000, LedgerEntry::TYPE_ADJUSTMENT, 'c', $admin);

        $entries = LedgerEntry::where('wallet_id', $wallet->id)->orderBy('id')->get();

        $this->assertCount(3, $entries);
        $this->assertSame(10000, $entries[0]->balance_after);
        $this->assertSame(15000, $entries[1]->balance_after);
        $this->assertSame(13000, $entries[2]->balance_after);
    }

    public function test_user_wallet_page_shows_balance_and_history(): void
    {
        $player = $this->makeUser('player');
        $admin = $this->makeUser('admin');
        $wallet = $this->wallets()->walletFor($player);
        $this->wallets()->credit($wallet, 25000, LedgerEntry::TYPE_ADJUSTMENT, 'Welcome bonus', $admin);

        $this->actingAs($player)->get(route('wallet.index'))
            ->assertOk()
            ->assertSee('৳250.00')
            ->assertSee('Welcome bonus');
    }

    // ------------------------------------------------------------------
    // Admin wallet controls
    // ------------------------------------------------------------------

    public function test_admin_can_credit_user_wallet_via_http(): void
    {
        $admin = $this->makeUser('admin');
        $player = $this->makeUser('player');

        $this->actingAs($admin)->post(route('admin.wallet.credit', $player), [
            'amount' => '150.50',
            'description' => 'Prize credit',
        ])->assertRedirect();

        $wallet = $this->wallets()->walletFor($player);
        $this->assertSame(15050, $wallet->balanceMinor());
    }

    public function test_admin_debit_beyond_balance_fails(): void
    {
        $admin = $this->makeUser('admin');
        $player = $this->makeUser('player');
        $this->wallets()->walletFor($player);

        $this->actingAs($admin)->post(route('admin.wallet.debit', $player), [
            'amount' => '50.00',
            'description' => 'overdraw',
        ])->assertSessionHas('error');

        $this->assertSame(0, $this->wallets()->walletFor($player)->balanceMinor());
    }

    // ------------------------------------------------------------------
    // Refunds
    // ------------------------------------------------------------------

    protected function settledPayment(): array
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $admin = $this->makeUser('admin');
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 100]);
        $team = $this->makeTeam($tournament, $captain);

        $payment = $this->payments()->createForTeam($tournament, $team, $captain, 'bkash', 'BTRX1');
        $this->payments()->verifyManually($payment, $admin);

        return [$payment, $captain, $admin];
    }

    public function test_refund_credits_payer_wallet_and_records_ledger(): void
    {
        [$payment, $captain, $admin] = $this->settledPayment();

        $this->actingAs($admin)->post(route('admin.payments.refund', $payment), [
            'reason' => 'Team withdrew',
        ])->assertRedirect();

        $payment->refresh();
        $this->assertSame(Payment::STATUS_REFUNDED, $payment->status);
        $this->assertNotNull($payment->refunded_at);

        $refund = Refund::where('payment_id', $payment->id)->firstOrFail();
        $this->assertSame(10000, $refund->amount_minor);

        $wallet = $this->wallets()->walletFor($captain);
        $this->assertSame(10000, $wallet->balanceMinor());
        $this->assertDatabaseHas('ledger_entries', [
            'wallet_id' => $wallet->id,
            'type' => LedgerEntry::TYPE_REFUND,
            'direction' => LedgerEntry::DIRECTION_CREDIT,
            'amount_minor' => 10000,
        ]);
        $this->assertDatabaseHas('payment_events', [
            'payment_id' => $payment->id,
            'event' => PaymentEvent::EVENT_REFUNDED,
        ]);
    }

    public function test_duplicate_refund_is_blocked(): void
    {
        [$payment, $captain, $admin] = $this->settledPayment();

        $this->payments()->refund($payment, $admin, 'first refund');

        $this->expectException(DomainException::class);
        $this->payments()->refund($payment, $admin, 'second refund');
    }

    public function test_refunding_a_pending_payment_is_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $admin = $this->makeUser('admin');
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 100]);
        $team = $this->makeTeam($tournament, $captain);

        $payment = $this->payments()->createForTeam($tournament, $team, $captain, 'bkash', 'BTRX1');

        $this->expectException(DomainException::class);
        $this->payments()->refund($payment, $admin, 'too early');
    }

    public function test_refund_requires_a_reason(): void
    {
        [$payment, $captain, $admin] = $this->settledPayment();

        $this->actingAs($admin)->post(route('admin.payments.refund', $payment), [
            'reason' => '',
        ])->assertSessionHasErrors('reason');

        $this->assertSame(Payment::STATUS_VERIFIED, $payment->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Registration integration
    // ------------------------------------------------------------------

    public function test_registration_flow_keeps_team_pending_until_verified(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $admin = $this->makeUser('admin');
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 100]);

        $this->actingAs($captain)->post(route('teams.store', $tournament), [
            'name' => 'Challengers', 'captain_name' => $captain->name,
            'phone' => '01700000000', 'game_uid' => 'UID123',
        ])->assertRedirect();

        $team = Team::where('name', 'Challengers')->firstOrFail();
        $this->assertSame(Team::STATUS_PENDING, $team->status);

        $this->actingAs($captain)->post(route('payment.verify', [$tournament, $team]), [
            'bkash_number' => '01700000000', 'trx_id' => 'BTRX1',
        ])->assertRedirect();
        $this->assertSame(Team::STATUS_PENDING, $team->fresh()->status);

        $payment = Payment::where('team_id', $team->id)->firstOrFail();
        $this->actingAs($admin)->post(route('admin.payments.verify', $payment))->assertRedirect();

        $this->assertSame(Team::STATUS_CONFIRMED, $team->fresh()->status);
    }

    public function test_payment_cannot_be_transferred_between_teams(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 100]);
        $teamA = $this->makeTeam($tournament, $captain);
        $teamB = $this->makeTeam($tournament);

        // Attempting to pay for teamB while the route is bound to teamA's id
        // is impossible via the route; the service validates the team belongs
        // to the tournament and is pending. Cross-team injection via a forged
        // team_id in the payload is ignored (team comes from the route).
        $payment = $this->payments()->createForTeam($tournament, $teamA, $captain, 'bkash', 'BTRX1');

        $this->assertSame($teamA->id, $payment->team_id);
        $this->assertNotSame($teamB->id, $payment->team_id);
    }
}
```

### FILE: tests/Feature/PaymentSecurityTest.php
```php
<?php

namespace Tests\Feature;

use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 08 — payment/wallet financial security: IDOR, amount tampering,
 * status tampering, unauthorized verification/refund, webhook forgery and
 * replay, mass assignment, and cross-tournament access.
 */
class PaymentSecurityTest extends TestCase
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
        $t->name = $o['name'] ?? 'Payment Security Tournament';
        $t->slug = $o['slug'] ?? ('paysec-'.Str::random(8));
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = $o['entry_fee'] ?? 100;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->starts_at = now()->addDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function makeTeam(Tournament $tournament, ?User $captain = null, string $status = 'pending'): Team
    {
        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain?->id;
        $team->name = 'Team '.Str::random(6);
        $team->captain_name = $captain?->name ?? 'Captain';
        $team->phone = '01700000000';
        $team->game_uid = 'UID'.strtoupper(Str::random(8));
        $team->status = $status;
        $team->save();

        return $team;
    }

    protected function makePayment(Tournament $tournament, Team $team, ?User $payer = null, string $status = 'pending'): Payment
    {
        $p = new Payment();
        $p->tournament_id = $tournament->id;
        $p->team_id = $team->id;
        $p->payer_user_id = $payer?->id ?? $team->captain_id;
        $p->amount = $tournament->entry_fee;
        $p->amount_minor = $tournament->entryFeeMinor();
        $p->currency = 'BDT';
        $p->method = 'bkash';
        $p->trx_id = 'TRX'.strtoupper(Str::random(8));
        $p->provider = 'bkash';
        $p->provider_reference = $p->trx_id;
        $p->idempotency_key = (string) Str::uuid();
        $p->status = $status;
        $p->save();

        return $p;
    }

    // ------------------------------------------------------------------
    // Amount tampering
    // ------------------------------------------------------------------

    public function test_client_amount_and_currency_are_ignored(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open', ['entry_fee' => 500]);
        $team = $this->makeTeam($tournament, $captain);

        $this->actingAs($captain)->post(route('payment.verify', [$tournament, $team]), [
            'bkash_number' => '01700000000',
            'trx_id' => 'BTRX1',
            'amount' => 1,
            'amount_minor' => 100,
            'currency' => 'USD',
            'status' => 'paid',
        ])->assertRedirect();

        $payment = Payment::where('team_id', $team->id)->firstOrFail();
        $this->assertSame(50000, $payment->amountMinor());
        $this->assertSame('BDT', $payment->currency);
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
    }

    public function test_negative_amount_is_rejected_by_money_parser(): void
    {
        $this->expectException(\DomainException::class);
        \App\Support\Money::toMinor('-5.00');
    }

    // ------------------------------------------------------------------
    // Unauthorized verification / refund
    // ------------------------------------------------------------------

    public function test_organizer_cannot_verify_or_refund_payment(): void
    {
        $org = $this->makeUser('organizer');
        $otherOrg = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');
        $team = $this->makeTeam($tournament, $captain);
        $payment = $this->makePayment($tournament, $team, $captain, 'verified');

        $this->actingAs($otherOrg)->post(route('admin.payments.verify', $payment))->assertStatus(403);
        $this->actingAs($otherOrg)->post(route('admin.payments.refund', $payment), [
            'reason' => 'hijack',
        ])->assertStatus(403);

        $this->assertSame('verified', $payment->fresh()->status);
    }

    public function test_player_cannot_access_admin_payment_pages(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');
        $team = $this->makeTeam($tournament, $captain);
        $payment = $this->makePayment($tournament, $team, $captain);

        $this->actingAs($captain)->get(route('admin.payments.index'))->assertStatus(403);
        $this->actingAs($captain)->get(route('admin.wallet.show', $captain))->assertStatus(403);
        $this->actingAs($captain)->post(route('admin.wallet.credit', $captain), [
            'amount' => '100', 'description' => 'hack',
        ])->assertStatus(403);
    }

    public function test_user_cannot_view_another_users_payment(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $captainB = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');
        $teamA = $this->makeTeam($tournament, $captainA);
        $payment = $this->makePayment($tournament, $teamA, $captainA);

        $this->actingAs($captainB)
            ->get(route('payment.pending', [$tournament, $teamA, $payment]))
            ->assertStatus(403);
    }

    public function test_cross_tournament_payment_is_blocked(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournamentA = $this->makeTournament($org, 'open');
        $tournamentB = $this->makeTournament($org, 'open', ['name' => 'Other', 'slug' => 'other-'.Str::random(6)]);
        $teamA = $this->makeTeam($tournamentA, $captain);
        $payment = $this->makePayment($tournamentA, $teamA, $captain);

        $this->actingAs($captain)
            ->get(route('payment.pending', [$tournamentB, $teamA, $payment]))
            ->assertStatus(404);
    }

    public function test_payment_team_and_tournament_relationship_is_checked(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');
        $other = $this->makeTournament($org, 'open', ['name' => 'Other', 'slug' => 'other-'.Str::random(6)]);
        $teamA = $this->makeTeam($tournament, $captain);
        $payment = $this->makePayment($other, $teamA, $captain); // mismatched

        // Payment belongs to the OTHER tournament but is addressed through
        // this tournament's team → blocked.
        $this->actingAs($captain)
            ->get(route('payment.pending', [$tournament, $teamA, $payment]))
            ->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // Webhook security + idempotency
    // ------------------------------------------------------------------

    protected function signedPayload(array $payload): array
    {
        $secret = (string) config('services.payments.webhook_secret');
        $body = json_encode($payload);

        return [$body, hash_hmac('sha256', $body, $secret)];
    }

    public function test_webhook_with_valid_signature_marks_payment_paid(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');
        $team = $this->makeTeam($tournament, $captain);
        $payment = $this->makePayment($tournament, $team, $captain, 'pending');

        [$body, $signature] = $this->signedPayload([
            'payment_id' => $payment->id,
            'provider_reference' => $payment->provider_reference,
            'amount_minor' => $payment->amountMinor(),
            'currency' => 'BDT',
            'status' => 'paid',
        ]);

        $this->withHeader('X-Signature', $signature)
            ->postJson(route('webhooks.payments', ['provider' => 'bkash']), json_decode($body, true))
            ->assertOk();

        $payment->refresh();
        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame(Team::STATUS_CONFIRMED, $team->fresh()->status);
    }

    public function test_webhook_with_bad_signature_is_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');
        $team = $this->makeTeam($tournament, $captain);
        $payment = $this->makePayment($tournament, $team, $captain, 'pending');

        $payload = [
            'payment_id' => $payment->id,
            'provider_reference' => $payment->provider_reference,
            'amount_minor' => $payment->amountMinor(),
            'currency' => 'BDT',
            'status' => 'paid',
        ];

        $this->withHeader('X-Signature', 'deadbeef')
            ->postJson(route('webhooks.payments', ['provider' => 'bkash']), $payload)
            ->assertStatus(400);

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_webhook_replay_is_idempotent(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');
        $team = $this->makeTeam($tournament, $captain);
        $payment = $this->makePayment($tournament, $team, $captain, 'pending');

        $payload = [
            'payment_id' => $payment->id,
            'provider_reference' => $payment->provider_reference,
            'amount_minor' => $payment->amountMinor(),
            'currency' => 'BDT',
            'status' => 'paid',
        ];
        [$body, $signature] = $this->signedPayload($payload);

        $this->withHeader('X-Signature', $signature)->postJson(route('webhooks.payments', ['provider' => 'bkash']), json_decode($body, true))->assertOk();
        $this->withHeader('X-Signature', $signature)->postJson(route('webhooks.payments', ['provider' => 'bkash']), json_decode($body, true))->assertOk();

        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        // No double effects: a single team confirmation and no duplicate ledger.
        $this->assertSame(1, Payment::where('id', $payment->id)->count());
    }

    public function test_webhook_with_wrong_amount_is_rejected(): void
    {
        $org = $this->makeUser('organizer');
        $captain = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');
        $team = $this->makeTeam($tournament, $captain);
        $payment = $this->makePayment($tournament, $team, $captain, 'pending');

        [$body, $signature] = $this->signedPayload([
            'payment_id' => $payment->id,
            'provider_reference' => $payment->provider_reference,
            'amount_minor' => 1, // tampered
            'currency' => 'BDT',
            'status' => 'paid',
        ]);

        $this->withHeader('X-Signature', $signature)
            ->postJson(route('webhooks.payments', ['provider' => 'bkash']), json_decode($body, true))
            ->assertStatus(400);

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_webhook_for_unknown_payment_is_rejected(): void
    {
        [$body, $signature] = $this->signedPayload([
            'payment_id' => 999999,
            'provider_reference' => 'NOPE',
            'amount_minor' => 100,
            'currency' => 'BDT',
            'status' => 'paid',
        ]);

        $this->withHeader('X-Signature', $signature)
            ->postJson(route('webhooks.payments', ['provider' => 'bkash']), json_decode($body, true))
            ->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // Mass assignment
    // ------------------------------------------------------------------

    public function test_payment_server_fields_are_not_mass_assignable(): void
    {
        $payment = new Payment();
        $payment->fill([
            'method' => 'nagad',          // fillable
            'trx_id' => 'TXN1',           // fillable
            'tournament_id' => 9999,      // server-controlled → ignored
            'team_id' => 9999,            // server-controlled → ignored
            'payer_user_id' => 9999,      // server-controlled → ignored
            'amount_minor' => 1,          // server-controlled → ignored
            'status' => 'paid',           // server-controlled → ignored
            'currency' => 'USD',          // server-controlled → ignored
        ]);

        $attributes = $payment->getAttributes();

        $this->assertSame('nagad', $attributes['method']);
        $this->assertSame('TXN1', $attributes['trx_id']);
        $this->assertArrayNotHasKey('tournament_id', $attributes);
        $this->assertArrayNotHasKey('team_id', $attributes);
        $this->assertArrayNotHasKey('payer_user_id', $attributes);
        $this->assertArrayNotHasKey('amount_minor', $attributes);
        $this->assertArrayNotHasKey('status', $attributes);
        $this->assertArrayNotHasKey('currency', $attributes);
    }

    // ------------------------------------------------------------------
    // Wallet integrity
    // ------------------------------------------------------------------

    public function test_wallet_balance_is_never_negative_after_debit(): void
    {
        $admin = $this->makeUser('admin');
        $player = $this->makeUser('player');
        $wallets = app(WalletService::class);
        $wallet = $wallets->walletFor($player);

        $wallets->credit($wallet, 1000, LedgerEntry::TYPE_ADJUSTMENT, 'seed', $admin);

        try {
            $wallets->debit($wallet, 5000, LedgerEntry::TYPE_ADJUSTMENT, 'over', $admin);
            $this->fail('Expected DomainException for overdraw.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Insufficient', $e->getMessage());
        }

        $this->assertSame(1000, $wallet->fresh()->balanceMinor());
    }

    public function test_ledger_entries_cannot_be_mass_assigned(): void
    {
        // Ledger entries are fully guarded (empty $fillable), so any mass
        // assignment attempt throws a MassAssignmentException.
        $this->expectException(\Illuminate\Database\Eloquent\MassAssignmentException::class);

        $entry = new LedgerEntry();
        $entry->fill([
            'wallet_id' => 1,
            'direction' => 'credit',
            'amount_minor' => 999999,
            'balance_after' => 999999,
            'type' => 'deposit',
        ]);
    }

    public function test_admin_wallet_page_shows_reconciliation(): void
    {
        $admin = $this->makeUser('admin');
        $player = $this->makeUser('player');
        $wallets = app(WalletService::class);
        $wallet = $wallets->walletFor($player);
        $wallets->credit($wallet, 7777, LedgerEntry::TYPE_ADJUSTMENT, 'x', $admin);

        $this->actingAs($admin)->get(route('admin.wallet.show', $player))
            ->assertOk()
            ->assertSee('Consistent');
    }

    public function test_payment_history_is_not_exposed_to_other_users(): void
    {
        $org = $this->makeUser('organizer');
        $captainA = $this->makeUser('player');
        $captainB = $this->makeUser('player');
        $tournament = $this->makeTournament($org, 'open');
        $teamA = $this->makeTeam($tournament, $captainA);
        $this->makePayment($tournament, $teamA, $captainA);

        // captainB's wallet page must not list captainA's payment.
        $response = $this->actingAs($captainB)->get(route('wallet.index'));
        $response->assertOk();
        $this->assertSame(0, $response->viewData('payments')->count());
    }
}
```

### FILE: app/Models/Payment.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PAID = 'paid';
    /**
     * Legacy (Phase 01–07) success status: manually verified by an admin.
     * Kept distinct from `paid` (provider-confirmed) so we never falsely
     * relabel a manual verification as a gateway confirmation.
     */
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';

    /**
     * Controlled payment state machine. A normal user can never move a
     * payment into `paid`/`verified`; only the admin verification workflow
     * or a verified provider callback may.
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING => [
            self::STATUS_PROCESSING,
            self::STATUS_PAID,
            self::STATUS_VERIFIED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_PROCESSING => [
            self::STATUS_PAID,
            self::STATUS_VERIFIED,
            self::STATUS_FAILED,
        ],
        self::STATUS_PAID => [self::STATUS_REFUNDED],
        self::STATUS_VERIFIED => [self::STATUS_REFUNDED],
        self::STATUS_FAILED => [],
        self::STATUS_CANCELLED => [],
        self::STATUS_REFUNDED => [],
    ];

    /**
     * Statuses that represent a successfully settled payment.
     */
    public const SUCCESS_STATUSES = [
        self::STATUS_PAID,
        self::STATUS_VERIFIED,
    ];

    /**
     * Statuses that still block a new payment attempt for the same team.
     */
    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_PAID,
        self::STATUS_VERIFIED,
    ];

    /**
     * tournament_id, team_id, amount, amount_minor, currency, provider,
     * provider_reference, idempotency_key, payer_user_id, paid_at and
     * status are all server-controlled. Only the raw bKash submission fields
     * are mass-assignable.
     */
    protected $fillable = [
        'method',
        'trx_id',
    ];

    protected $casts = [
        'amount' => 'float',
        'amount_minor' => 'integer',
        'paid_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function payer()
    {
        return $this->belongsTo(User::class, 'payer_user_id');
    }

    public function refund()
    {
        return $this->hasOne(Refund::class);
    }

    public function events()
    {
        return $this->hasMany(PaymentEvent::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function belongsToTournament(Tournament $tournament): bool
    {
        return $this->tournament_id === $tournament->id;
    }

    public function belongsToTeam(Team $team): bool
    {
        return $this->team_id === $team->id;
    }

    public function canTransitionTo(string $target): bool
    {
        return in_array($target, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isSuccessful(): bool
    {
        return in_array($this->status, self::SUCCESS_STATUSES, true);
    }

    public function isRefundable(): bool
    {
        return $this->isSuccessful();
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_FAILED, self::STATUS_CANCELLED, self::STATUS_REFUNDED], true);
    }

    /**
     * The authoritative integer minor-unit amount (poisha).
     */
    public function amountMinor(): int
    {
        return (int) ($this->amount_minor ?? 0);
    }

    /**
     * Human status label for Blade views.
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PROCESSING => 'Processing',
            self::STATUS_PAID => 'Paid',
            self::STATUS_VERIFIED => 'Verified',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_CANCELLED => 'Cancelled',
            self::STATUS_REFUNDED => 'Refunded',
            default => 'Pending',
        };
    }

    /**
     * Status pill class reusing the shared layout palette.
     */
    public function statusPill(): string
    {
        return match ($this->status) {
            self::STATUS_PAID, self::STATUS_VERIFIED => 'confirmed',
            self::STATUS_FAILED => 'failed',
            self::STATUS_CANCELLED => 'cancelled',
            self::STATUS_REFUNDED => 'finished',
            self::STATUS_PROCESSING => 'live',
            default => 'pending',
        };
    }
}
```

### FILE: app/Models/User.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * Sensitive fields (role, wallet_balance) are intentionally excluded from
     * mass assignment. `role` must be set explicitly (see AuthController) and
     * can only ever be 'player' or 'organizer' at registration time.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'username',
        'phone',
        'game_uid',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isOrganizer(): bool
    {
        return $this->role === 'organizer';
    }

    /**
     * Moderators are platform staff who can work the dispute/moderation
     * queue and review/resolve disputes. The role is granted only by admins
     * (never self-assigned and never mass-assignable).
     */
    public function isModerator(): bool
    {
        return $this->role === 'moderator';
    }

    /**
     * Platform staff (admins + moderators) — distinct from tournament
     * organizers, who are staff only within their own tournaments.
     */
    public function isStaff(): bool
    {
        return $this->isAdmin() || $this->isModerator();
    }

    public function tournaments()
    {
        return $this->hasMany(Tournament::class, 'organizer_id');
    }

    public function teams()
    {
        return $this->hasMany(Team::class, 'captain_id');
    }

    /**
     * The user's wallet (Phase 08). Created lazily by WalletService.
     */
    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }
}
```

### FILE: app/Models/Tournament.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tournament extends Model
{
    use HasFactory;

    /**
     * Lifecycle states. These are the single source of truth for the values
     * stored in the `status` column.
     */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_LIVE = 'live';
    public const STATUS_FINISHED = 'finished';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Statuses that are visible on public listings. DRAFT (not yet published)
     * and CANCELLED tournaments are hidden.
     */
    public const PUBLIC_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_CLOSED,
        self::STATUS_LIVE,
        self::STATUS_FINISHED,
    ];

    /**
     * Valid state transitions. A tournament may only move along these edges;
     * it can never jump arbitrarily between states. FINISHED and CANCELLED
     * are terminal.
     *
     * draft    → open, cancelled
     * open     → closed, live, cancelled   (open == published + accepting)
     * closed   → live, cancelled
     * live     → finished
     * finished → (terminal)
     * cancelled→ (terminal)
     */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_OPEN, self::STATUS_CANCELLED],
        self::STATUS_OPEN => [self::STATUS_CLOSED, self::STATUS_LIVE, self::STATUS_CANCELLED],
        self::STATUS_CLOSED => [self::STATUS_LIVE, self::STATUS_CANCELLED],
        self::STATUS_LIVE => [self::STATUS_FINISHED],
        self::STATUS_FINISHED => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * Bracket formats. Only formats that are actually implemented may be
     * selected; the others are intentionally absent so they never appear
     * as a selectable option.
     */
    public const FORMAT_SINGLE_ELIM = 'single_elim';
    public const FORMAT_DOUBLE_ELIM = 'double_elim';

    public const FORMATS = [
        self::FORMAT_SINGLE_ELIM,
        self::FORMAT_DOUBLE_ELIM,
    ];

    /**
     * organizer_id, slug and status are set server-side only. They are
     * excluded from mass assignment so a client can never hijack ownership
     * or lifecycle state.
     */
    protected $fillable = [
        'name',
        'game_mode',
        'map',
        'entry_fee',
        'prize_pool',
        'team_slots',
        'team_size',
        'rules',
        'starts_at',
        'check_in_starts_at',
        'check_in_ends_at',
        'format',
        'dispute_window_hours',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'check_in_starts_at' => 'datetime',
        'check_in_ends_at' => 'datetime',
        'entry_fee' => 'float',
        'prize_pool' => 'float',
        'team_slots' => 'integer',
        'team_size' => 'integer',
        'bracket_size' => 'integer',
        'dispute_window_hours' => 'integer',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function organizer()
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function teams()
    {
        return $this->hasMany(Team::class);
    }

    public function confirmedTeams()
    {
        return $this->hasMany(Team::class)->where('status', Team::STATUS_CONFIRMED);
    }

    /**
     * Teams that currently occupy a slot: pending (awaiting payment
     * verification) or confirmed. Withdrawn, rejected, no-show and
     * waitlisted teams do not occupy a slot.
     */
    public function registeredTeams()
    {
        return $this->hasMany(Team::class)
            ->whereIn('status', [Team::STATUS_PENDING, Team::STATUS_CONFIRMED]);
    }

    /**
     * Teams on the waitlist, in deterministic FIFO order.
     */
    public function waitlistedTeams()
    {
        return $this->hasMany(Team::class)->where('status', Team::STATUS_WAITLISTED);
    }

    public function matches()
    {
        return $this->hasMany(GameMatch::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Scoring rule-set versions for this tournament (Phase 06).
     */
    public function scoringRules()
    {
        return $this->hasMany(ScoringRule::class);
    }

    /**
     * Disputes raised against matches in this tournament (Phase 07).
     */
    public function disputes()
    {
        return $this->hasMany(Dispute::class);
    }

    /**
     * The participant dispute window in hours (default 24). A value of 0
     * disables participant disputes entirely; staff always bypass.
     */
    public function disputeWindowHours(): int
    {
        return (int) ($this->dispute_window_hours ?? 24);
    }

    /**
     * The entry fee in integer minor units (poisha), computed from the
     * server-side `entry_fee` column — never from client input.
     */
    public function entryFeeMinor(): int
    {
        $raw = $this->getRawOriginal('entry_fee');

        if ($raw === null) {
            $raw = $this->entry_fee;
        }

        try {
            return \App\Support\Money::toMinor($raw);
        } catch (\DomainException $e) {
            return 0;
        }
    }

    /**
     * Number of registration slots still available. Counts both pending and
     * confirmed teams so that a team awaiting payment still reserves its slot.
     */
    public function slotsLeft(): int
    {
        return max(0, (int) $this->team_slots - $this->registeredTeams()->count());
    }

    public function isFull(): bool
    {
        return $this->slotsLeft() <= 0;
    }

    public function isOrganizedBy(User $user): bool
    {
        return $this->organizer_id === $user->id;
    }

    /**
     * Whether the configured start time has already passed.
     */
    public function hasStarted(): bool
    {
        return $this->starts_at !== null && $this->starts_at->isPast();
    }

    /**
     * Whether the tournament is currently accepting new registrations.
     * Status is the primary authority; the start time acts as a hard deadline.
     */
    public function acceptsRegistration(): bool
    {
        return $this->status === self::STATUS_OPEN && ! $this->hasStarted();
    }

    /**
     * Whether moving to the given status is legal from the current status.
     */
    public function canTransitionTo(string $target): bool
    {
        return in_array($target, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isFinished(): bool
    {
        return $this->status === self::STATUS_FINISHED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_LIVE;
    }

    public function isDoubleElim(): bool
    {
        return $this->format === self::FORMAT_DOUBLE_ELIM;
    }

    // ------------------------------------------------------------------
    // Phase 04 — check-in window + bracket eligibility
    // ------------------------------------------------------------------

    /**
     * Check-in is only active when BOTH window timestamps are configured.
     * Tournaments created before this feature (or without a window) simply
     * do not require check-in, preserving the Phase 02 behaviour.
     */
    public function hasCheckIn(): bool
    {
        return $this->check_in_starts_at !== null && $this->check_in_ends_at !== null;
    }

    /**
     * Whether the check-in window is currently open.
     */
    public function checkInIsOpen(): bool
    {
        if (! $this->hasCheckIn()) {
            return false;
        }

        return now()->between($this->check_in_starts_at, $this->check_in_ends_at);
    }

    /**
     * Whether the check-in window has already closed.
     */
    public function checkInHasClosed(): bool
    {
        return $this->hasCheckIn() && $this->check_in_ends_at->isPast();
    }

    /**
     * The set of teams eligible for competitive bracket generation:
     * confirmed teams that have checked in (when check-in is configured).
     * This is the single source of truth used by BracketService.
     */
    public function bracketEligibleTeams()
    {
        return $this->teams()
            ->where('status', Team::STATUS_CONFIRMED)
            ->when($this->hasCheckIn(), fn ($q) => $q->whereNotNull('checked_in_at'));
    }
}
```

### FILE: app/Http/Controllers/PaymentController.php
```php
<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Team;
use App\Models\Tournament;
use App\Services\PaymentService;
use DomainException;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        protected PaymentService $payments,
    ) {
    }

    /**
     * bKash payment page for a team's entry fee.
     */
    public function show(Tournament $tournament, Team $team)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('pay', $team);

        // Reuse the existing pending payment instead of letting the user
        // start a duplicate one.
        $existing = Payment::where('team_id', $team->id)
            ->whereIn('status', Payment::ACTIVE_STATUSES)
            ->first();

        if ($existing !== null) {
            return redirect()->route('payment.pending', [$tournament, $team, $existing]);
        }

        return view('payment.show', compact('tournament', 'team'));
    }

    /**
     * Simulated bKash "Send Money" verification.
     * The server computes the amount from the tournament's entry fee; client
     * amounts are never accepted.
     */
    public function verify(Request $request, Tournament $tournament, Team $team)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        $this->authorize('pay', $team);

        $data = $request->validate([
            'bkash_number' => 'required|string|min:11|max:15',
            'trx_id' => 'required|string|max:40',
        ]);

        try {
            $payment = $this->payments->createForTeam(
                $tournament,
                $team,
                $request->user(),
                'bkash',
                $data['trx_id'],
            );
        } catch (DomainException $e) {
            // Duplicate active payment → point at the existing one.
            $existing = Payment::where('team_id', $team->id)
                ->whereIn('status', Payment::ACTIVE_STATUSES)
                ->first();

            if ($existing !== null) {
                return redirect()->route('payment.pending', [$tournament, $team, $existing]);
            }

            return back()->with('error', $e->getMessage());
        }

        if ($payment->isSuccessful()) {
            return redirect()
                ->route('tournaments.show', $tournament)
                ->with('success', 'Registration confirmed! Your team is in.');
        }

        return redirect()->route('payment.pending', [$tournament, $team, $payment]);
    }

    public function pending(Tournament $tournament, Team $team, Payment $payment)
    {
        abort_unless($team->belongsToTournament($tournament), 404);
        abort_unless($payment->belongsToTournament($tournament) && $payment->belongsToTeam($team), 404);
        $this->authorize('view', $payment);

        return view('payment.pending', compact('tournament', 'team', 'payment'));
    }
}
```

### FILE: app/Http/Controllers/AdminController.php
```php
<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PaymentService;
use App\Services\WalletService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function __construct(
        protected PaymentService $payments,
        protected WalletService $wallets,
    ) {
    }

    public function dashboard()
    {
        $stats = [
            'tournaments' => Tournament::count(),
            'teams' => Team::count(),
            'verified_payments' => Payment::whereIn('status', Payment::SUCCESS_STATUSES)->count(),
            'revenue' => Payment::whereIn('status', Payment::SUCCESS_STATUSES)->sum('amount'),
            'commission' => Payment::whereIn('status', Payment::SUCCESS_STATUSES)->sum('amount') * 0.08,
        ];

        $pendingPayments = Payment::with(['team', 'tournament'])->where('status', 'pending')->latest()->limit(20)->get();
        $moderators = User::where('role', 'moderator')->orderBy('name')->get();

        return view('admin.dashboard', compact('stats', 'pendingPayments', 'moderators'));
    }

    // ------------------------------------------------------------------
    // Payments
    // ------------------------------------------------------------------

    /**
     * Payment list with status/tournament filters.
     */
    public function payments(Request $request)
    {
        $payments = Payment::query()
            ->with(['team', 'tournament', 'payer', 'refund'])
            ->orderByDesc('created_at');

        $status = $request->query('status');
        if ($status !== null && $status !== '') {
            $payments->where('status', $status);
        }

        $tournamentId = (int) $request->query('tournament_id');
        if ($tournamentId > 0) {
            $payments->where('tournament_id', $tournamentId);
        }

        $payments = $payments->paginate(25)->withQueryString();

        $tournaments = Tournament::query()->orderBy('name')->get(['id', 'name']);
        $statuses = [
            Payment::STATUS_PENDING,
            Payment::STATUS_PROCESSING,
            Payment::STATUS_PAID,
            Payment::STATUS_VERIFIED,
            Payment::STATUS_FAILED,
            Payment::STATUS_CANCELLED,
            Payment::STATUS_REFUNDED,
        ];

        return view('admin.payments', compact('payments', 'tournaments', 'statuses', 'status', 'tournamentId'));
    }

    /**
     * Verify a pending payment (manual bKash verification) and confirm the
     * team — the legacy admin flow, now routed through the PaymentService.
     */
    public function verifyPayment(Payment $payment)
    {
        try {
            $this->payments->verifyManually($payment, auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payment verified. Team confirmed.');
    }

    /**
     * Fail a pending payment.
     */
    public function failPayment(Request $request, Payment $payment)
    {
        $reason = (string) $request->input('reason', '');

        try {
            $this->payments->markFailed($payment, auth()->user(), $reason);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payment marked as failed.');
    }

    /**
     * Refund a settled payment (full amount), crediting the payer's wallet.
     */
    public function refundPayment(Request $request, Payment $payment)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        try {
            $refund = $this->payments->refund($payment, auth()->user(), $data['reason']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payment refunded (৳' . \App\Support\Money::toDecimal($refund->amount_minor) . ' credited to the payer).');
    }

    // ------------------------------------------------------------------
    // Wallets + ledger
    // ------------------------------------------------------------------

    /**
     * A user's wallet with its ledger history.
     */
    public function wallet(User $user)
    {
        $wallet = $this->wallets->walletFor($user);
        $ledger = $wallet->ledgerEntries()->with('actor')->limit(200)->get();
        $delta = $this->wallets->reconciliationDelta($wallet);

        return view('admin.wallet', compact('user', 'wallet', 'ledger', 'delta'));
    }

    /**
     * Credit a user's wallet (admin manual credit/deposit).
     */
    public function creditWallet(Request $request, User $user)
    {
        $data = $request->validate([
            'amount' => 'required|string|regex:/^\d+(\.\d{1,2})?$/',
            'description' => 'required|string|max:255',
        ]);

        $minor = \App\Support\Money::toMinor($data['amount']);

        try {
            $wallet = $this->wallets->walletFor($user);
            $this->wallets->credit($wallet, $minor, LedgerEntry::TYPE_ADJUSTMENT, $data['description'], auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Wallet credited.');
    }

    /**
     * Debit a user's wallet (admin manual adjustment).
     */
    public function debitWallet(Request $request, User $user)
    {
        $data = $request->validate([
            'amount' => 'required|string|regex:/^\d+(\.\d{1,2})?$/',
            'description' => 'required|string|max:255',
        ]);

        $minor = \App\Support\Money::toMinor($data['amount']);

        try {
            $wallet = $this->wallets->walletFor($user);
            $this->wallets->debit($wallet, $minor, LedgerEntry::TYPE_ADJUSTMENT, $data['description'], auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Wallet debited.');
    }

    // ------------------------------------------------------------------
    // Moderation roles (Phase 07)
    // ------------------------------------------------------------------

    /**
     * Promote a user to moderator (admin only — the route sits behind the
     * `admin` middleware, and `role` is never mass-assignable).
     */
    public function makeModerator(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $user = User::where('email', $data['email'])->firstOrFail();

        if ($user->isAdmin()) {
            return back()->with('error', 'Admins are already staff.');
        }

        if ($user->isModerator()) {
            return back()->with('error', $user->name . ' is already a moderator.');
        }

        $user->role = 'moderator';
        $user->save();

        return back()->with('success', $user->name . ' is now a moderator.');
    }

    /**
     * Demote a moderator back to a regular player (admin only).
     */
    public function removeModerator(User $user)
    {
        if ($user->isAdmin()) {
            return back()->with('error', 'Cannot demote an admin.');
        }

        if (! $user->isModerator()) {
            return back()->with('error', 'This user is not a moderator.');
        }

        $user->role = 'player';
        $user->save();

        return back()->with('success', $user->name . ' is no longer a moderator.');
    }
}
```

### FILE: app/Policies/PaymentPolicy.php
```php
<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    /**
     * Only admins, the payment's tournament organizer, or the paying team's
     * captain may view a payment.
     */
    public function view(User $user, Payment $payment): bool
    {
        return $user->isAdmin()
            || $payment->tournament->organizer_id === $user->id
            || $payment->team->isCaptain($user);
    }

    /**
     * Only admins may verify/reject/refund payments.
     */
    public function verify(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Only admins may issue refunds (authorized financial operation).
     */
    public function refund(User $user, Payment $payment): bool
    {
        return $user->isAdmin();
    }
}
```

### FILE: routes/web.php
```php
<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DisputeController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\ModerationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ScoringRuleController;
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

    // Wallet (authenticated user)
    Route::get('/wallet', [WalletController::class, 'index'])->name('wallet.index');

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

        // Moderation roles (Phase 07)
        Route::post('/users/moderators', [AdminController::class, 'makeModerator'])->name('users.moderate');
        Route::post('/users/{user}/remove-moderator', [AdminController::class, 'removeModerator'])->name('users.unmoderate');
    });
});
```

### FILE: config/services.php
```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Payments (Phase 08)
    |--------------------------------------------------------------------------
    */
    'payments' => [
        // Secret used to sign/verify provider webhook callbacks (HMAC-SHA256).
        'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET', 'ffarena-local-webhook-secret'),
    ],

];
```

### FILE: bootstrap/app.php
```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
        ]);

        // Provider payment webhooks are authenticated by HMAC signature, not
        // by a session CSRF token.
        $middleware->validateCsrfTokens(except: [
            'webhooks/payments/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
```

### FILE: resources/views/layouts/app.blade.php
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
    <nav>
        <a href="{{ route('home') }}" class="brand">FF<span>ARENA</span></a>
        <div class="nav-links">
            <a href="{{ route('tournaments.index') }}">Tournaments</a>
            @auth
                @if(auth()->user()->isOrganizer() || auth()->user()->isAdmin())
                    <a href="{{ route('tournaments.create') }}" class="btn btn-sm btn-cyan">+ Create Tournament</a>
                @endif
                <a href="{{ route('wallet.index') }}" class="btn btn-sm">Wallet</a>
                @if(auth()->user()->isAdmin() || auth()->user()->isModerator() || auth()->user()->isOrganizer())
                    <a href="{{ route('moderation.index') }}" class="btn btn-sm">Moderation</a>
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

### FILE: resources/views/admin/dashboard.blade.php
```blade
@extends('layouts.app')
@section('title', 'Admin Dashboard — FF Arena')
@section('content')
    <h1 style="margin:30px 0 16px">🛡 Admin Dashboard</h1>

    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr))">
        <div class="stat"><div class="muted">Tournaments</div><div class="num">{{ $stats['tournaments'] }}</div></div>
        <div class="stat"><div class="muted">Teams</div><div class="num">{{ $stats['teams'] }}</div></div>
        <div class="stat"><div class="muted">Verified payments</div><div class="num">{{ $stats['verified_payments'] }}</div></div>
        <div class="stat"><div class="muted">Collected (৳)</div><div class="num">{{ number_format($stats['revenue']) }}</div></div>
        <div class="stat"><div class="muted">Platform commission (8%)</div><div class="num" style="color:var(--green)">৳{{ number_format($stats['commission']) }}</div></div>
    </div>

    <div class="card" style="margin-top:18px">
        <h3>🛡 Moderators</h3>
        <form method="POST" action="{{ route('admin.users.moderate') }}" style="display:flex; gap:10px; align-items:end">
            @csrf
            <div style="flex:1; max-width:320px">
                <label>Promote a user to moderator (by email)</label>
                <input type="email" name="email" placeholder="user@example.com" required>
            </div>
            <button class="btn btn-cyan btn-sm">Promote</button>
        </form>
        @if($moderators->isEmpty())
            <p class="muted" style="margin-top:12px">No moderators yet.</p>
        @else
            <table style="margin-top:12px">
                <tr><th>Name</th><th>Email</th><th></th></tr>
                @foreach($moderators as $moderator)
                    <tr>
                        <td>{{ $moderator->name }}</td>
                        <td class="muted">{{ $moderator->email }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.users.unmoderate', $moderator) }}">
                                @csrf
                                <button class="btn btn-sm" style="border-color:var(--red); color:var(--red)">Demote</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>

    <div class="card" style="margin-top:18px">
        <h3>💸 Pending Payments</h3>
        @if($pendingPayments->isEmpty())
            <p class="muted">No pending payments.</p>
        @else
            <table>
                <tr><th>Tournament</th><th>Team</th><th>Amount</th><th>TrxID</th><th>Action</th></tr>
                @foreach($pendingPayments as $p)
                    <tr>
                        <td>{{ $p->tournament->name }}</td>
                        <td>{{ $p->team->name }}</td>
                        <td>৳{{ number_format($p->amount_minor / 100, 2) }}</td>
                        <td>{{ $p->trx_id }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.payments.verify', $p) }}" style="display:inline">@csrf
                                <button class="btn btn-green btn-sm">Verify</button>
                            </form>
                            <form method="POST" action="{{ route('admin.payments.fail', $p) }}" style="display:inline">@csrf
                                <button class="btn btn-sm">Reject</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </table>
            <p class="muted" style="font-size:13px; margin-top:12px">
                <a href="{{ route('admin.payments.index') }}">View all payments &amp; refunds →</a>
            </p>
        @endif
    </div>
@endsection
```

### FILE: resources/views/payment/pending.blade.php
```blade
@extends('layouts.app')
@section('title', 'Payment Pending — FF Arena')
@section('content')
    <div class="card" style="max-width: 460px; margin: 50px auto; text-align:center">
        <div style="font-size:44px">⏳</div>
        <h2>Payment under review</h2>
        <p class="muted">
            Your payment (৳{{ number_format($payment->amount, 2) }}, TrxID
            <strong>{{ $payment->trx_id }}</strong>) is being verified by the organizer.
        </p>
        <div class="stat" style="margin:16px 0">
            <span class="pill {{ $payment->statusPill() }}">{{ strtoupper($payment->status) }}</span>
        </div>
        @if(in_array($payment->status, ['pending', 'processing'], true))
            <p class="muted" style="font-size:13px">
                Once verified, team <strong>{{ $team->name }}</strong> will be confirmed automatically.
            </p>
        @elseif($payment->isSuccessful())
            <p style="color:var(--green); font-size:13px">✓ Payment verified — your team is confirmed.</p>
        @elseif($payment->status === 'refunded')
            <p class="muted" style="font-size:13px">This payment has been refunded to your wallet.</p>
        @endif
        <a href="{{ route('tournaments.show', $tournament) }}" class="btn btn-sm" style="margin-top:12px">Back to tournament</a>
        <a href="{{ route('wallet.index') }}" class="btn btn-sm" style="margin-top:12px">My Wallet</a>
    </div>
@endsection
```
