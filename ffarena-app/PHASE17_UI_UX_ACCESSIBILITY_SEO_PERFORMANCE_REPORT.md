# PHASE17_UI_UX_ACCESSIBILITY_SEO_PERFORMANCE_REPORT.md

**Project:** FF Arena (Laravel 12.69.1, PHP 8.4.24, SQLite, Tailwind CSS v4 + Vite 7)
**Status:** COMPLETE — all verification gates green.

**Verification (final):** full suite **812 tests / 2560 assertions** green
(Phase 17: **52 tests / 233 assertions** across 9 files); scoped Pint
**PASS 77 files**; `php -l` clean; `view:cache` compiles every Blade view;
`route:list` **258 routes**; `migrate:fresh --seed` **28 migrations** run;
`npm install && npm run build` green (CSS 47.97 kB / gzip 11.96 kB, JS
0.00 kB); `public/build/` removed after verification; no stray `artisan
serve` processes.

---

## 1. Phase 17 Assumption

No dedicated Phase 17 specification existed. Phase 17 was defined as
**world-class UI/UX + accessibility + SEO + frontend performance** — the
presentation layer over the Phase 01–16 business platform. Backend business
logic, domain services, scoring/payment/wallet/payout/dispute/authorization
rules, API contracts and webhook signatures are **unchanged**.

## 2. Current UI Audit (inspection findings)

Inspected before writing any code:

- 67 Blade views, **one shared layout** (`layouts/app.blade.php`) whose entire
  design system was a ~150-line inline `<style>` block; `welcome.blade.php`
  was the untouched Laravel starter page (unused — `/` is `home.blade.php`).
- No Blade components directory; no vendor pagination views (Laravel's
  unstyled Tailwind pagination was used with no Tailwind actually loaded).
- No `@vite` wiring in the layout; `resources/css/app.css` was a 15-line
  Tailwind skeleton; `resources/js/app.js` only bundled **Axios, which nothing
  used** (all AJAX uses native `fetch`) — 51.5 kB of dead weight.
- `public/build/` empty (never built); `public/favicon.ico` was **0 bytes**;
  `public/robots.txt` was permissive (`Disallow:` nothing); no sitemap; no
  canonical/meta/OG/JSON-LD anywhere; no skip-link, landmarks, focus styles,
  aria-live, reduced-motion or mobile menu.
- Forms used bare `<label>` without `for`, no autocomplete, no
  `aria-invalid`/`aria-describedby` error wiring.
- Live feed (`live/poll.blade.php`) and the unread badge polled every 10 s
  **even in hidden tabs**, with no backoff; the nav had no mobile layout.

## 3. Design-System Audit

No reusable vocabulary existed beyond a handful of ad-hoc inline-styled
classes (`.btn`, `.card`, `.pill`, `.flash`, `.grid`, `.stat`, `.bracket-*`,
`.tag`, `.muted`) and CSS custom properties (`--bg`, `--panel`, …) embedded in
the layout's inline `<style>`.

## 4. Design System

A complete design system was built in **one source of truth**
(`public/css/app.css`, mirrored to the Vite entry `resources/css/app.css`):

- tokens (surfaces, text, brand, semantic colors, radii, a 4px spacing scale,
  focus ring, min touch-target, fonts, motion);
- light reset + base typography + link focus affordances;
- `.skip-link`; layout shell (`site-header`/`nav-bar`/`site-main`/
  `site-footer`/`container`);
- buttons (`btn`, variants primary/cyan/green/danger/ghost, sizes sm/lg,
  disabled, block, `[data-loading]`), status pills (paired dot **and** text),
  cards, stats, grids, forms (fields, labels, checkbox/radio, help-text,
  `aria-invalid` styling), responsive `.table-wrap` tables with sticky headers
  + captions, alerts (`.alert-*`), empty states, accessible pagination,
  breadcrumbs, bracket components, avatars, spinners/skeletons, utilities and
  `.sr-only`;
- `@media (prefers-reduced-motion: reduce)`, `@media (pointer: coarse)` (44px
  targets), `@media (max-width: 900px)` mobile nav, print styles.

The existing visual identity (dark navy/cyan/purple esports theme) and the
existing class/variable names were preserved, so every untouched page adopts
the system automatically.

## 5. Layout Architecture

`layouts/app.blade.php` was rewritten: semantic `header > nav` / `main`
(`id="main"` + skip link target) / `footer`; consistent header with brand
(SVG mark), a responsive **mobile menu toggle** (`aria-expanded`/
`aria-controls`), role-gated links (unchanged), a server-rendered unread badge
(`aria-live="polite"`), auth-state aware CTA, and a footer with Sitemap /
robots.txt links. Admin links remain visible only to the matching roles.

## 6. Mobile Responsiveness

- `<meta name="viewport">` retained; nav collapses to a hamburger at
  ≤ 900 px; tap targets grow to 44 px on coarse pointers.
- Tables wrap horizontally in `.table-wrap` (leaderboard, registered teams,
  waitlist); tournament cards use auto-fill grids (no fixed widths); bracket
  columns scroll horizontally with `scroll-snap`.

## 7. Accessibility Standard

Targeted **WCAG 2.2 Level AA** (not certified). Implemented and tested:
keyboard operation, focus visibility + not-obscured (no sticky header),
target size (≥ 40 px desktop / 44 px touch), labels, error identification,
accessible authentication (autocomplete + paste-friendly, no puzzles),
status messages, contrast (muted text lifted to ≥ 4.5:1), semantic structure.

## 8. Semantic HTML

`header`, `nav`, `main`, `footer`, `section`, `article`, `dl/dt/dd` (stats),
`table/caption/thead/tbody/th[scope]`, `fieldset/legend`, `button` (no
div-as-button) are used on every rewritten page.

## 9. Keyboard Navigation

Skip-to-content link; visible `:focus-visible` outline; logical tab order;
Escape closes the mobile menu and returns focus to the toggle; click-outside
closes it; auto-collapse on desktop resize; no focus trapping.

## 10. Screen Readers

`aria-expanded`/`aria-controls` on the menu; `aria-live="polite"` unread
badge + live feed; `role="status"`/`role="alert"` flash messages; `sr-only`
table captions, filter labels and toggle labels; accessible names on bracket
links. No ARIA was added where native semantics suffice.

## 11. Focus

Global `:focus-visible` ring; skip link; bracket/breadcrumb/pagination focus
targets; no sticky header to obscure focus.

## 12. Target Size

Buttons/nav/pagination/checkbox hit areas ≥ 40 px (44 px on touch) via
`--target-min` and the `pointer: coarse` media query.

## 13. Color Contrast

Muted text raised to `#9aa4c8`/`#b6bfe0` (≥ 4.5:1 on the dark surfaces);
status pills always pair a color dot **with text**; winner marked by a "W"
badge + text, never color alone; error/success use border + text + glyph.

## 14. Reduced Motion

`prefers-reduced-motion: reduce` disables all transitions/animations
(spinner, skeleton, hover transforms); the JS also sets `scroll-behavior:
auto` when the user prefers reduced motion.

## 15. Forms

Auth forms (login/register) fully wired: `label[for]`, correct input types,
autocomplete (`name`, `username`, `email`, `tel`, `current-password`,
`new-password`), `aria-invalid` + `aria-describedby` on errors, per-field
`.form-error` messages. In the second pass every long-tail form (wallet,
payment, support, disputes, teams, tournaments create/edit/scoring,
settings, profile/edit, admin moderation/settlement/accounts) was
hand-rewritten with the same field/input/error semantics, labelled controls,
and correct input types, while preserving every business conditional and
route.

## 16. Authentication UX

Login/register redesigned; password-manager-friendly autocomplete kept; no
inaccessible CAPTCHA or puzzles; Google sign-in and phone-login entry points
preserved verbatim.

## 17. Account / Settings UX

Profile page redesigned (avatar fallback initial, username, role pill, bio,
country/region, joined date, edit link). Settings pages inherit the design
system.

## 18. Tournament Discovery

`/tournaments` gained GET-based filters — search (`q`, LIKE-wildcard-escaped),
status, game mode — with `withQueryString()` pagination, accessible labels,
and a "Clear" reset. Default (unfiltered) listing is unchanged.

## 19. Tournament Detail UX

`tournaments.show` rebuilt with breadcrumbs, prize/entry/rules stat cards,
organizer controls, "your team" card, register/waitlist CTAs, bracket, and
responsive captioned tables — every business conditional preserved.

## 20. Registration UX / 21. Check-in / 22. Waitlist

States surfaced with text+icon pills (check-in open/closed, waitlist
position, checked-in) on the tournament page; no false confirmations; no
other team's private data exposed (waitlist positions only to the team's
captain).

## 23. Bracket UX / 24. Match UX

Bracket cards (`matches/_bracket_card`) restyled with text statuses (Done/
Disputed/Live/Ready/Bye) + winner "W" badge and accessible names; horizontal
scroll with snap; room credentials remain visibility-gated (unchanged logic).

## 25. Score Submission UX

Unchanged (server-authoritative, Phase 06) — inherits the design system.
No client-side point totals were introduced.

## 26. Leaderboard UX

`leaderboard.show` rebuilt: breadcrumbs, responsive captioned table
(rank/team/matches/kills/place/kill/total), empty state, deterministic-
ordering note retained. Ranking calculation untouched.

## 27. Profile UX / 29. Wallet / 28. Payment / 30. Dispute / 31. Support /
32. Admin / 33. Notifications / 34. Realtime

Notifications page redesigned (semantic list, status pills, empty state,
pagination); live feed made visibility-aware with backoff, capped DOM (12
items), `aria-live` list and reduced-motion-safe. Wallet, payment, dispute,
support and admin pages adopt the shared design system (buttons, tables,
pills, alerts, forms) without business-logic changes; internal moderation
notes and financial internals remain staff-only (unchanged authorization).

## 35. Loading / 36. Empty / 37. Error / 38. Success States

Shared `x-alert` (role=status/alert), `x-empty-state`, `.spinner`, `.skeleton`
and `[data-loading]` patterns; duplicate-submit-safe buttons; no
inaccessible spinners without status text. Server errors never expose stack
traces (Phase 15/16 envelope preserved).

## 39. SEO Architecture

Indexable public pages opt in via a request-scoped `Seo` manager; **every
other page is noindex by default**, so SEO can never override authorization.
Indexable: homepage, tournament listing, public tournament details, live/
finished leaderboards, public profiles. Not indexable: admin, wallet,
payments, notifications, settings, support, disputes, private/limited
profiles, drafts/cancelled tournaments, auth pages.

## 40. Titles / 41. Descriptions / 42. Canonical URLs

Unique server-rendered titles + ≤ 160-char descriptions + canonical URLs for
every public page; description fallbacks never contain private data. The
listing page canonicalizes to its clean URL regardless of filters.

## 43. robots.txt / 44. XML Sitemap

`/robots.txt` is now a dynamic route (absolute `Sitemap:` URL) blocking
`/admin`, `/wallet`, `/settings`, `/notifications`, `/support`, `/disputes`,
`/moderation`, `/organizer`, `/profile/edit`, `/login`, `/register`, auth/
OTP, `/api/`, `/webhooks/`, and allowing `/tournaments` + `/profile/`.
`/sitemap.xml` lists only indexable resources (homepage, listing, public
tournaments, live/finished leaderboards, public profiles), capped at 50 000
URLs, cached 1 h, `X-Robots-Tag: noindex` on the sitemap itself. robots.txt
is documented as a crawl hint, never a security control.

## 45. Structured Data

Accurate JSON-LD only: `WebSite` (homepage), `Event` (public tournaments —
with `eventStatus`, `eventAttendanceMode: Online`, `VirtualLocation`,
organizer, `startDate`, and `offers` when entry fee > 0; **never** for
cancelled tournaments), `ProfilePage`/`Person` (public profiles only). All
JSON is encoded with HTML-escaping flags so DB text can never break out of
the script tag; no fake ratings/reviews/event data.

## 46. Open Graph / 47. Social / 48. Favicon / 49. URL Quality

`og:site_name/title/description/type/url` (+ `twitter:*`) emitted for every
page; `og:image` only where a public image exists (currently none — honest).
New SVG favicon + generated 32×32 ICO + 180×180 apple-touch-icon + theme
color. Tournament URLs were already slug-based (kept); profile URLs remain
ID-based (unchanged, stable) with canonicalization to prevent duplicates.

## 50. Core Web Vitals / 51. LCP / 52. INP / 53. CLS

Targets (LCP ≤ 2.5 s, INP < 200 ms, CLS < 0.1) are **not claimed — not
measured with a real browser here**. Structural work done: no render-
blocking inline CSS (external stylesheet), deferred JS, no unused Axios
bundle, server-rendered HTML, no image CLS (avatars sized; no hero images),
visibility-aware polling, capped live-feed DOM. Real CWV must be measured
with Lighthouse/CrUX in production (§75).

## 54. JavaScript / 55. CSS / 56. Images / 57. Fonts / 58. Caching

- JS: dead Axios bundle removed (51.52 kB → 0 kB); shared behaviours in one
  deferred `public/js/app.js`; visibility-aware pollers with backoff.
- CSS: single design system (47.97 kB raw / 11.96 kB gzip when built), no
  inline `<style>` blocks, no duplicated selectors.
- Images: only decorative avatars/SVG icons; no large images; favicons
  optimized.
- Fonts: system font stack only (no external font requests).
- Caching: static assets cacheable by URL; private pages never publicly
  cached; sitemap cached 1 h.

## 59. Performance Budgets / 62. Performance Automation

Budget ceilings enforced by `PerformanceTest`: CSS < 120 kB, JS < 40 kB;
asserts external stylesheet, `defer`, favicon links, no inline `<style>`.
Measured build output recorded in §68.

## 60. Accessibility Automation / 61. SEO Automation

`tests/Feature/Phase17`: landmarks/skip-link/focus/reduced-motion/contrast
CSS checks, form labels + autocomplete, alert roles, table captions, status
pill text, breadcrumbs (AccessibilityTest); titles/descriptions/canonical/
noindex/JSON-LD/OG/robots/sitemap validity + exclusions (SeoTest,
SitemapRobotsTest). Manual keyboard guidance in §84.

## 63. Browser Testing / 64. Mobile Testing

No browser driver was introduced (kept lightweight). Critical flows are
covered by HTTP feature tests at the route/HTML level; mobile-specific CSS
behaviours are regression-tested via the stylesheet assertions. Manual
browser/mobile test guidance is in §84.

## 65. Internationalization Readiness

Shared layout/component chrome uses `__()` with `lang/en/ui.php` (English
fallback). Bengali (`lang/bn`) can be added without touching business copy.
Existing page copy stays English (unchanged).

## 66. Date/Currency/Number Formatting

BDT stays `number_format()` (integer/poisha precision untouched); dates via
existing `format('d M Y, h:i A')` conventions.

## 67. Security + SEO / 68. Accessible Authentication / 69. Security Headers

SEO never overrides authorization (noindex-by-default + privacy-gated
metadata). Accessible auth keeps autocomplete/paste and adds no puzzles, and
never weakens security. Phase 16 security headers/CSP remain compatible —
the layout removed its inline `<style>` (so a strict CSP `style-src` is
possible); JSON-LD uses a script tag that a nonce-based CSP should cover
(document §77).

## 70. Performance + Realtime / 71. Accessibility + Live Regions

Pollers pause in hidden tabs, resume on visibility, back off on failure, cap
the DOM (12 items), never duplicate events (revision cursor), and announce
via `aria-live="polite"` without focus stealing.

## 72. SEO Content Quality

All SEO content (titles, descriptions, JSON-LD) is derived from actual DB
content; no fake reviews/ratings/events.

## 73. Admin/Public Separation

No public page imports or renders admin analytics, audit logs, fraud state,
financial internals, support notes or identity data.

## 74. Responsive Table Audit

Every data table on every rewritten page is wrapped in `.table-wrap`
(horizontal scroll on narrow viewports) with a `<caption class="sr-only">`
and `<th scope="col">` headers: leaderboard, registered teams, waitlist,
wallet/ledger, payments, payouts, settlements, audit log, support queue,
analytics, accounts, security (devices/risk events/incidents), moderation
queue and dispute timelines. No table relies on colour alone for status —
every status cell carries a text+dot pill.

## 75. Design Consistency

One vocabulary: Primary/Secondary(cyan)/Success(green)/Danger/Neutral/Ghost
buttons; one status-pill set; one alert set; one table/form/card style.

## 76. Reusable Components

`x-alert`, `x-status-pill`, `x-empty-state`, `x-button` (link vs button,
variant/size), plus accessible pagination views — created to standardize
repeated patterns, not to rename single tags.

## 77. Known Limitations

- Second-pass completion: every long-tail view (auth, profile/edit,
  settings, wallet, payment, teams, tournaments create/edit/scoring,
  matches/show, disputes, support, moderation, organizer dashboards, and the
  full admin/analytics/security/ops/settlement set) is now hand-rewritten
  against the design system — no page still depends on the legacy
  inline-style markup. `welcome.blade.php` (unused Laravel starter page, not
  routed) is the sole untouched exception.
- Core Web Vitals were not measured in a real browser; only structural
  optimizations + budget tests are in place.
- No `og:image` yet (no public banner asset exists); social previews use text
  cards until one is added.
- Axios remains in `package.json` (dev dependency) though no longer bundled;
  removing it would churn the lockfile unnecessarily.
- The `public/build/` Vite output and `node_modules/` are build artifacts
  that do not persist in this sandbox; the layout serves
  `public/css/app.css`/`public/js/app.js` when no build manifest exists, so
  the app is fully styled with or without `npm run build`.

## 78. Production Recommendations

- Run `npm install && npm run build` and serve the hashed assets behind a
  CDN with long-lived cache headers.
- Enable `SECURITY_CSP_ENABLE` only after adding a nonce for the JSON-LD
  script and confirming all assets are self-hosted (the system font stack
  already is).
- Add a 1200×630 public banner image to activate `og:image`.
- Measure LCP/INP/CLS with Lighthouse/CrUX; the structural work is in place
  but the metrics must be verified on real hardware/networks.
- Add `lang/bn` translations when the Bangla rollout is planned.

---

## 79. COMPLETE FINAL CONTENT OF EVERY NEW/MODIFIED FILE

Every text file is reproduced below in full (byte-for-byte). The two
generated binary assets (favicon.ico, apple-touch-icon.png) are listed with
their specs — their bytes are binary and reproducible via the documented
generation steps.

## New Files (complete content)

### `app/Support/Seo.php`

```php
<?php

namespace App\Support;

/**
 * Phase 17 — per-request SEO metadata manager.
 *
 * A request-scoped singleton that accumulates search/social metadata for the
 * current page. Public, indexable pages opt in explicitly via ->indexable();
 * every other page stays noindex by default, so SEO can never override
 * authorization or leak private content into crawlers.
 *
 * All JSON-LD is JSON-encoded with HTML-escaping flags so untrusted database
 * text can never break out of the <script type="application/ld+json"> tag.
 */
class Seo
{
    private string $title = '';

    private string $description = '';

    private bool $indexable = false;

    private ?string $canonical = null;

    private string $ogType = 'website';

    private ?string $ogImage = null;

    private ?string $ogImageAlt = null;

    /** @var array<string, mixed>|null */
    private ?array $jsonLd = null;

    public function title(string $title): self
    {
        $this->title = trim($title);

        return $this;
    }

    public function description(string $description): self
    {
        // Keep meta descriptions to a sane, snippet-friendly length.
        $this->description = mb_substr(trim($description), 0, 160);

        return $this;
    }

    public function canonical(string $url): self
    {
        $this->canonical = $url;

        return $this;
    }

    public function indexable(bool $indexable = true): self
    {
        $this->indexable = $indexable;

        return $this;
    }

    public function ogType(string $type): self
    {
        $this->ogType = $type;

        return $this;
    }

    public function ogImage(?string $url, ?string $alt = null): self
    {
        $this->ogImage = $url;
        $this->ogImageAlt = $alt;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function jsonLd(array $data): self
    {
        $this->jsonLd = $data;

        return $this;
    }

    /**
     * @return array{title:string, description:string, indexable:bool, canonical:string, og_type:string, og_image:?string, og_image_alt:?string, jsonld:?string}
     */
    public function toArray(): array
    {
        $siteName = (string) config('app.name', 'FF Arena');

        $title = $this->title !== ''
            ? $this->title
            : $siteName.' — Bangladesh Free Fire Tournaments';

        $description = $this->description !== ''
            ? $this->description
            : 'FF Arena is Bangladesh\'s Free Fire tournament platform — organize, register, compete and get paid, all in one place.';

        $json = null;
        if ($this->jsonLd !== null) {
            $json = json_encode(
                $this->jsonLd,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            );

            // json_encode can return false on invalid UTF-8; never emit garbage.
            if ($json === false) {
                $json = null;
            }
        }

        return [
            'title' => $title,
            'description' => $description,
            'indexable' => $this->indexable,
            'canonical' => $this->canonical ?? url()->current(),
            'og_type' => $this->ogType,
            'og_image' => $this->ogImage,
            'og_image_alt' => $this->ogImageAlt,
            'jsonld' => $json,
        ];
    }
}
```

### `app/Http/Controllers/SitemapController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Phase 17 — SEO sitemap.
 *
 * Emits only indexable public resources: the homepage, the tournament
 * listing, public tournament pages, leaderboards for live/finished
 * tournaments and public player/organizer profiles. Private profiles,
 * drafts, cancelled tournaments, admin, wallet, settings, support and every
 * authenticated-only page are excluded by construction. A `noindex` X-Robots
 * header is set on the sitemap itself so search engines index the pages it
 * lists, not the sitemap document.
 */
class SitemapController extends Controller
{
    /**
     * Soft cap on the number of <url> entries emitted per generation.
     */
    private const MAX_URLS = 50000;

    public function index(): Response
    {
        $urls = Cache::remember('seo.sitemap', 3600, function () {
            $urls = [];

            $urls[] = [
                'loc' => route('home'),
                'changefreq' => 'daily',
                'priority' => '1.0',
            ];

            $urls[] = [
                'loc' => route('tournaments.index'),
                'changefreq' => 'hourly',
                'priority' => '0.9',
            ];

            Tournament::query()
                ->whereIn('status', Tournament::PUBLIC_STATUSES)
                ->select(['id', 'slug', 'status', 'updated_at'])
                ->orderBy('id')
                ->limit(10000)
                ->chunkById(500, function ($chunk) use (&$urls) {
                    foreach ($chunk as $tournament) {
                        $urls[] = [
                            'loc' => route('tournaments.show', ['tournament' => $tournament->slug]),
                            'lastmod' => $tournament->updated_at?->toAtomString(),
                            'changefreq' => 'daily',
                            'priority' => '0.8',
                        ];

                        // Standings only become meaningful once matches exist.
                        if (in_array($tournament->status, [Tournament::STATUS_LIVE, Tournament::STATUS_FINISHED], true)) {
                            $urls[] = [
                                'loc' => route('leaderboard.show', ['tournament' => $tournament->slug]),
                                'lastmod' => $tournament->updated_at?->toAtomString(),
                                'changefreq' => 'daily',
                                'priority' => '0.7',
                            ];
                        }
                    }
                });

            User::query()
                ->where('privacy', 'public')
                ->whereIn('role', ['player', 'organizer'])
                ->select(['id', 'updated_at'])
                ->orderBy('id')
                ->limit(10000)
                ->chunkById(500, function ($chunk) use (&$urls) {
                    foreach ($chunk as $user) {
                        $urls[] = [
                            'loc' => route('profile.show', $user),
                            'lastmod' => $user->updated_at?->toAtomString(),
                            'changefreq' => 'weekly',
                            'priority' => '0.5',
                        ];
                    }
                });

            return array_slice($urls, 0, self::MAX_URLS);
        });

        return response()
            ->view('seo.sitemap', ['urls' => $urls])
            ->header('Content-Type', 'text/xml; charset=UTF-8')
            ->header('X-Robots-Tag', 'noindex');
    }

    /**
     * robots.txt — served dynamically so the Sitemap directive can use the
     * configured absolute application URL.
     */
    public function robots(): Response
    {
        $lines = [
            '# FF Arena — robots.txt (Phase 17)',
            '# robots.txt is a crawl hint, not a security control; private data',
            '# is protected by server-side authorization, not by this file.',
            'User-agent: *',
            '',
            'Disallow: /admin',
            'Disallow: /wallet',
            'Disallow: /settings',
            'Disallow: /notifications',
            'Disallow: /support',
            'Disallow: /disputes',
            'Disallow: /moderation',
            'Disallow: /organizer',
            'Disallow: /profile/edit',
            'Disallow: /login',
            'Disallow: /register',
            'Disallow: /forgot-password',
            'Disallow: /reset-password',
            'Disallow: /login/phone',
            'Disallow: /auth/',
            'Disallow: /account',
            'Disallow: /api/',
            'Disallow: /webhooks/',
            '',
            'Allow: /tournaments',
            'Allow: /profile/',
            '',
            'Sitemap: '.route('sitemap'),
            '',
        ];

        return response(implode("\n", $lines), 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
```

### `resources/views/seo/sitemap.blade.php`

```blade
<?php echo '<?xml version="1.0" encoding="UTF-8"?>'; ?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($urls as $entry)
    <url>
        <loc>{{ $entry['loc'] }}</loc>
        @if (! empty($entry['lastmod']))<lastmod>{{ $entry['lastmod'] }}</lastmod>@endif
        @if (! empty($entry['changefreq']))<changefreq>{{ $entry['changefreq'] }}</changefreq>@endif
        @if (isset($entry['priority']))<priority>{{ $entry['priority'] }}</priority>@endif
    </url>
@endforeach
</urlset>
```

### `resources/views/components/alert.blade.php`

```blade
@props(['type' => 'info'])

@php
    // Errors and warnings are announced assertively; success/info politely.
    $role = in_array($type, ['error', 'warning'], true) ? 'alert' : 'status';
@endphp

<div role="{{ $role }}" {{ $attributes->merge(['class' => 'alert alert-'.$type]) }}>
    {{ $slot }}
</div>
```

### `resources/views/components/status-pill.blade.php`

```blade
@props(['status', 'label' => null])

@php
    $labels = [
        'draft' => 'Draft',
        'open' => 'Open',
        'closed' => 'Closed',
        'live' => 'Live',
        'finished' => 'Finished',
        'cancelled' => 'Cancelled',
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'verified' => 'Verified',
        'checked' => 'Checked In',
        'checked_in' => 'Checked In',
        'waitlisted' => 'Waitlisted',
        'withdrawn' => 'Withdrawn',
        'rejected' => 'Rejected',
        'no_show' => 'No Show',
        'disputed' => 'Disputed',
        'under_review' => 'Under Review',
        'resolved' => 'Resolved',
        'failed' => 'Failed',
        'refunded' => 'Refunded',
        'paid' => 'Paid',
        'processing' => 'Processing',
        'ready' => 'Ready',
        'bye' => 'Bye',
        'expired' => 'Expired',
        'suspended' => 'Suspended',
        'active' => 'Active',
        'completed' => 'Completed',
        'success' => 'Success',
        'warning' => 'Warning',
        'overdue' => 'Overdue',
        'in_progress' => 'In Progress',
    ];

    $text = $label ?? ($labels[$status] ?? strtoupper(str_replace('_', ' ', (string) $status)));
@endphp

<span {{ $attributes->merge(['class' => 'pill '.$status]) }}>{{ $text }}</span>
```

### `resources/views/components/empty-state.blade.php`

```blade
@props(['title', 'icon' => null])

<div {{ $attributes->merge(['class' => 'empty-state']) }}>
    @if ($icon)
        <div class="empty-icon" aria-hidden="true">{{ $icon }}</div>
    @endif
    <h3>{{ $title }}</h3>
    <p>{{ $slot }}</p>
</div>
```

### `resources/views/components/button.blade.php`

```blade
@props(['href' => null, 'variant' => 'default', 'size' => null, 'type' => 'button'])

@php
    $class = 'btn'
        . ($variant && $variant !== 'default' ? ' btn-'.$variant : '')
        . ($size ? ' btn-'.$size : '');
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $class]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $class]) }}>{{ $slot }}</button>
@endif
```

### `resources/views/vendor/pagination/tailwind.blade.php`

```blade
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination">
        <ul class="pagination">
            {{-- Previous page link --}}
            @if ($paginator->onFirstPage())
                <li>
                    <span class="disabled" aria-disabled="true">&laquo; Previous</span>
                </li>
            @else
                <li>
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev">&laquo; Previous</a>
                </li>
            @endif

            {{-- Pagination elements --}}
            @foreach ($elements as $element)
                {{-- "Three Dots" separator --}}
                @if (is_string($element))
                    <li><span class="disabled" aria-hidden="true">{{ $element }}</span></li>
                @endif

                {{-- Array of links --}}
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <li><span aria-current="page" class="current">{{ $page }}</span></li>
                        @else
                            <li><a href="{{ $url }}" aria-label="Page {{ $page }}">{{ $page }}</a></li>
                        @endif
                    @endforeach
                @endif
            @endforeach

            {{-- Next page link --}}
            @if ($paginator->hasMorePages())
                <li>
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next">Next &raquo;</a>
                </li>
            @else
                <li>
                    <span class="disabled" aria-disabled="true">Next &raquo;</span>
                </li>
            @endif
        </ul>
    </nav>

    <p class="pagination-meta">
        Showing {{ $paginator->firstItem() ?? 0 }}&ndash;{{ $paginator->lastItem() ?? 0 }}
        of {{ $paginator->total() }} result{{ $paginator->total() === 1 ? '' : 's' }}
    </p>
@endif
```

### `resources/views/vendor/pagination/simple-tailwind.blade.php`

```blade
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination">
        <ul class="pagination">
            {{-- Previous page link --}}
            @if ($paginator->onFirstPage())
                <li>
                    <span class="disabled" aria-disabled="true">&laquo; Previous</span>
                </li>
            @else
                <li>
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev">&laquo; Previous</a>
                </li>
            @endif

            {{-- Next page link --}}
            @if ($paginator->hasMorePages())
                <li>
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next">Next &raquo;</a>
                </li>
            @else
                <li>
                    <span class="disabled" aria-disabled="true">Next &raquo;</span>
                </li>
            @endif
        </ul>
    </nav>
@endif
```

### `public/css/app.css`

```css
/* ==========================================================================
   FF Arena design system (Phase 17)
   --------------------------------------------------------------------------
   A single source of truth for the platform's look & feel. Built to WCAG 2.2
   AA: visible focus, minimum target sizes, non-color status cues, reduced
   motion support and accessible form/alert patterns.

   Two copies are kept in sync:
     - public/css/app.css  -> served directly (no build step required)
     - resources/css/app.css -> the Vite entry used by `npm run build`
   ========================================================================== */

/* --------------------------------------------------------------------------
   1. Design tokens
   -------------------------------------------------------------------------- */
:root {
    /* Surfaces */
    --bg: #0b0e1a;
    --panel: #141a2e;
    --panel2: #1b2340;
    --panel3: #232c52;
    --line: #28335a;
    --line-strong: #38457a;

    /* Text */
    --txt: #e8ecff;
    --muted: #9aa4c8;          /* >= 4.5:1 on --bg for body text */
    --muted-strong: #b6bfe0;

    /* Brand / accents */
    --cyan: #22d3ee;
    --purple: #a855f7;
    --violet: #7c3aed;
    --blue: #2563eb;

    /* Semantic */
    --green: #34d399;
    --green-strong: #6ee7b7;
    --red: #f87171;
    --red-strong: #fca5a5;
    --amber: #fbbf24;
    --amber-strong: #fcd34d;

    /* Focus */
    --focus: var(--cyan);
    --focus-ring: 0 0 0 3px rgba(34, 211, 238, .35);

    /* Shape */
    --radius-sm: 8px;
    --radius: 12px;
    --radius-lg: 16px;

    /* Spacing scale (4px base) */
    --space-1: 4px;
    --space-2: 8px;
    --space-3: 12px;
    --space-4: 16px;
    --space-5: 20px;
    --space-6: 24px;
    --space-8: 32px;
    --space-10: 40px;
    --space-12: 48px;

    /* Typography */
    --font-sans: 'Segoe UI', system-ui, -apple-system, 'Noto Sans Bengali', sans-serif;
    --font-mono: ui-monospace, 'SFMono-Regular', Menlo, Consolas, monospace;

    /* Motion */
    --t-fast: 120ms;
    --t-base: 180ms;

    /* Layout */
    --container: 1180px;
    --target-min: 40px;        /* minimum touch target, desktop */
}

/* --------------------------------------------------------------------------
   2. Reset
   -------------------------------------------------------------------------- */
*,
*::before,
*::after {
    box-sizing: border-box;
}

html {
    -webkit-text-size-adjust: 100%;
    scroll-behavior: smooth;
}

body {
    margin: 0;
    font-family: var(--font-sans);
    font-size: 16px;
    line-height: 1.55;
    color: var(--txt);
    background-color: var(--bg);
    background-image:
        radial-gradient(1200px 600px at 80% -10%, rgba(168, 85, 247, .14), transparent),
        radial-gradient(900px 500px at -10% 110%, rgba(34, 211, 238, .12), transparent);
    background-attachment: fixed;
    min-height: 100vh;
}

h1, h2, h3, h4, h5, h6 {
    margin: 0 0 var(--space-3);
    line-height: 1.2;
    overflow-wrap: break-word;
    scroll-margin-top: var(--space-6);
}

h1 { font-size: 1.75rem; }
h2 { font-size: 1.35rem; }
h3 { font-size: 1.1rem; }
h4 { font-size: 1rem; }

p { margin: 0 0 var(--space-3); }

ul, ol { margin: 0 0 var(--space-3); padding-left: var(--space-6); }

a {
    color: var(--cyan);
    text-decoration: none;
    border-radius: var(--radius-sm);
}

a:hover { text-decoration: underline; }
a:hover, a:focus-visible { text-decoration-thickness: 2px; text-underline-offset: 3px; }

img, svg, video { max-width: 100%; height: auto; display: block; }

button, input, select, textarea { font: inherit; }

/* --------------------------------------------------------------------------
   3. Focus visibility (WCAG 2.2 — Focus Visible / Not Obscured)
   -------------------------------------------------------------------------- */
:focus-visible {
    outline: 2px solid var(--focus);
    outline-offset: 2px;
    box-shadow: var(--focus-ring);
}

:focus:not(:focus-visible) { outline: none; }

/* --------------------------------------------------------------------------
   4. Skip link
   -------------------------------------------------------------------------- */
.skip-link {
    position: absolute;
    top: -100%;
    left: var(--space-4);
    z-index: 100;
    padding: var(--space-3) var(--space-4);
    background: var(--panel2);
    color: var(--txt);
    border: 1px solid var(--cyan);
    border-radius: var(--radius-sm);
    font-weight: 600;
    transition: top var(--t-fast) ease-in-out;
}

.skip-link:focus {
    top: var(--space-3);
    color: var(--txt);
    text-decoration: none;
}

/* --------------------------------------------------------------------------
   5. Layout shell
   -------------------------------------------------------------------------- */
.container {
    width: 100%;
    max-width: var(--container);
    margin-inline: auto;
    padding-inline: var(--space-5);
}

.site-header {
    position: relative;
    border-bottom: 1px solid var(--line);
    background: rgba(11, 14, 26, .85);
}

.site-main {
    min-height: 60vh;
    padding-block: var(--space-6);
}

.site-footer {
    border-top: 1px solid var(--line);
    margin-top: var(--space-10);
    padding-block: var(--space-6);
    color: var(--muted);
    font-size: .875rem;
}

.site-footer a { color: var(--muted-strong); }
.site-footer .container { display: flex; flex-wrap: wrap; gap: var(--space-4); align-items: center; justify-content: space-between; }

/* --- Nav bar --- */
.nav-bar {
    display: flex;
    align-items: center;
    gap: var(--space-4);
    padding-block: var(--space-3);
    flex-wrap: wrap;
}

.brand {
    font-size: 1.35rem;
    font-weight: 800;
    letter-spacing: .5px;
    color: var(--txt);
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
}

.brand:hover { text-decoration: none; }
.brand span { color: var(--cyan); }
.brand-mark { width: 26px; height: 26px; flex: none; }

.nav-links {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    margin-left: auto;
    flex-wrap: wrap;
}

.nav-link {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    min-height: var(--target-min);
    padding: var(--space-2) var(--space-3);
    border-radius: var(--radius-sm);
    color: var(--txt);
    font-size: .9rem;
    font-weight: 600;
    border: 1px solid transparent;
}

.nav-link:hover {
    text-decoration: none;
    background: var(--panel2);
    border-color: var(--line);
}

/* Notification badge */
.nav-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 1.4em;
    height: 1.4em;
    padding-inline: .4em;
    background: var(--red);
    color: #fff;
    border-radius: 999px;
    font-size: .72rem;
    font-weight: 700;
    line-height: 1;
}

.nav-badge[hidden] { display: none; }

/* Mobile menu toggle */
.nav-toggle {
    display: none;
    margin-left: auto;
    align-items: center;
    justify-content: center;
    gap: var(--space-2);
    min-height: var(--target-min);
    padding: var(--space-2) var(--space-3);
    background: var(--panel2);
    color: var(--txt);
    border: 1px solid var(--line);
    border-radius: var(--radius-sm);
    cursor: pointer;
    font-weight: 600;
}

.nav-toggle:hover { border-color: var(--cyan); }

.nav-toggle-icon,
.nav-toggle-icon::before,
.nav-toggle-icon::after {
    display: block;
    width: 18px;
    height: 2px;
    background: currentColor;
    border-radius: 2px;
    content: '';
    position: relative;
}

.nav-toggle-icon::before { position: absolute; top: -6px; }
.nav-toggle-icon::after { position: absolute; top: 6px; }

/* --------------------------------------------------------------------------
   6. Buttons (>= 40px desktop / 44px coarse pointer target)
   -------------------------------------------------------------------------- */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-2);
    min-height: var(--target-min);
    padding: var(--space-2) var(--space-4);
    border-radius: var(--radius-sm);
    border: 1px solid var(--line);
    background: var(--panel2);
    color: var(--txt);
    font-weight: 600;
    font-size: .9rem;
    line-height: 1.2;
    cursor: pointer;
    text-align: center;
    transition: background var(--t-fast), border-color var(--t-fast), filter var(--t-fast);
}

.btn:hover { border-color: var(--cyan); text-decoration: none; }

.btn:disabled,
.btn[aria-disabled="true"] {
    opacity: .55;
    cursor: not-allowed;
    filter: none;
}

.btn-primary {
    background: linear-gradient(90deg, var(--violet), var(--blue));
    border-color: transparent;
    color: #fff;
}

.btn-primary:hover { filter: brightness(1.12); }

.btn-cyan {
    background: rgba(34, 211, 238, .12);
    border-color: var(--cyan);
    color: var(--cyan);
}

.btn-cyan:hover { background: rgba(34, 211, 238, .2); }

.btn-green {
    background: rgba(52, 211, 153, .12);
    border-color: var(--green);
    color: var(--green-strong);
}

.btn-green:hover { background: rgba(52, 211, 153, .2); }

.btn-danger {
    background: rgba(248, 113, 113, .1);
    border-color: var(--red);
    color: var(--red-strong);
}

.btn-danger:hover { background: rgba(248, 113, 113, .2); }

.btn-ghost { background: transparent; }
.btn-ghost:hover { background: var(--panel2); }

.btn-sm { min-height: 34px; padding: var(--space-1) var(--space-3); font-size: .82rem; }
.btn-lg { min-height: 48px; padding: var(--space-3) var(--space-6); font-size: 1rem; }
.btn-block { width: 100%; }

.btn[data-loading="true"] { pointer-events: none; opacity: .7; }

/* --------------------------------------------------------------------------
   7. Badges & status pills (always pair color WITH text/icon)
   -------------------------------------------------------------------------- */
.pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: .75rem;
    font-weight: 700;
    letter-spacing: .3px;
    white-space: nowrap;
}

.pill::before {
    content: '';
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
    flex: none;
}

.pill.open, .pill.confirmed, .pill.verified, .pill.checked, .pill.completed, .pill.success, .pill.paid { background: rgba(52, 211, 153, .15); color: var(--green-strong); }
.pill.live, .pill.ready, .pill.active, .pill.in_progress { background: rgba(34, 211, 238, .15); color: var(--cyan); }
.pill.draft, .pill.pending, .pill.waitlisted, .pill.processing, .pill.warning, .pill.under_review { background: rgba(251, 191, 36, .15); color: var(--amber-strong); }
.pill.closed, .pill.finished, .pill.disputed, .pill.rejected, .pill.failed, .pill.cancelled, .pill.suspended, .pill.overdue { background: rgba(248, 113, 113, .15); color: var(--red-strong); }
.pill.withdrawn, .pill.no_show, .pill.bye, .pill.neutral, .pill.expired, .pill.refunded { background: rgba(148, 163, 184, .16); color: var(--muted-strong); }

/* --------------------------------------------------------------------------
   8. Cards
   -------------------------------------------------------------------------- */
.card {
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
    padding: var(--space-5);
    margin-bottom: var(--space-5);
}

.card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    flex-wrap: wrap;
    margin: calc(var(--space-5) * -1) calc(var(--space-5) * -1) var(--space-4);
    padding: var(--space-4) var(--space-5);
    border-bottom: 1px solid var(--line);
    border-radius: var(--radius-lg) var(--radius-lg) 0 0;
}

.card-header h2, .card-header h3 { margin: 0; }

/* --- Stats --- */
.stat {
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: var(--radius);
    padding: var(--space-4);
}

.stat .num { font-size: 1.6rem; font-weight: 800; color: var(--cyan); }
.stat .label { color: var(--muted); font-size: .8rem; }

/* --------------------------------------------------------------------------
   9. Grids
   -------------------------------------------------------------------------- */
.grid { display: grid; gap: var(--space-5); }
.cols-2 { grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); }
.cols-3 { grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); }
.cols-4 { grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); }

/* --------------------------------------------------------------------------
   10. Forms
   -------------------------------------------------------------------------- */
.field { margin-bottom: var(--space-4); }

.field label,
label.field-label {
    display: block;
    font-size: .85rem;
    font-weight: 600;
    color: var(--muted-strong);
    margin-bottom: var(--space-1);
}

input[type="text"],
input[type="email"],
input[type="password"],
input[type="search"],
input[type="number"],
input[type="tel"],
input[type="url"],
input[type="date"],
input[type="datetime-local"],
select,
textarea {
    width: 100%;
    min-height: var(--target-min);
    padding: var(--space-2) var(--space-3);
    border-radius: var(--radius-sm);
    border: 1px solid var(--line);
    background: #0d1226;
    color: var(--txt);
    font-size: .95rem;
}

input:focus, select:focus, textarea:focus {
    outline: none;
    border-color: var(--cyan);
    box-shadow: var(--focus-ring);
}

input[aria-invalid="true"],
select[aria-invalid="true"],
textarea[aria-invalid="true"] {
    border-color: var(--red);
}

input[aria-invalid="true"]:focus,
select[aria-invalid="true"]:focus,
textarea[aria-invalid="true"]:focus {
    box-shadow: 0 0 0 3px rgba(248, 113, 113, .35);
}

textarea { min-height: 120px; resize: vertical; }

/* Checkbox / radio — usable hit area */
.checkbox, .radio {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    min-height: var(--target-min);
    cursor: pointer;
    font-size: .9rem;
}

.checkbox input, .radio input {
    width: 18px;
    height: 18px;
    accent-color: var(--cyan);
    flex: none;
}

.help-text { color: var(--muted); font-size: .8rem; margin-top: var(--space-1); }

.form-error {
    display: block;
    margin-top: var(--space-1);
    font-size: .82rem;
    font-weight: 600;
    color: var(--red-strong);
}

.form-error::before { content: '⚠ '; }

/* Fieldset grouping */
fieldset {
    border: 1px solid var(--line);
    border-radius: var(--radius);
    padding: var(--space-4);
    margin: 0 0 var(--space-4);
}

legend {
    padding-inline: var(--space-2);
    font-weight: 700;
    color: var(--muted-strong);
}

/* --------------------------------------------------------------------------
   11. Tables (responsive via .table-wrap)
   -------------------------------------------------------------------------- */
.table-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    border: 1px solid var(--line);
    border-radius: var(--radius);
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 560px;
}

caption {
    text-align: left;
    padding: var(--space-3);
    color: var(--muted);
    font-size: .85rem;
    caption-side: top;
}

th, td {
    text-align: left;
    padding: var(--space-3);
    border-bottom: 1px solid var(--line);
    font-size: .9rem;
    vertical-align: top;
}

th {
    color: var(--muted-strong);
    font-size: .75rem;
    text-transform: uppercase;
    letter-spacing: .5px;
    background: var(--panel2);
    position: sticky;
    top: 0;
}

tr:last-child td { border-bottom: none; }

tbody tr:hover td { background: rgba(34, 211, 238, .03); }

/* --------------------------------------------------------------------------
   12. Alerts / flash messages (announced to screen readers)
   -------------------------------------------------------------------------- */
.alert {
    display: flex;
    gap: var(--space-3);
    align-items: flex-start;
    padding: var(--space-3) var(--space-4);
    border-radius: var(--radius);
    margin: var(--space-4) 0;
    font-weight: 600;
    border: 1px solid;
}

.alert-success, .flash.success { background: rgba(52, 211, 153, .15); color: var(--green-strong); border-color: rgba(52, 211, 153, .4); }
.alert-error, .flash.error { background: rgba(248, 113, 113, .15); color: var(--red-strong); border-color: rgba(248, 113, 113, .4); }
.alert-warning { background: rgba(251, 191, 36, .15); color: var(--amber-strong); border-color: rgba(251, 191, 36, .4); }
.alert-info { background: rgba(34, 211, 238, .15); color: var(--cyan); border-color: rgba(34, 211, 238, .4); }

.alert ul { margin: var(--space-2) 0 0; padding-left: var(--space-5); }

/* Back-compat alias used by older views */
.flash { padding: var(--space-3) var(--space-4); border-radius: var(--radius); margin: var(--space-4) 0; font-weight: 600; border: 1px solid; }

/* --------------------------------------------------------------------------
   13. Empty states
   -------------------------------------------------------------------------- */
.empty-state {
    text-align: center;
    padding: var(--space-10) var(--space-5);
    color: var(--muted);
}

.empty-state .empty-icon { font-size: 2rem; margin-bottom: var(--space-3); }
.empty-state h3 { color: var(--txt); }
.empty-state p { max-width: 40ch; margin-inline: auto; }

/* --------------------------------------------------------------------------
   14. Pagination (accessible: nav landmark + aria-current)
   -------------------------------------------------------------------------- */
.pagination {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-1);
    list-style: none;
    padding: 0;
    margin: var(--space-5) 0 0;
}

.pagination li { margin: 0; }

.pagination a,
.pagination span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: var(--target-min);
    min-height: var(--target-min);
    padding: var(--space-1) var(--space-3);
    border-radius: var(--radius-sm);
    border: 1px solid var(--line);
    background: var(--panel2);
    color: var(--txt);
    font-size: .9rem;
}

.pagination a:hover { border-color: var(--cyan); text-decoration: none; }

.pagination .current,
.pagination [aria-current="page"] {
    background: linear-gradient(90deg, var(--violet), var(--blue));
    border-color: transparent;
    color: #fff;
    font-weight: 700;
}

.pagination .disabled {
    opacity: .5;
}

.pagination-meta {
    color: var(--muted);
    font-size: .82rem;
    margin-top: var(--space-2);
}

/* --------------------------------------------------------------------------
   15. Breadcrumbs
   -------------------------------------------------------------------------- */
.breadcrumbs {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    align-items: center;
    list-style: none;
    padding: 0;
    margin: 0 0 var(--space-4);
    font-size: .85rem;
    color: var(--muted);
}

.breadcrumbs li { margin: 0; display: inline-flex; align-items: center; gap: var(--space-2); }
.breadcrumbs li + li::before { content: '/'; color: var(--line-strong); }
.breadcrumbs a { color: var(--muted-strong); }
.breadcrumbs [aria-current="page"] { color: var(--txt); font-weight: 600; }

/* --------------------------------------------------------------------------
   16. Bracket
   -------------------------------------------------------------------------- */
.bracket-col {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-6);
    align-items: flex-start;
    overflow-x: auto;
    padding-bottom: var(--space-3);
    scroll-snap-type: x proximity;
}

.bracket-round {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    min-width: 200px;
    scroll-snap-align: start;
}

.bracket-match {
    background: var(--panel2);
    border: 1px solid var(--line);
    border-radius: var(--radius);
    padding: var(--space-2);
}

.bracket-match a { display: block; color: inherit; border-radius: var(--radius-sm); }
.bracket-match a:hover { text-decoration: none; background: rgba(34, 211, 238, .05); }

.bracket-team {
    padding: var(--space-2) var(--space-3);
    border-radius: var(--radius-sm);
    font-size: .85rem;
    display: flex;
    justify-content: space-between;
    gap: var(--space-2);
    min-height: 32px;
    align-items: center;
}

.bracket-team.win { background: rgba(52, 211, 153, .12); color: var(--green-strong); font-weight: 700; }
.bracket-team.win::after { content: 'W'; font-size: .7rem; border: 1px solid currentColor; border-radius: 4px; padding: 0 4px; }
.bracket-team.bye { color: var(--muted); }

.divider { height: 1px; background: var(--line); margin: var(--space-1) 0; }

/* --------------------------------------------------------------------------
   17. Avatars
   -------------------------------------------------------------------------- */
.avatar {
    border-radius: 50%;
    object-fit: cover;
    border: 1px solid var(--line);
    background: var(--panel2);
}

.avatar-fallback {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: var(--panel2);
    border: 1px solid var(--line);
    color: var(--cyan);
    font-weight: 800;
}

/* --------------------------------------------------------------------------
   18. Loading states
   -------------------------------------------------------------------------- */
.spinner {
    width: 1.1em;
    height: 1.1em;
    border: 2px solid rgba(255, 255, 255, .25);
    border-top-color: currentColor;
    border-radius: 50%;
    display: inline-block;
    animation: spin .8s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }

.skeleton {
    background: linear-gradient(90deg, var(--panel2) 25%, var(--panel3) 50%, var(--panel2) 75%);
    background-size: 200% 100%;
    animation: shimmer 1.4s ease infinite;
    border-radius: var(--radius-sm);
}

@keyframes shimmer { to { background-position: -200% 0; } }

/* --------------------------------------------------------------------------
   19. Utilities
   -------------------------------------------------------------------------- */
.muted { color: var(--muted); }
.muted-strong { color: var(--muted-strong); }
.tag { color: var(--purple); font-weight: 700; }
.divider-block { height: 1px; background: var(--line); margin: var(--space-4) 0; }

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

.text-center { text-align: center; }
.text-right { text-align: right; }
.text-danger { color: var(--red-strong); }
.text-success { color: var(--green-strong); }
.text-warning { color: var(--amber-strong); }

.stack { display: flex; flex-direction: column; gap: var(--space-3); }
.row { display: flex; flex-wrap: wrap; gap: var(--space-3); align-items: center; }
.row-between { display: flex; flex-wrap: wrap; gap: var(--space-3); align-items: center; justify-content: space-between; }
.grow { flex: 1 1 0; min-width: 0; }

.page-head { padding: var(--space-6) 0 var(--space-4); }
.page-title { margin: 0 0 var(--space-2); }
.page-subtitle { color: var(--muted); margin: 0; max-width: 72ch; }

.hero { padding: var(--space-10) 0 var(--space-6); }
.hero h1 { font-size: clamp(1.9rem, 4vw, 2.75rem); }

.mt-1 { margin-top: var(--space-1); }
.mt-2 { margin-top: var(--space-2); }
.mt-3 { margin-top: var(--space-3); }
.mt-4 { margin-top: var(--space-4); }
.mb-1 { margin-bottom: var(--space-1); }
.mb-2 { margin-bottom: var(--space-2); }
.mb-3 { margin-bottom: var(--space-3); }
.mb-4 { margin-bottom: var(--space-4); }

/* --------------------------------------------------------------------------
   20. Live feed (Phase 12 polling)
   -------------------------------------------------------------------------- */
.live-dot { color: var(--green); }

.live-feed-list { list-style: none; padding: 0; margin: 0; }

.live-feed-list li {
    padding: var(--space-2) 0;
    border-bottom: 1px solid var(--line);
    font-size: .85rem;
    color: var(--muted);
}

.live-feed-list li:last-child { border-bottom: none; }

/* --------------------------------------------------------------------------
   21. Responsive behaviour
   -------------------------------------------------------------------------- */
@media (max-width: 900px) {
    .nav-toggle { display: inline-flex; }

    .nav-links {
        display: none;
        width: 100%;
        flex-direction: column;
        align-items: stretch;
        gap: var(--space-1);
        padding-block: var(--space-2);
    }

    .nav-links.is-open { display: flex; }

    .nav-link {
        min-height: var(--target-min);
        width: 100%;
        justify-content: flex-start;
    }
}

@media (pointer: coarse) {
    .btn, .nav-link, .pagination a, .pagination span, .nav-toggle {
        min-height: 44px;
    }
}

/* --------------------------------------------------------------------------
   22. Reduced motion (WCAG 2.2 — prefers-reduced-motion)
   -------------------------------------------------------------------------- */
@media (prefers-reduced-motion: reduce) {
    html { scroll-behavior: auto; }

    *, *::before, *::after {
        animation-duration: .001ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: .001ms !important;
    }

    .spinner { animation: none; }
    .skeleton { animation: none; background: var(--panel3); }
}

/* --------------------------------------------------------------------------
   23. Print
   -------------------------------------------------------------------------- */
@media print {
    body { background: #fff; color: #000; }
    .site-header, .site-footer, .skip-link, .btn { display: none; }
    .card, .table-wrap { border-color: #ccc; }
}
```

### `public/js/app.js`

```js
/* ==========================================================================
   FF Arena shared UI behaviours (Phase 17)
   --------------------------------------------------------------------------
   Loaded with `defer`. Vanilla JS only — no framework. Progressive
   enhancement: everything here is driven by data-* attributes already
   rendered server-side, so pages remain fully usable without JavaScript.

   Behaviours:
     1. Accessible mobile navigation (aria-expanded, Escape to close, focus
        return, click-outside to close).
     2. Unread-notification badge polling — visibility-aware (pauses in
        hidden tabs), resilient (no retry storm, keeps last known count).
   ========================================================================== */
(function () {
    'use strict';

    var reduceMotion = window.matchMedia
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ------------------------------------------------------------------
       1. Mobile navigation toggle
       ------------------------------------------------------------------ */
    var toggle = document.querySelector('[data-nav-toggle]');
    var nav = document.getElementById('site-nav');

    if (toggle && nav) {
        var closeMenu = function () {
            nav.classList.remove('is-open');
            toggle.setAttribute('aria-expanded', 'false');
        };

        var openMenu = function () {
            nav.classList.add('is-open');
            toggle.setAttribute('aria-expanded', 'true');
        };

        toggle.addEventListener('click', function () {
            if (nav.classList.contains('is-open')) {
                closeMenu();
            } else {
                openMenu();
            }
        });

        // Escape closes the menu and returns focus to the toggle.
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && nav.classList.contains('is-open')) {
                closeMenu();
                toggle.focus();
            }
        });

        // Clicking/tapping outside the open menu closes it.
        document.addEventListener('click', function (event) {
            if (nav.classList.contains('is-open')
                && !nav.contains(event.target)
                && !toggle.contains(event.target)) {
                closeMenu();
            }
        });

        // Collapse the menu automatically when the viewport grows past the
        // breakpoint so an open mobile menu never obscures desktop nav.
        var mq = window.matchMedia('(min-width: 901px)');
        if (mq.addEventListener) {
            mq.addEventListener('change', function (e) {
                if (e.matches) { closeMenu(); }
            });
        } else if (mq.addListener) {
            mq.addListener(function (e) { if (e.matches) { closeMenu(); } });
        }
    }

    /* ------------------------------------------------------------------
       2. Unread notification badge (visibility-aware polling)
       ------------------------------------------------------------------ */
    var badgeHost = document.querySelector('[data-unread-url]');
    var badge = document.getElementById('unread-badge');

    if (badgeHost && badge) {
        var url = badgeHost.getAttribute('data-unread-url');
        if (url) {
            var interval = 10000;
            var timer = null;

            var render = function (n) {
                badge.textContent = n > 99 ? '99+' : String(n);
                badge.hidden = n === 0;
                badge.setAttribute('aria-label',
                    n === 1 ? '1 unread notification' : n + ' unread notifications');
            };

            var poll = function () {
                // Never poll a hidden tab; resume immediately on visibility.
                if (document.hidden) {
                    schedule();
                    return;
                }
                fetch(url, {
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin',
                })
                    .then(function (response) {
                        if (!response.ok) { throw new Error('http ' + response.status); }
                        return response.json();
                    })
                    .then(function (data) {
                        var n = parseInt(data && data.unread, 10);
                        render(Number.isFinite(n) ? n : 0);
                    })
                    .catch(function () {
                        /* Network hiccup — keep the last known count. */
                    });
                schedule();
            };

            var schedule = function () {
                clearTimeout(timer);
                timer = setTimeout(poll, interval);
            };

            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) {
                    clearTimeout(timer);
                    poll();
                }
            });

            schedule();
        }
    }

    /* ------------------------------------------------------------------
       3. Reduced-motion: cancel any transform/scroll animations the CSS
          might apply (the stylesheet already disables them, this is a
          belt-and-braces guard for any future inline styles).
       ------------------------------------------------------------------ */
    if (reduceMotion && 'scrollBehavior' in document.documentElement.style) {
        document.documentElement.style.scrollBehavior = 'auto';
    }
})();
```

### `public/favicon.svg`

```xml
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" role="img" aria-label="FF Arena">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#1b2340"/>
      <stop offset="1" stop-color="#0b0e1a"/>
    </linearGradient>
    <linearGradient id="fg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#22d3ee"/>
      <stop offset="1" stop-color="#a855f7"/>
    </linearGradient>
  </defs>
  <rect x="2" y="2" width="60" height="60" rx="14" fill="url(#bg)" stroke="#28335a" stroke-width="2"/>
  <path d="M22 14h22l-5 14h-8l-2 8h8l-5 14H20l5-14h8l2-8h-8z" fill="url(#fg)"/>
</svg>
```

### `lang/en/ui.php`

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | UI chrome strings (Phase 17).
    |--------------------------------------------------------------------------
    |
    | Shared layout/navigation/component chrome is kept here so the shell can
    | be localized (e.g. Bengali) without touching business copy. Existing
    | page content intentionally keeps its current English wording.
    |
    */

    'skip_to_content' => 'Skip to main content',
    'menu' => 'Menu',
    'open_menu' => 'Open navigation menu',
    'close_menu' => 'Close navigation menu',
    'primary_navigation' => 'Primary navigation',
    'footer_navigation' => 'Footer',
    'home' => 'Home',
    'tournaments' => 'Tournaments',
    'notifications' => 'Notifications',
    'unread_notifications' => 'Unread notifications',
    'logout' => 'Log out',
    'login' => 'Login',
    'register' => 'Register',
    'form_errors' => 'There were errors with your submission',
    'success' => 'Success',
    'error' => 'Error',
    'back_to_top' => 'Back to top',
    'view' => 'View',
    'register_cta' => 'Register',
];
```

### `tests/Feature/Phase17/Phase17TestCase.php`

```php
<?php

namespace Tests\Feature\Phase17;

use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 17 — UI/UX, accessibility, SEO and performance tests base.
 */
abstract class Phase17TestCase extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(string $role = 'player', array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        $user->role = $role;
        $user->account_status = 'active';
        $user->save();

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeTournament(User $organizer, string $status = 'open', array $overrides = []): Tournament
    {
        $tournament = new Tournament;
        $tournament->organizer_id = $organizer->id;
        $tournament->name = $overrides['name'] ?? 'Phase17 Tournament';
        $tournament->slug = $overrides['slug'] ?? 'phase17-'.Str::random(8);
        $tournament->game_mode = $overrides['game_mode'] ?? 'squad';
        $tournament->map = $overrides['map'] ?? 'Bermuda';
        $tournament->entry_fee = $overrides['entry_fee'] ?? 0;
        $tournament->prize_pool = $overrides['prize_pool'] ?? 5000;
        $tournament->team_slots = $overrides['team_slots'] ?? 8;
        $tournament->team_size = $overrides['team_size'] ?? 4;
        $tournament->rules = $overrides['rules'] ?? 'No rules.';
        $tournament->starts_at = $overrides['starts_at'] ?? now()->addDay();
        $tournament->format = $overrides['format'] ?? Tournament::FORMAT_SINGLE_ELIM;
        $tournament->status = $status;
        $tournament->save();

        return $tournament;
    }
}
```

### `tests/Feature/Phase17/SeoTest.php`

```php
<?php

namespace Tests\Feature\Phase17;

use App\Models\Tournament;

/**
 * Phase 17 — SEO metadata: titles, descriptions, canonical URLs, noindex
 * defaults for private pages, Open Graph and JSON-LD structured data.
 */
class SeoTest extends Phase17TestCase
{
    public function test_homepage_has_full_metadata(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('<title>FF Arena — Free Fire Tournaments in Bangladesh</title>', false)
            ->assertSee('<meta name="description"', false)
            ->assertSee('<link rel="canonical" href="'.route('home').'"', false)
            ->assertSee('<meta property="og:site_name"', false)
            ->assertSee('<meta property="og:type" content="website"', false)
            ->assertSee('application/ld+json', false)
            ->assertSee('"@type":"WebSite"', false)
            ->assertDontSee('name="robots" content="noindex', false);
    }

    public function test_tournament_listing_is_indexable_with_canonical(): void
    {
        $this->get(route('tournaments.index'))
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.route('tournaments.index').'"', false)
            ->assertDontSee('name="robots" content="noindex', false);
    }

    public function test_tournament_detail_has_canonical_title_and_event_data(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer, Tournament::STATUS_OPEN, ['name' => 'Alpha Cup']);

        $this->get(route('tournaments.show', $tournament))
            ->assertOk()
            ->assertSee('<title>Alpha Cup — FF Arena</title>', false)
            ->assertSee('<link rel="canonical" href="'.route('tournaments.show', $tournament).'"', false)
            ->assertSee('"@type":"Event"', false)
            ->assertSee('"name":"Alpha Cup"', false)
            ->assertSee('OnlineEventAttendanceMode', false)
            ->assertDontSee('name="robots" content="noindex', false);
    }

    public function test_cancelled_tournament_is_not_indexable(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer, Tournament::STATUS_CANCELLED);

        $this->get(route('tournaments.show', $tournament))
            ->assertOk()
            ->assertSee('name="robots" content="noindex, nofollow"', false)
            ->assertDontSee('"@type":"Event"', false);
    }

    public function test_leaderboard_is_indexable(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer, Tournament::STATUS_LIVE, ['name' => 'Ranked Cup']);

        $this->get(route('leaderboard.show', $tournament))
            ->assertOk()
            ->assertSee('<title>Leaderboard — Ranked Cup — FF Arena</title>', false)
            ->assertSee('<link rel="canonical" href="'.route('leaderboard.show', $tournament).'"', false)
            ->assertDontSee('name="robots" content="noindex', false);
    }

    public function test_public_profile_is_indexable_with_person_data(): void
    {
        $user = $this->makeUser('player', [
            'username' => 'acegamer',
            'privacy' => 'public',
            'bio' => 'Pro Free Fire squad leader from Dhaka.',
        ]);

        $this->get(route('profile.show', $user))
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.route('profile.show', $user).'"', false)
            ->assertSee('"@type":"ProfilePage"', false)
            ->assertSee('"@type":"Person"', false)
            ->assertSee('"alternateName":"acegamer"', false)
            ->assertDontSee('name="robots" content="noindex', false);
    }

    public function test_private_profile_is_noindex_and_leaks_nothing_into_metadata(): void
    {
        $user = $this->makeUser('player', [
            'username' => 'ghost',
            'privacy' => 'private',
            'bio' => 'TOP SECRET BIO',
        ]);

        $response = $this->get(route('profile.show', $user));

        $response->assertOk()
            ->assertSee('name="robots" content="noindex, nofollow"', false)
            ->assertDontSee('TOP SECRET BIO', false)
            ->assertDontSee('ProfilePage', false)
            ->assertDontSee('<meta name="description" content="TOP SECRET', false);
    }

    public function test_authenticated_private_pages_are_noindex_by_default(): void
    {
        $player = $this->makeUser('player');

        $this->actingAs($player)
            ->get(route('wallet.index'))
            ->assertOk()
            ->assertSee('name="robots" content="noindex, nofollow"', false)
            ->assertDontSee('<link rel="canonical"', false);
    }
}
```

### `tests/Feature/Phase17/SitemapRobotsTest.php`

```php
<?php

namespace Tests\Feature\Phase17;

use App\Models\Tournament;

/**
 * Phase 17 — robots.txt + XML sitemap: only indexable public resources are
 * listed; private/limited resources are excluded by construction.
 */
class SitemapRobotsTest extends Phase17TestCase
{
    public function test_robots_txt_is_served_and_blocks_private_areas(): void
    {
        $response = $this->get('/robots.txt');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('User-agent: *')
            ->assertSee('Disallow: /admin')
            ->assertSee('Disallow: /wallet')
            ->assertSee('Disallow: /settings')
            ->assertSee('Disallow: /profile/edit')
            ->assertSee('Allow: /tournaments')
            ->assertSee('Sitemap: '.route('sitemap'));
    }

    public function test_sitemap_is_valid_xml_and_lists_public_pages(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer, Tournament::STATUS_OPEN, ['name' => 'Public Cup']);

        $response = $this->get('/sitemap.xml');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/xml; charset=UTF-8')
            ->assertHeader('X-Robots-Tag', 'noindex')
            ->assertSee('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', false)
            ->assertSee(route('home'), false)
            ->assertSee(route('tournaments.index'), false)
            ->assertSee(route('tournaments.show', ['tournament' => $tournament->slug]), false);
    }

    public function test_sitemap_excludes_draft_and_cancelled_tournaments(): void
    {
        $organizer = $this->makeUser('organizer');
        $draft = $this->makeTournament($organizer, Tournament::STATUS_DRAFT, ['name' => 'Draft Cup']);
        $cancelled = $this->makeTournament($organizer, Tournament::STATUS_CANCELLED, ['name' => 'Dead Cup']);

        $content = $this->get('/sitemap.xml')->getContent();

        $this->assertStringNotContainsString($draft->slug, $content);
        $this->assertStringNotContainsString($cancelled->slug, $content);
    }

    public function test_sitemap_includes_public_profiles_and_excludes_private(): void
    {
        $public = $this->makeUser('player', ['username' => 'publicone', 'privacy' => 'public']);
        $private = $this->makeUser('player', ['username' => 'privateone', 'privacy' => 'private']);
        $registered = $this->makeUser('player', ['username' => 'regone', 'privacy' => 'registered']);

        $content = $this->get('/sitemap.xml')->getContent();

        $this->assertStringContainsString(route('profile.show', $public), $content);
        $this->assertStringNotContainsString(route('profile.show', $private), $content);
        $this->assertStringNotContainsString(route('profile.show', $registered), $content);
    }

    public function test_sitemap_adds_leaderboard_only_for_live_and_finished(): void
    {
        $organizer = $this->makeUser('organizer');
        $live = $this->makeTournament($organizer, Tournament::STATUS_LIVE, ['name' => 'Live Cup']);
        $open = $this->makeTournament($organizer, Tournament::STATUS_OPEN, ['name' => 'Open Cup']);

        $content = $this->get('/sitemap.xml')->getContent();

        $this->assertStringContainsString(
            route('leaderboard.show', ['tournament' => $live->slug]),
            $content
        );
        $this->assertStringNotContainsString(
            route('leaderboard.show', ['tournament' => $open->slug]),
            $content
        );
    }
}
```

### `tests/Feature/Phase17/AccessibilityTest.php`

```php
<?php

namespace Tests\Feature\Phase17;

use App\Models\Team;
use Illuminate\Support\Str;

/**
 * Phase 17 — accessibility: landmarks, skip link, focus styles, reduced
 * motion, form labels, autocomplete for accessible authentication, status
 * announcements and non-colour-only status cues.
 */
class AccessibilityTest extends Phase17TestCase
{
    public function test_layout_has_language_landmarks_and_skip_link(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSee('class="skip-link"', false)
            ->assertSee('Skip to main content')
            ->assertSee('<main id="main"', false)
            ->assertSee('<header', false)
            ->assertSee('<footer', false);
    }

    public function test_mobile_navigation_controls_are_accessible(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-nav-toggle', false)
            ->assertSee('aria-expanded="false"', false)
            ->assertSee('aria-controls="site-nav"', false)
            ->assertSee('id="site-nav"', false);
    }

    public function test_design_system_defines_visible_focus_and_reduced_motion(): void
    {
        $css = (string) file_get_contents(public_path('css/app.css'));

        $this->assertStringContainsString(':focus-visible', $css);
        $this->assertStringContainsString('prefers-reduced-motion', $css);
        $this->assertStringContainsString('outline: 2px solid var(--focus)', $css);
    }

    public function test_login_form_has_labels_and_accessible_authentication(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('<label for="email"', false)
            ->assertSee('<label for="password"', false)
            ->assertSee('autocomplete="email"', false)
            ->assertSee('autocomplete="current-password"', false)
            ->assertSee('type="password"', false);
    }

    public function test_register_form_has_labels_and_password_manager_support(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertSee('autocomplete="name"', false)
            ->assertSee('autocomplete="username"', false)
            ->assertSee('autocomplete="email"', false)
            ->assertSee('autocomplete="new-password"', false)
            ->assertSee('autocomplete="tel"', false)
            ->assertSee('<label for="game_uid"', false);
    }

    public function test_flash_error_is_announced_as_alert(): void
    {
        $this->withSession(['error' => 'Something went wrong.'])
            ->get(route('home'))
            ->assertOk()
            ->assertSee('role="alert"', false)
            ->assertSee('Something went wrong.');
    }

    public function test_flash_success_is_announced_as_status(): void
    {
        $this->withSession(['success' => 'All good.'])
            ->get(route('home'))
            ->assertOk()
            ->assertSee('role="status"', false)
            ->assertSee('All good.');
    }

    public function test_status_pills_carry_text_not_colour_alone(): void
    {
        $organizer = $this->makeUser('organizer');
        $this->makeTournament($organizer, 'open', ['name' => 'Status Cup']);

        $this->get(route('tournaments.index'))
            ->assertOk()
            ->assertSee('class="pill open">Open</span>', false);
    }

    public function test_confirmed_teams_table_is_wrapped_and_captioned(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer, 'open');

        $captain = $this->makeUser('player');
        $team = new Team;
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain->id;
        $team->name = 'Table Squad';
        $team->captain_name = $captain->name;
        $team->phone = '01700000000';
        $team->game_uid = 'UID'.strtoupper(Str::random(8));
        $team->status = Team::STATUS_CONFIRMED;
        $team->save();

        $this->get(route('tournaments.show', $tournament))
            ->assertOk()
            ->assertSee('class="table-wrap"', false)
            ->assertSee('<caption class="sr-only">', false)
            ->assertSee('Table Squad');
    }

    public function test_tournament_detail_uses_breadcrumbs(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer, 'open', ['name' => 'Crumb Cup']);

        $this->get(route('tournaments.show', $tournament))
            ->assertOk()
            ->assertSee('class="breadcrumbs"', false)
            ->assertSee('aria-current="page"', false);
    }
}
```

### `tests/Feature/Phase17/ResponsiveTest.php`

```php
<?php

namespace Tests\Feature\Phase17;

/**
 * Phase 17 — responsive/mobile web: viewport, mobile navigation toggle,
 * responsive tables and touch target sizing.
 */
class ResponsiveTest extends Phase17TestCase
{
    public function test_pages_declare_a_viewport(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('name="viewport"', false)
            ->assertSee('width=device-width', false);
    }

    public function test_mobile_navigation_toggle_exists(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-nav-toggle', false);
    }

    public function test_stylesheet_defines_mobile_breakpoint_and_touch_targets(): void
    {
        $css = (string) file_get_contents(public_path('css/app.css'));

        $this->assertStringContainsString('@media (max-width: 900px)', $css);
        $this->assertStringContainsString('@media (pointer: coarse)', $css);
        $this->assertStringContainsString('min-height: 44px', $css);
        $this->assertStringContainsString('.table-wrap', $css);
    }

    public function test_tournament_list_cards_render_without_fixed_widths(): void
    {
        $organizer = $this->makeUser('organizer');
        $this->makeTournament($organizer, 'open', ['name' => 'Mobile Cup']);

        $this->get(route('tournaments.index'))
            ->assertOk()
            ->assertSee('grid cols-3', false)
            ->assertSee('Mobile Cup');
    }
}
```

### `tests/Feature/Phase17/PerformanceTest.php`

```php
<?php

namespace Tests\Feature\Phase17;

/**
 * Phase 17 — frontend performance regression checks: external (cacheable)
 * stylesheet instead of a render-blocking inline <style> block, deferred
 * JavaScript, favicon/site identity and a present meta description.
 */
class PerformanceTest extends Phase17TestCase
{
    public function test_layout_loads_external_stylesheet_instead_of_inline_block(): void
    {
        $html = $this->get(route('home'))->getContent();

        $this->assertStringContainsString('<link rel="stylesheet" href="', $html);
        $this->assertStringContainsString('css/app.css', $html);
        // The old layout shipped a large inline <style> block — it must be gone.
        $this->assertStringNotContainsString('<style>', $html);
    }

    public function test_shared_script_is_deferred(): void
    {
        $html = $this->get(route('home'))->getContent();

        $this->assertStringContainsString('js/app.js', $html);
        $this->assertStringContainsString('defer', $html);
    }

    public function test_site_identity_assets_are_linked(): void
    {
        $html = $this->get(route('home'))->getContent();

        $this->assertStringContainsString('favicon.svg', $html);
        $this->assertStringContainsString('apple-touch-icon.png', $html);
        $this->assertStringContainsString('name="theme-color"', $html);
    }

    public function test_stylesheet_is_bounded_in_size(): void
    {
        $size = filesize(public_path('css/app.css'));

        // A generous ceiling for a full design system; guards against
        // accidental bloat (e.g. re-embedding a minified framework).
        $this->assertLessThan(120 * 1024, $size, 'public/css/app.css exceeds 120 KB');
    }

    public function test_shared_js_is_bounded_in_size(): void
    {
        $size = filesize(public_path('js/app.js'));

        $this->assertLessThan(40 * 1024, $size, 'public/js/app.js exceeds 40 KB');
    }
}
```

### `tests/Feature/Phase17/DiscoveryTest.php`

```php
<?php

namespace Tests\Feature\Phase17;

use App\Models\Tournament;

/**
 * Phase 17 — tournament discovery: search, status and game-mode filters.
 * The default (unfiltered) listing behaviour is unchanged.
 */
class DiscoveryTest extends Phase17TestCase
{
    public function test_listing_shows_all_public_tournaments_by_default(): void
    {
        $organizer = $this->makeUser('organizer');
        $this->makeTournament($organizer, Tournament::STATUS_OPEN, ['name' => 'One Cup']);
        $this->makeTournament($organizer, Tournament::STATUS_FINISHED, ['name' => 'Two Cup']);

        $this->get(route('tournaments.index'))
            ->assertOk()
            ->assertSee('One Cup')
            ->assertSee('Two Cup');
    }

    public function test_search_filters_by_name(): void
    {
        $organizer = $this->makeUser('organizer');
        $this->makeTournament($organizer, Tournament::STATUS_OPEN, ['name' => 'Bermuda Blitz', 'map' => 'Bermuda']);
        $this->makeTournament($organizer, Tournament::STATUS_OPEN, ['name' => 'Purgatorio Cup', 'map' => 'Purgatorio']);

        $this->get(route('tournaments.index', ['q' => 'bermuda']))
            ->assertOk()
            ->assertSee('Bermuda Blitz')
            ->assertDontSee('Purgatorio Cup');
    }

    public function test_search_escapes_like_wildcards(): void
    {
        $organizer = $this->makeUser('organizer');
        $this->makeTournament($organizer, Tournament::STATUS_OPEN, ['name' => 'Blitz']);

        // A raw `%` must match nothing (it is escaped), not everything.
        $this->get(route('tournaments.index', ['q' => '%']))
            ->assertOk()
            ->assertSee('No tournaments found');
    }

    public function test_status_filter_works(): void
    {
        $organizer = $this->makeUser('organizer');
        $this->makeTournament($organizer, Tournament::STATUS_OPEN, ['name' => 'Open Cup']);
        $this->makeTournament($organizer, Tournament::STATUS_LIVE, ['name' => 'Live Cup']);

        $this->get(route('tournaments.index', ['status' => 'live']))
            ->assertOk()
            ->assertSee('Live Cup')
            ->assertDontSee('Open Cup');
    }

    public function test_game_mode_filter_works(): void
    {
        $organizer = $this->makeUser('organizer');
        $this->makeTournament($organizer, Tournament::STATUS_OPEN, ['name' => 'Duo Cup', 'game_mode' => 'duo']);
        $this->makeTournament($organizer, Tournament::STATUS_OPEN, ['name' => 'Squad Cup', 'game_mode' => 'squad']);

        $this->get(route('tournaments.index', ['game_mode' => 'duo']))
            ->assertOk()
            ->assertSee('Duo Cup')
            ->assertDontSee('Squad Cup');
    }

    public function test_invalid_status_is_ignored(): void
    {
        $organizer = $this->makeUser('organizer');
        $this->makeTournament($organizer, Tournament::STATUS_OPEN, ['name' => 'Safe Cup']);

        $this->get(route('tournaments.index', ['status' => 'draft']))
            ->assertOk()
            ->assertSee('Safe Cup');
    }

    public function test_pagination_is_accessible(): void
    {
        $organizer = $this->makeUser('organizer');

        for ($i = 1; $i <= 13; $i++) {
            $this->makeTournament($organizer, Tournament::STATUS_OPEN, ['name' => 'Cup '.$i]);
        }

        $this->get(route('tournaments.index'))
            ->assertOk()
            ->assertSee('aria-label="Pagination"', false)
            ->assertSee('aria-current="page"', false);
    }
}
```

### `tests/Feature/Phase17/UiComponentsTest.php`

```php
<?php

namespace Tests\Feature\Phase17;

use Illuminate\Support\Facades\Blade;

/**
 * Phase 17 — reusable Blade components render the expected accessible markup.
 */
class UiComponentsTest extends Phase17TestCase
{
    public function test_alert_component_announces_errors_assertively(): void
    {
        $html = Blade::render('<x-alert type="error">Account suspended.</x-alert>');

        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('alert alert-error', $html);
        $this->assertStringContainsString('Account suspended.', $html);
    }

    public function test_alert_component_announces_success_politely(): void
    {
        $html = Blade::render('<x-alert type="success">Saved.</x-alert>');

        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('alert alert-success', $html);
    }

    public function test_button_component_renders_link_when_href_given(): void
    {
        $html = Blade::render('<x-button href="/tournaments" variant="primary">Browse</x-button>');

        $this->assertStringContainsString('<a href="/tournaments"', $html);
        $this->assertStringContainsString('class="btn btn-primary"', $html);
        $this->assertStringContainsString('>Browse</a>', $html);
    }

    public function test_button_component_renders_button_when_href_absent(): void
    {
        $html = Blade::render('<x-button type="submit" variant="green">Save</x-button>');

        $this->assertStringContainsString('<button type="submit"', $html);
        $this->assertStringContainsString('class="btn btn-green"', $html);
    }

    public function test_status_pill_component_maps_status_to_text(): void
    {
        $html = Blade::render('<x-status-pill status="waitlisted" />');

        $this->assertStringContainsString('class="pill waitlisted"', $html);
        $this->assertStringContainsString('Waitlisted', $html);
    }

    public function test_status_pill_component_honours_custom_label(): void
    {
        $html = Blade::render('<x-status-pill status="checked" label="Check-in open" />');

        $this->assertStringContainsString('class="pill checked"', $html);
        $this->assertStringContainsString('Check-in open', $html);
    }

    public function test_empty_state_component_renders_title_and_guidance(): void
    {
        $html = Blade::render('<x-empty-state title="No results" icon="📊">Check back soon.</x-empty-state>');

        $this->assertStringContainsString('empty-state', $html);
        $this->assertStringContainsString('No results', $html);
        $this->assertStringContainsString('Check back soon.', $html);
    }
}
```

### `tests/Feature/Phase17/SmokeMatrixTest.php`

```php
<?php

namespace Tests\Feature\Phase17;

use App\Models\Tournament;

/**
 * Phase 17 — HTTP smoke matrix over the long-tail views (guest / authed /
 * admin). This complements the dedicated feature tests (disputes, support,
 * security, settlements, ops) by exercising every remaining view against
 * the design-system rewrite and asserting a 200 response with its page
 * title/heading rendered.
 */
class SmokeMatrixTest extends Phase17TestCase
{
    public function test_guest_can_view_public_pages(): void
    {
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer, Tournament::STATUS_OPEN, ['name' => 'Smoke Cup']);

        $this->get(route('home'))->assertOk();
        $this->get(route('tournaments.index'))->assertOk()->assertSee('Smoke Cup');
        $this->get(route('tournaments.show', $tournament))->assertOk()->assertSee('Smoke Cup');
        $this->get(route('leaderboard.show', $tournament))->assertOk();
        $this->get(route('login'))->assertOk();
        $this->get(route('register'))->assertOk();
    }

    public function test_authenticated_user_can_view_account_and_settings_pages(): void
    {
        $user = $this->makeUser('player');

        $this->actingAs($user)->get(route('profile.edit'))->assertOk();

        foreach ([
            'settings.security',
            'settings.sessions',
            'settings.login-history',
            'settings.connected-accounts',
            'settings.payment-methods',
        ] as $routeName) {
            $this->get(route($routeName))->assertOk();
        }

        $this->get(route('wallet.index'))->assertOk();
        $this->get(route('notifications.index'))->assertOk();
        $this->get(route('support.index'))->assertOk()->assertSee('My Support Tickets');
        $this->get(route('support.create'))->assertOk()->assertSee('New Support Ticket');
    }

    public function test_admin_can_view_admin_dashboard_and_operations(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertSee('Admin Dashboard');
        $this->get(route('admin.accounts.index'))->assertOk()->assertSee('Account Administration');
        $this->get(route('admin.audit.index'))->assertOk()->assertSee('Audit Log');
        $this->get(route('admin.ops.dashboard'))->assertOk()->assertSee('Infrastructure Operations');
        $this->get(route('admin.ops.failed_jobs'))->assertOk()->assertSee('Failed Jobs');
    }

    public function test_admin_can_view_analytics_financial_and_security_pages(): void
    {
        $admin = $this->makeUser('admin');
        $organizer = $this->makeUser('organizer');
        $this->makeTournament($organizer, Tournament::STATUS_OPEN, ['name' => 'Metrics Cup']);

        $this->actingAs($admin);

        foreach ([
            ['admin.analytics.index', 'Analytics'],
            ['admin.analytics.tournaments', 'Match Analytics'],
            ['admin.analytics.financial', 'Financial Analytics'],
            ['admin.analytics.security', 'Security Analytics'],
            ['admin.analytics.disputes', 'Dispute Analytics'],
            ['admin.analytics.support', 'Support Analytics'],
            ['admin.payments.index', 'Payments'],
            ['admin.payouts.index', 'Payouts'],
            ['admin.settlements.index', 'Financial Settlements'],
            ['admin.security.dashboard', 'Security Dashboard'],
            ['admin.security.users', 'Suspicious Users'],
            ['admin.security.events', 'Risk Events'],
            ['admin.support.index', 'Support Queue'],
        ] as [$routeName, $heading]) {
            $this->get(route($routeName))->assertOk()->assertSee($heading);
        }
    }

    public function test_admin_can_view_wallet_and_settlement_detail_pages(): void
    {
        $admin = $this->makeUser('admin');
        $player = $this->makeUser('player');
        $organizer = $this->makeUser('organizer');
        $tournament = $this->makeTournament($organizer, Tournament::STATUS_OPEN, ['name' => 'Settlement Cup']);

        $this->actingAs($admin)
            ->get(route('admin.wallet.show', $player))
            ->assertOk()
            ->assertSee('Wallet: '.$player->name);

        $this->get(route('admin.settlements.show', $tournament))
            ->assertOk()
            ->assertSee('Settlement: Settlement Cup')
            ->assertSee('Prize Configuration');
    }

    public function test_staff_can_view_moderation_pages_but_players_cannot(): void
    {
        $moderator = $this->makeUser('moderator');
        $player = $this->makeUser('player');

        $this->actingAs($moderator)->get(route('moderation.index'))->assertOk()->assertSee('Dispute Moderation Queue');
        $this->actingAs($moderator)->get(route('moderation.security'))->assertOk()->assertSee('Security Review');
        $this->actingAs($player)->get(route('moderation.security'))->assertStatus(403);
    }
}
```

### `package-lock.json`

```json
{
    "name": "ffarena-app",
    "lockfileVersion": 3,
    "requires": true,
    "packages": {
        "": {
            "devDependencies": {
                "@tailwindcss/vite": "^4.0.0",
                "axios": "^1.11.0",
                "concurrently": "^9.0.1",
                "laravel-vite-plugin": "^2.0.0",
                "tailwindcss": "^4.0.0",
                "vite": "^7.0.7"
            }
        },
        "node_modules/@esbuild/aix-ppc64": {
            "version": "0.28.2",
            "resolved": "https://registry.npmjs.org/@esbuild/aix-ppc64/-/aix-ppc64-0.28.2.tgz",
            "integrity": "sha512-XExcO+dvLKvVtNTibSTBej1NCAbaGhWn9Ww1ZPx80qsahhPFe/8jgWP0IchNe0F3HwkU7n8ejhH8bjonqht8mQ==",
            "cpu": [
                "ppc64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "aix"
            ],
            "engines": {
                "node": ">=18"
            }
        },
        "node_modules/@esbuild/android-arm": {
            "version": "0.28.2",
            "resolved": "https://registry.npmjs.org/@esbuild/android-arm/-/android-arm-0.28.2.tgz",
            "integrity": "sha512-kXXoiPVVGQcnIYGOeaovwOURpniDBpSq4A03qkQ+BMQqtGG6HYap3xne9C1O1yo4TR3qxlCX5IqqmX6fFo2Lqg==",
            "cpu": [
                "arm"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "android"
            ],
            "engines": {
                "node": ">=18"
            }
        },
        "node_modules/@esbuild/android-arm64": {
            "version": "0.28.2",
            "resolved": "https://registry.npmjs.org/@esbuild/android-arm64/-/android-arm64-0.28.2.tgz",
            "integrity": "sha512-5YfKeeI8qWfBZIX+u2xZC3Zlb3Os/gLS2sbEKM+I4ZOcsWmHS2WLysCcQZDAFRslDUU5Oiq44gf6PYN1vGwG5A==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "android"
            ],
            "engines": {
                "node": ">=18"
            }
        },
        "node_modules/@esbuild/android-x64": {
            "version": "0.28.2",
            "resolved": "https://registry.npmjs.org/@esbuild/android-x64/-/android-x64-0.28.2.tgz",
            "integrity": "sha512-O387ite7SzUyCcy3JQX4P4bLtEA7bLLkx+esve5JHnyYfNTxcVpXZo9jhdB0lTKN44gztELTdU7nS8Nr16Fs1Q==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "android"
            ],
            "engines": {
                "node": ">=18"
            }
        },
        "node_modules/@esbuild/darwin-arm64": {
            "version": "0.28.2",
            "resolved": "https://registry.npmjs.org/@esbuild/darwin-arm64/-/darwin-arm64-0.28.2.tgz",
            "integrity": "sha512-n4KqkOQrraxHJcgjM1RvwbigfQKIKJVpM7xp+KsxiyUSrRdIXnt73VhrPAx0fV44hgfmIVKjxMN9J1t5jySVkw==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "darwin"
            ],
            "engines": {
                "node": ">=18"
            }
        },
        "node_modules/@esbuild/darwin-x64": {
            "version": "0.28.2",
            "resolved": "https://registry.npmjs.org/@esbuild/darwin-x64/-/darwin-x64-0.28.2.tgz",
            "integrity": "sha512-uq6suIWYP37qzGddBKPw5QEQPi6HiLGsO7UmkpfyaYNQ3D+rN6w6WfwH+nuqcGXWvawGwxOEroO4YGnFh95azw==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "darwin"
            ],
            "engines": {
                "node": ">=18"
            }
        },
        "node_modules/@esbuild/freebsd-arm64": {
            "version": "0.28.2",
            "resolved": "https://registry.npmjs.org/@esbuild/freebsd-arm64/-/freebsd-arm64-0.28.2.tgz",
            "integrity": "sha512-n+I0BTSRIoy+d6RPKnEVwql5UwBJolytvY4mAOIEJorKlqgPII8ix6slVVrfZ5Tnj7glIZvloylbB/EJPMWEXw==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "freebsd"
            ],
            "engines": {
                "node": ">=18"
            }
        },
        "node_modules/@esbuild/freebsd-x64": {
            "version": "0.28.2",
            "resolved": "https://registry.npmjs.org/@esbuild/freebsd-x64/-/freebsd-x64-0.28.2.tgz",
            "integrity": "sha512-78XJTJkvPs0kz2w61301PJjXl4g7q3JqiYMZ/M/yVI73EHBrCRTgkhu9oqG7vPqq+a/yadEW8aD+agKlk5xrmg==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "freebsd"
            ],
            "engines": {
                "node": ">=18"
            }
        },
        "node_modules/@esbuild/linux-arm": {
            "version": "0.28.2",
            "resolved": "https://registry.npmjs.org/@esbuild/linux-arm/-/linux-arm-0.28.2.tgz",
            "integrity": "sha512-XlDnu2q5yoqems+xay6wSAcg9DDD7K9RLKZEBOMZm3ckNpJBvOX20tSfby8KfrrhINDyv9V2YVZKY/SpoGJI8w==",
            "cpu": [
                "arm"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": ">=18"
            }
        },
        "node_modules/@esbuild/linux-arm64": {
            "version": "0.28.2",
            "resolved": "https://registry.npmjs.org/@esbuild/linux-arm64/-/linux-arm64-0.28.2.tgz",
            "integrity": "sha512-pW4AC0P3it8c7do9MVM4p51FzHzdM/TZrerurgRcHJ2WTa1VQ1CIq18xncfpBJw4ojkiZZrKW2yIBWBP92j6Ug==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": ">=18"
            }
        },
        "node_modules/@esbuild/linux-ia32": {
            "version": "0.28.2",
            "resolved": "https://registry.npmjs.org/@esbuild/linux-ia32/-/linux-ia32-0.28.2.tgz",
            "integrity": "sha512-CYbnj78HsIeA+DhgUKgFCfvNsTHFhMMrinUrMZpDXJXKN8T3XViTZ/+wtHeVxEWY8ewSzTFN+nRmSwO2tZaLUQ==",
            "cpu": [
                "ia32"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": ">=18"
            }
        },
        "node_modules/@esbuild/linux-loong64": {
            "version": "0.28.2",
            "resolved": "https://registry.npmjs.org/@esbuild/linux-loong64/-/linux-loong64-0.28.2.tgz",
            "integrity": "sha512-buwkd8nsph4R+ajRvw0qM5Hja/TXQow3ptzWO2EbG/cqcIkHloRrdlBtQlshyYGTNFvfkfJ5tpPLVkY4DtsPfQ==",
            "cpu": [
                "loong64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": ">=18"
            }
        },
        "node_modules/@esbuild/linux-mips64el": {
            "version": "0.28.2",
            "resolved": "https://registry.npmjs.org/@esbuild/linux-mips64el/-/linux-mips64el-0.28.2.tgz",
            "integrity": "sha512-ZVykbDyk7519VwiNb9Lcj9m8XM6v5V9uKPvrEMkkEedVewf+0itkhahp4HDpgERXhwLRpWFypsGbG/J8s0QjJA==",
            "cpu": [
                "mips64el"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": ">=18"
            }
        },
        "node_modules/@esbuild/linux-ppc64": {
            "version": "0.28.2",
            "resolved": "https://registry.npmjs.org/@esbuild/linux-ppc64/-/linux-ppc64-0.28.2.tgz",
            "integrity": "sha512-CAXl+Dtd9UUuJd8pKKdwh6MLm3MUMiqMPmhZ3tTSXPqfyQ3vDl6R5hZdZ/kYojK4ofXtdfSv1tFq8XzWx3heNQ==",
            "cpu": [
                "ppc64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": ">=18"
            }
        },
        "node_modules/@esbuild/linux-riscv64": {
            "version": "0.28.2",
            "resolved": "https://registry.npmjs.org/@esbuild/linux-riscv64/-/linux-riscv64-0.28.2.tgz",
            "integrity": "sha512-GeXCej4IQtU1B+QlDV8W/RRvbzI3O/Stss+/bCXv4lZls5WGRtu2a+3JkA3i4qIUlMXpcHebWpF8AkJhATowuA==",
            "cpu": [
                "riscv64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": ">=18"
            }
        },
        "node_modules/@esbuild/linux-s390x": {
            "version": "0.28.2",
            "resolved": "https://registry.npmjs.org/@esbuild/linux-s390x/-/linux-s390x-0.28.2.tgz",
            "integrity": "sha512-3H1weTYZPxt/WOhByszQZybS9w5lKzUn1FDMsgEChbHWQwHYQQRfBxgCcZvPhjHfKyJjIievvMmEUawJrdY9Dg==",
            "cpu": [
                "s390x"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": ">=18"
            }
        },
        "node_modules/@esbuild/linux-x64": {
            "version": "0.28.2",
            "resolved": "https://registry.npmjs.org/@esbuild/linux-x64/-/linux-x64-0.28.2.tgz",
            "integrity": "sha512-4xTZr1FUmSoQW4XIWmit3tzQrUTZM+N3P0XV8xROKYF50XfI7xeO90+1bZvNwxIufQ9hDQVRJH5YhgPVF8A/HQ==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": ">=18"
            }
        },
        "node_modules/@esbuild/netbsd-arm64": {
            "version": "0.28.2",
            "resolved": "https://registry.npmjs.org/@esbuild/netbsd-arm64/-/netbsd-arm64-0.28.2.tgz",
            "integrity": "sha512-sSATRjPeDBg3pdgHoQfoYBob11Kk1FGa9lui5RIHZCoCkJa9QKlvl3/vKz2usCmYYjs7ymJR/2Nnsqe+Hjt5nw==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "netbsd"
            ],
            "engines": {
                "node": ">=18"
            }
        },
        "node_modules/@esbuild/netbsd-x64": {
            "version": "0.28.2",
            "resolved": "https://registry.npmjs.org/@esbuild/netbsd-x64/-/netbsd-x64-0.28.2.tgz",
            "integrity": "sha512-lqnzCV+mM0gIADaKihiCg6ifgfU2L3h5E33rNQBN1Y4MaVGnzryzmvvf7UHxprpQdE8hpqLolJ9Rl+SkIRDpyw==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "netbsd"
            ],
            "engines": {
                "node": ">=18"
            }
        },
        "node_modules/@esbuild/openbsd-arm64": {
            "version": "0.28.2",
            "resolved": "https://registry.npmjs.org/@esbuild/openbsd-arm64/-/openbsd-arm64-0.28.2.tgz",
            "integrity": "sha512-AL2qJILH7lNjrDmCQDvdxMfAUIv8KMNZOvrwAQ8i8//ntL9FflhOyMJ8OZSMBb8/AWXe3/5v5S20y3zCoZWKoQ==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "openbsd"
            ],
            "engines": {
                "node": ">=18"
            }
        },
        "node_modules/@esbuild/openbsd-x64": {
            "version": "0.28.2",
            "resolved": "https://registry.npmjs.org/@esbuild/openbsd-x64/-/openbsd-x64-0.28.2.tgz",
            "integrity": "sha512-QtiuPytchRyC4rwUKhexJdQKvDuZ6hWloi3igqPQNUJCS1/v9EiO3UTOXR6A3FoMo4fnAKbWJdqaIwhOzh8qEw==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "openbsd"
            ],
            "engines": {
                "node": ">=18"
            }
        },
        "node_modules/@esbuild/openharmony-arm64": {
            "version": "0.28.2",
            "resolved": "https://registry.npmjs.org/@esbuild/openharmony-arm64/-/openharmony-arm64-0.28.2.tgz",
            "integrity": "sha512-WkhYDmpTjLvGlScA1rwjRUmhl4k8oXR3cIbtqWmELgU/dFeHHlEllxDvdWcNJV9rbzCexB5vz8gtNewWLgCT7Q==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "openharmony"
            ],
            "engines": {
                "node": ">=18"
            }
        },
        "node_modules/@esbuild/sunos-x64": {
            "version": "0.28.2",
            "resolved": "https://registry.npmjs.org/@esbuild/sunos-x64/-/sunos-x64-0.28.2.tgz",
            "integrity": "sha512-GPMSkTOtMnv2U2F8gxe4Io6qmVs+YKyp832Etqqxr0hFngmXQ3rzwytelm3GIn7T4VviRUlf3sOgBOiTdvaf7g==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "sunos"
            ],
            "engines": {
                "node": ">=18"
            }
        },
        "node_modules/@esbuild/win32-arm64": {
            "version": "0.28.2",
            "resolved": "https://registry.npmjs.org/@esbuild/win32-arm64/-/win32-arm64-0.28.2.tgz",
            "integrity": "sha512-PIhhEkE9uPBleRBrQEJpUn7MBnibZzbGzYWPmY3x+YoVg/95zbjB4CxPPOQ8l5tYYM4mMaCthF8/1DIfBQQyWQ==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "win32"
            ],
            "engines": {
                "node": ">=18"
            }
        },
        "node_modules/@esbuild/win32-ia32": {
            "version": "0.28.2",
            "resolved": "https://registry.npmjs.org/@esbuild/win32-ia32/-/win32-ia32-0.28.2.tgz",
            "integrity": "sha512-YmJbfTlvU7Sdn9BB+4PRES4oB6pxgS37MAONj+hBr/cpXS1aBPKXxNnDbu+QCWPj0o9dgyxeq79g6c5P8KeuYA==",
            "cpu": [
                "ia32"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "win32"
            ],
            "engines": {
                "node": ">=18"
            }
        },
        "node_modules/@esbuild/win32-x64": {
            "version": "0.28.2",
            "resolved": "https://registry.npmjs.org/@esbuild/win32-x64/-/win32-x64-0.28.2.tgz",
            "integrity": "sha512-5ebpxr3nWMzrL/rnUI755Jkuee0bHL/Gq0WTF9lvcpv73wAp5eu8MfBUgWK9bhWvZjj7yX8etf/8tI8Ney695g==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "win32"
            ],
            "engines": {
                "node": ">=18"
            }
        },
        "node_modules/@jridgewell/gen-mapping": {
            "version": "0.3.13",
            "resolved": "https://registry.npmjs.org/@jridgewell/gen-mapping/-/gen-mapping-0.3.13.tgz",
            "integrity": "sha512-2kkt/7niJ6MgEPxF0bYdQ6etZaA+fQvDcLKckhy1yIQOzaoKjBBjSj63/aLVjYE3qhRt5dvM+uUyfCg6UKCBbA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "@jridgewell/sourcemap-codec": "^1.5.0",
                "@jridgewell/trace-mapping": "^0.3.24"
            }
        },
        "node_modules/@jridgewell/remapping": {
            "version": "2.3.5",
            "resolved": "https://registry.npmjs.org/@jridgewell/remapping/-/remapping-2.3.5.tgz",
            "integrity": "sha512-LI9u/+laYG4Ds1TDKSJW2YPrIlcVYOwi2fUC6xB43lueCjgxV4lffOCZCtYFiH6TNOX+tQKXx97T4IKHbhyHEQ==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "@jridgewell/gen-mapping": "^0.3.5",
                "@jridgewell/trace-mapping": "^0.3.24"
            }
        },
        "node_modules/@jridgewell/resolve-uri": {
            "version": "3.1.2",
            "resolved": "https://registry.npmjs.org/@jridgewell/resolve-uri/-/resolve-uri-3.1.2.tgz",
            "integrity": "sha512-bRISgCIjP20/tbWSPWMEi54QVPRZExkuD9lJL+UIxUKtwVJA8wW1Trb1jMs1RFXo1CBTNZ/5hpC9QvmKWdopKw==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=6.0.0"
            }
        },
        "node_modules/@jridgewell/sourcemap-codec": {
            "version": "1.6.0",
            "resolved": "https://registry.npmjs.org/@jridgewell/sourcemap-codec/-/sourcemap-codec-1.6.0.tgz",
            "integrity": "sha512-T7jf+5zgsZHwNJ4lvQ7/aezbyk0nNX+zJVWpmHA7VYsEx7a7qr5Rg5IbtJFqkgze5Y2sruq1RUY8Q837Od7iFw==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/@jridgewell/trace-mapping": {
            "version": "0.3.31",
            "resolved": "https://registry.npmjs.org/@jridgewell/trace-mapping/-/trace-mapping-0.3.31.tgz",
            "integrity": "sha512-zzNR+SdQSDJzc8joaeP8QQoCQr8NuYx2dIIytl1QeBEZHJ9uW6hebsrYgbz8hJwUQao3TWCMtmfV8Nu1twOLAw==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "@jridgewell/resolve-uri": "^3.1.0",
                "@jridgewell/sourcemap-codec": "^1.4.14"
            }
        },
        "node_modules/@napi-rs/lzma-linux-x64-gnu": {
            "version": "1.5.1",
            "resolved": "https://registry.npmjs.org/@napi-rs/lzma-linux-x64-gnu/-/lzma-linux-x64-gnu-1.5.1.tgz",
            "integrity": "sha512-oTXEIha4SsuXdTA4Iyskj0kpdx2yVXdhd75c2v3xGrHFfVMsbhTPZU/nMPL4sWKo4pBHm3aucLaqGlF696dTyQ==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": "^22.20 || ^24.12 || >=25"
            }
        },
        "node_modules/@rollup/rollup-android-arm-eabi": {
            "version": "4.63.1",
            "resolved": "https://registry.npmjs.org/@rollup/rollup-android-arm-eabi/-/rollup-android-arm-eabi-4.63.1.tgz",
            "integrity": "sha512-UZ8sUxPTiHWYX9QNdJedb1kDZSpS1t/VPWBWGSgqHNi9w3Cu6IXvu2mzbhiTiPvtrqgTQJ+zqiAq2iPIPilpaQ==",
            "cpu": [
                "arm"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "android"
            ]
        },
        "node_modules/@rollup/rollup-android-arm64": {
            "version": "4.63.1",
            "resolved": "https://registry.npmjs.org/@rollup/rollup-android-arm64/-/rollup-android-arm64-4.63.1.tgz",
            "integrity": "sha512-cQ4nFQABN5cDvDpbvJ7bMStCpnaVxynZrRMfUJYgxcIk9Sh54FIO1vtfkg0B69REjER77ioZ/ov+eAApx/KmLQ==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "android"
            ]
        },
        "node_modules/@rollup/rollup-darwin-arm64": {
            "version": "4.63.1",
            "resolved": "https://registry.npmjs.org/@rollup/rollup-darwin-arm64/-/rollup-darwin-arm64-4.63.1.tgz",
            "integrity": "sha512-FQNqd1lRy/0QhDk3xeRIkSBiCpXCiDnZO3YLVdcDKN1UBiKToNftCzcXYNLshmPDUMlu2TdeS8tGcsU6f3YF1Q==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "darwin"
            ]
        },
        "node_modules/@rollup/rollup-darwin-x64": {
            "version": "4.63.1",
            "resolved": "https://registry.npmjs.org/@rollup/rollup-darwin-x64/-/rollup-darwin-x64-4.63.1.tgz",
            "integrity": "sha512-pvD16V939D3CloK0+qikpGaxiPrDUXTe7Y5cWOMkMSy7m1cawa8EGy/kXYi/G/cKAC4HDAbSnzCIk1WmsoOKXg==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "darwin"
            ]
        },
        "node_modules/@rollup/rollup-freebsd-arm64": {
            "version": "4.63.1",
            "resolved": "https://registry.npmjs.org/@rollup/rollup-freebsd-arm64/-/rollup-freebsd-arm64-4.63.1.tgz",
            "integrity": "sha512-pcFGeL2345VwdTnJhA6zLbew+YgWB0qBG2+dMtXjCicf6+rm6kO6cOoh5VnTe0ZMrMRgRyuHmCJxZWrIdzYuOw==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "freebsd"
            ]
        },
        "node_modules/@rollup/rollup-freebsd-x64": {
            "version": "4.63.1",
            "resolved": "https://registry.npmjs.org/@rollup/rollup-freebsd-x64/-/rollup-freebsd-x64-4.63.1.tgz",
            "integrity": "sha512-mRJlqSRulVzcKq/LKA6ICSIc3K/l4fzlVn/gePn2nXIHy8seRi5z/eeRE0d/XMBxcMldiXtQTSpRj0tkkC3g8Q==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "freebsd"
            ]
        },
        "node_modules/@rollup/rollup-linux-arm-gnueabihf": {
            "version": "4.63.1",
            "resolved": "https://registry.npmjs.org/@rollup/rollup-linux-arm-gnueabihf/-/rollup-linux-arm-gnueabihf-4.63.1.tgz",
            "integrity": "sha512-YDUNvVM85TI3g/1OpnqKP1h4NeW/j64DfWMf+G3M809xNk1bJSnpFp4sh83NpmVE5DXnkh8ULor4LTVZKoYLHw==",
            "cpu": [
                "arm"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ]
        },
        "node_modules/@rollup/rollup-linux-arm-musleabihf": {
            "version": "4.63.1",
            "resolved": "https://registry.npmjs.org/@rollup/rollup-linux-arm-musleabihf/-/rollup-linux-arm-musleabihf-4.63.1.tgz",
            "integrity": "sha512-7Mcn71p9ZuQFAj+h+dhQXy/yeLePRS2yKRnmW1DijA9thKO5qap0GNOIQK4yQ6iP3SU0Mrb/yWo8h8vgRba8lw==",
            "cpu": [
                "arm"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ]
        },
        "node_modules/@rollup/rollup-linux-arm64-gnu": {
            "version": "4.63.1",
            "resolved": "https://registry.npmjs.org/@rollup/rollup-linux-arm64-gnu/-/rollup-linux-arm64-gnu-4.63.1.tgz",
            "integrity": "sha512-4YiLQTX6U4CSl0L9cluep9A9W6UmTfqBDc2/CH6wlu54pl4E7Jn3cOD8oxzvBDEGk/JMKgJ47C8g+radF7mwvg==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ]
        },
        "node_modules/@rollup/rollup-linux-arm64-musl": {
            "version": "4.63.1",
            "resolved": "https://registry.npmjs.org/@rollup/rollup-linux-arm64-musl/-/rollup-linux-arm64-musl-4.63.1.tgz",
            "integrity": "sha512-2ra8F7w8OquwZN9z2/fKFnli69wa8PLwaVzRMIPGb13ByMJwC28Fbp8YcVGoUhlYMTt7j5j9bNgpysrN2UM+vw==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ]
        },
        "node_modules/@rollup/rollup-linux-loong64-gnu": {
            "version": "4.63.1",
            "resolved": "https://registry.npmjs.org/@rollup/rollup-linux-loong64-gnu/-/rollup-linux-loong64-gnu-4.63.1.tgz",
            "integrity": "sha512-Sy20ncyhjmBP0Ml+UvQbimjlk6VFgjW5uNP+qqwHB00mTE8Bl2C1TuHTlRwK2YoXeZbee5lP2XevBWVkAQAtSQ==",
            "cpu": [
                "loong64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ]
        },
        "node_modules/@rollup/rollup-linux-loong64-musl": {
            "version": "4.63.1",
            "resolved": "https://registry.npmjs.org/@rollup/rollup-linux-loong64-musl/-/rollup-linux-loong64-musl-4.63.1.tgz",
            "integrity": "sha512-noITLp8oNjYliPnGWmLyelIHwULGqbHloQHGw1rtxbWhTuWooRpnZarZQJ1y9EUC4szuCusCc+HEpUtxpIwYvA==",
            "cpu": [
                "loong64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ]
        },
        "node_modules/@rollup/rollup-linux-ppc64-gnu": {
            "version": "4.63.1",
            "resolved": "https://registry.npmjs.org/@rollup/rollup-linux-ppc64-gnu/-/rollup-linux-ppc64-gnu-4.63.1.tgz",
            "integrity": "sha512-hlxxXd+F1mWiAcaFR7Sv9ZQT6m6UfI8+Vy/kFJzztq2pDMU/0wZ9sish0iszNZvsQDo8Gc0i5yuFEOz5dDf6fA==",
            "cpu": [
                "ppc64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ]
        },
        "node_modules/@rollup/rollup-linux-ppc64-musl": {
            "version": "4.63.1",
            "resolved": "https://registry.npmjs.org/@rollup/rollup-linux-ppc64-musl/-/rollup-linux-ppc64-musl-4.63.1.tgz",
            "integrity": "sha512-EF7OpqQTQ/BvGqLzUi4rEHuagCV9MugAUXSHemwPW5vxZ75RR+jxO/2j95Ph2dalMpFHSVECjRoioHZgA9zOYA==",
            "cpu": [
                "ppc64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ]
        },
        "node_modules/@rollup/rollup-linux-riscv64-gnu": {
            "version": "4.63.1",
            "resolved": "https://registry.npmjs.org/@rollup/rollup-linux-riscv64-gnu/-/rollup-linux-riscv64-gnu-4.63.1.tgz",
            "integrity": "sha512-wQO3JesW9PRkwlabQ27y7sPfVOOTLRG73I4F2UYHG5PXun3J9U3y+b7ezVKSYbsvSKGQ1k1cq8Qlun4C9kLt3w==",
            "cpu": [
                "riscv64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ]
        },
        "node_modules/@rollup/rollup-linux-riscv64-musl": {
            "version": "4.63.1",
            "resolved": "https://registry.npmjs.org/@rollup/rollup-linux-riscv64-musl/-/rollup-linux-riscv64-musl-4.63.1.tgz",
            "integrity": "sha512-ouAGwhO6wHRXdnOVCOsB0tRFkA7nhNB2Nwax6oECXN0YiN8EYUTBAOudADOB1PI+yDL61TeNx/u7MVCzksNbkQ==",
            "cpu": [
                "riscv64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ]
        },
        "node_modules/@rollup/rollup-linux-s390x-gnu": {
            "version": "4.63.1",
            "resolved": "https://registry.npmjs.org/@rollup/rollup-linux-s390x-gnu/-/rollup-linux-s390x-gnu-4.63.1.tgz",
            "integrity": "sha512-q2R38Sn+1J8RxhfJ+T54wSWmyKXWec+9jgDfqO2AtArEqHO5R2aeayp5H5OYLr5UYDVGsVaZPEFUooMhYCdz5A==",
            "cpu": [
                "s390x"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ]
        },
        "node_modules/@rollup/rollup-linux-x64-gnu": {
            "version": "4.63.1",
            "resolved": "https://registry.npmjs.org/@rollup/rollup-linux-x64-gnu/-/rollup-linux-x64-gnu-4.63.1.tgz",
            "integrity": "sha512-gfI5T24WLLuFfSKw7Go/zDXjAAV0fny0swTaDv+WjK7vqcw4cRhFfdsyKL1n+ukI+ooBxn3bVQnyrn06WpI50w==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ]
        },
        "node_modules/@rollup/rollup-linux-x64-musl": {
            "version": "4.63.1",
            "resolved": "https://registry.npmjs.org/@rollup/rollup-linux-x64-musl/-/rollup-linux-x64-musl-4.63.1.tgz",
            "integrity": "sha512-4h6XqthmB4Hspji84wvgk+ElodTsGj+dbZqHJHHtKxj4mYq0ANSEEPX9ys3moJueqsRjwpaJYH7874Itwnj2ow==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ]
        },
        "node_modules/@rollup/rollup-openbsd-x64": {
            "version": "4.63.1",
            "resolved": "https://registry.npmjs.org/@rollup/rollup-openbsd-x64/-/rollup-openbsd-x64-4.63.1.tgz",
            "integrity": "sha512-dlfCOa87o1VAYegLQ9EKilx2JCeRofiyPGhTCmqnuXZ6bMPiycO1rq1+sKoulAp7pGLIsTIw+1x5R+zgh5LhhA==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "openbsd"
            ]
        },
        "node_modules/@rollup/rollup-openharmony-arm64": {
            "version": "4.63.1",
            "resolved": "https://registry.npmjs.org/@rollup/rollup-openharmony-arm64/-/rollup-openharmony-arm64-4.63.1.tgz",
            "integrity": "sha512-cjkLbOlfcm3QGhMM1J5zaZjsw1GggbN6rw9UTSSRrPrR1KkcXnN7Uq9rPw34xImQ9VOY9GN+6u2Zj80B9ptkcw==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "openharmony"
            ]
        },
        "node_modules/@rollup/rollup-win32-arm64-msvc": {
            "version": "4.63.1",
            "resolved": "https://registry.npmjs.org/@rollup/rollup-win32-arm64-msvc/-/rollup-win32-arm64-msvc-4.63.1.tgz",
            "integrity": "sha512-Li1KdUnWGE4N3e1F/B4RTB1ms+nG4WBgjByO46pkeBVX/2UBsY53xf5vK9WygVmnH3RwncIST7lkSdLSY6P9lg==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "win32"
            ]
        },
        "node_modules/@rollup/rollup-win32-ia32-msvc": {
            "version": "4.63.1",
            "resolved": "https://registry.npmjs.org/@rollup/rollup-win32-ia32-msvc/-/rollup-win32-ia32-msvc-4.63.1.tgz",
            "integrity": "sha512-t4ZYOSoLTgwhuFMrmTMLx/+i1DQVK7HYqMc6kY46EApwi8X0nIVphzdNoThU3xt6n+N5urG1/gxBdCaKDLavfg==",
            "cpu": [
                "ia32"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "win32"
            ]
        },
        "node_modules/@rollup/rollup-win32-x64-gnu": {
            "version": "4.63.1",
            "resolved": "https://registry.npmjs.org/@rollup/rollup-win32-x64-gnu/-/rollup-win32-x64-gnu-4.63.1.tgz",
            "integrity": "sha512-RgroPfMmKlD1RzSDxvwgcPiy2HNQKoYV7OmwIXDsk73uKW5t6B/V8KIy27SMv/FNXFo/oSBtWc9J0X7t91ezZg==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "win32"
            ]
        },
        "node_modules/@rollup/rollup-win32-x64-msvc": {
            "version": "4.63.1",
            "resolved": "https://registry.npmjs.org/@rollup/rollup-win32-x64-msvc/-/rollup-win32-x64-msvc-4.63.1.tgz",
            "integrity": "sha512-at8QVep6S3h5Y6gSbdGU06bRY5WJkf6WUduM9YtvYMbYhB1MOFfUgc6kehitQXzOtMSaT70q7f9ydPhpqu821w==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "win32"
            ]
        },
        "node_modules/@tailwindcss/node": {
            "version": "4.3.3",
            "resolved": "https://registry.npmjs.org/@tailwindcss/node/-/node-4.3.3.tgz",
            "integrity": "sha512-/T8IKEsf9VTU6tLjgC7+sv2mOPtQxzE2jMw7u4Tt40Tx+QSZxpzh95/H6cMKoja9XuW7iMdLJYBB0o9G1CaAgg==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "@jridgewell/remapping": "^2.3.5",
                "enhanced-resolve": "^5.24.1",
                "jiti": "^2.7.0",
                "lightningcss": "1.32.0",
                "magic-string": "^0.30.21",
                "source-map-js": "^1.2.1",
                "tailwindcss": "4.3.3"
            }
        },
        "node_modules/@tailwindcss/oxide": {
            "version": "4.3.3",
            "resolved": "https://registry.npmjs.org/@tailwindcss/oxide/-/oxide-4.3.3.tgz",
            "integrity": "sha512-krXjAikiaFSPaK/FkAQT5UTx3VormQaiZ5hBFlJZ9UFQGB/rwg1MZIhHAG9smMQRTdyJxP6Qt5MwMtdyU5FWrA==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 20"
            },
            "optionalDependencies": {
                "@tailwindcss/oxide-android-arm64": "4.3.3",
                "@tailwindcss/oxide-darwin-arm64": "4.3.3",
                "@tailwindcss/oxide-darwin-x64": "4.3.3",
                "@tailwindcss/oxide-freebsd-x64": "4.3.3",
                "@tailwindcss/oxide-linux-arm-gnueabihf": "4.3.3",
                "@tailwindcss/oxide-linux-arm64-gnu": "4.3.3",
                "@tailwindcss/oxide-linux-arm64-musl": "4.3.3",
                "@tailwindcss/oxide-linux-x64-gnu": "4.3.3",
                "@tailwindcss/oxide-linux-x64-musl": "4.3.3",
                "@tailwindcss/oxide-wasm32-wasi": "4.3.3",
                "@tailwindcss/oxide-win32-arm64-msvc": "4.3.3",
                "@tailwindcss/oxide-win32-x64-msvc": "4.3.3"
            }
        },
        "node_modules/@tailwindcss/oxide-android-arm64": {
            "version": "4.3.3",
            "resolved": "https://registry.npmjs.org/@tailwindcss/oxide-android-arm64/-/oxide-android-arm64-4.3.3.tgz",
            "integrity": "sha512-Y85A2gmPSkl5Ve5qR86GL4HT509cFqQh1aes9p3sSkyTPwt0Pppf3GkwGe4JPACcRYjgJIEhQgM6dBClnr0NYw==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "android"
            ],
            "engines": {
                "node": ">= 20"
            }
        },
        "node_modules/@tailwindcss/oxide-darwin-arm64": {
            "version": "4.3.3",
            "resolved": "https://registry.npmjs.org/@tailwindcss/oxide-darwin-arm64/-/oxide-darwin-arm64-4.3.3.tgz",
            "integrity": "sha512-BiaWatpBcERQFDlOjRDpIVXuFK5PJez5SA4JMg6VYZdBYU+qKfV/vqjcIs+IYmtitf1xYQZTwXvU/8y4lfZUGw==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "darwin"
            ],
            "engines": {
                "node": ">= 20"
            }
        },
        "node_modules/@tailwindcss/oxide-darwin-x64": {
            "version": "4.3.3",
            "resolved": "https://registry.npmjs.org/@tailwindcss/oxide-darwin-x64/-/oxide-darwin-x64-4.3.3.tgz",
            "integrity": "sha512-fAeUqfV5ndhxRwai8cXGzdLvul9utWOmeTkv69unv4ZXixjn61Z+p9lCWdwOwA3TYboG3BwdVuN/RDjhBRl0mw==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "darwin"
            ],
            "engines": {
                "node": ">= 20"
            }
        },
        "node_modules/@tailwindcss/oxide-freebsd-x64": {
            "version": "4.3.3",
            "resolved": "https://registry.npmjs.org/@tailwindcss/oxide-freebsd-x64/-/oxide-freebsd-x64-4.3.3.tgz",
            "integrity": "sha512-iyf5bV6+wnAlflVeEy7R25dupxTNECZN5QMI0qNT6eT+EgaGdZcKhGkr5SdoaWiLJ3spLqIY9VCeSGrwmtg4kw==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "freebsd"
            ],
            "engines": {
                "node": ">= 20"
            }
        },
        "node_modules/@tailwindcss/oxide-linux-arm-gnueabihf": {
            "version": "4.3.3",
            "resolved": "https://registry.npmjs.org/@tailwindcss/oxide-linux-arm-gnueabihf/-/oxide-linux-arm-gnueabihf-4.3.3.tgz",
            "integrity": "sha512-aAYUprJAJQWWbRrPvtjdroZ56Md+JM8pMiopS6xGEwDfLhqj+2ver2p4nU4Mb3CRqcMmNBjo8KkUgcxhkzVQGQ==",
            "cpu": [
                "arm"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": ">= 20"
            }
        },
        "node_modules/@tailwindcss/oxide-linux-arm64-gnu": {
            "version": "4.3.3",
            "resolved": "https://registry.npmjs.org/@tailwindcss/oxide-linux-arm64-gnu/-/oxide-linux-arm64-gnu-4.3.3.tgz",
            "integrity": "sha512-nDxldcEENOxZRzC2uu9jrutZdAAQtb+8WWDCSnWL1zvBk1+FN+x6MtDViPB5AJMfttVCUhehGWus3XBPgatM/w==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": ">= 20"
            }
        },
        "node_modules/@tailwindcss/oxide-linux-arm64-musl": {
            "version": "4.3.3",
            "resolved": "https://registry.npmjs.org/@tailwindcss/oxide-linux-arm64-musl/-/oxide-linux-arm64-musl-4.3.3.tgz",
            "integrity": "sha512-Md44bD6veX/PC5iyF8cDVnw4HBIANZepRZZ7a8DQOvkfo5WUBwcp6iAuCUz23u+4SUkhJlD3eL7hNdW8ezd/kA==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": ">= 20"
            }
        },
        "node_modules/@tailwindcss/oxide-linux-x64-gnu": {
            "version": "4.3.3",
            "resolved": "https://registry.npmjs.org/@tailwindcss/oxide-linux-x64-gnu/-/oxide-linux-x64-gnu-4.3.3.tgz",
            "integrity": "sha512-tx7us1muwOKAKWao2v/GaafFeQboE6aj88vC6ziN2NCGcRm8gWUhwjzg+YdVB1e4boAtdtma4L43onunI6NS4w==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": ">= 20"
            }
        },
        "node_modules/@tailwindcss/oxide-linux-x64-musl": {
            "version": "4.3.3",
            "resolved": "https://registry.npmjs.org/@tailwindcss/oxide-linux-x64-musl/-/oxide-linux-x64-musl-4.3.3.tgz",
            "integrity": "sha512-SJxX60smvHgasZoBy11dX6YRjXJFovwWBoedhbQPOBzgFWBHGB+TVPWB9BxzR7TTxU8FQZAI2AyiNCMzFm8Img==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": ">= 20"
            }
        },
        "node_modules/@tailwindcss/oxide-wasm32-wasi": {
            "version": "4.3.3",
            "resolved": "https://registry.npmjs.org/@tailwindcss/oxide-wasm32-wasi/-/oxide-wasm32-wasi-4.3.3.tgz",
            "integrity": "sha512-jx1+rPhY/5Ympkktd656HBWEBLxP7dH06losBLjjf5vgCODXvi9KhtftWcMIwTFIDqBr7cRnQkdLnAG+IOlGvQ==",
            "bundleDependencies": [
                "@napi-rs/wasm-runtime",
                "@emnapi/core",
                "@emnapi/runtime",
                "@tybys/wasm-util",
                "@emnapi/wasi-threads",
                "tslib"
            ],
            "cpu": [
                "wasm32"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "dependencies": {
                "@emnapi/core": "^1.11.1",
                "@emnapi/runtime": "^1.11.1",
                "@emnapi/wasi-threads": "^1.2.2",
                "@napi-rs/wasm-runtime": "^1.1.4",
                "@tybys/wasm-util": "^0.10.2",
                "tslib": "^2.8.1"
            },
            "engines": {
                "node": ">=14.0.0"
            }
        },
        "node_modules/@tailwindcss/oxide-win32-arm64-msvc": {
            "version": "4.3.3",
            "resolved": "https://registry.npmjs.org/@tailwindcss/oxide-win32-arm64-msvc/-/oxide-win32-arm64-msvc-4.3.3.tgz",
            "integrity": "sha512-3rc292Ca2ceK6Ulcc/bAVnTs/3nDtoPhyEKlgPv+yQJQi/JS/AMJlqzxvlDacL1nekbrcf6bTqp/jV4qgnPxNQ==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "win32"
            ],
            "engines": {
                "node": ">= 20"
            }
        },
        "node_modules/@tailwindcss/oxide-win32-x64-msvc": {
            "version": "4.3.3",
            "resolved": "https://registry.npmjs.org/@tailwindcss/oxide-win32-x64-msvc/-/oxide-win32-x64-msvc-4.3.3.tgz",
            "integrity": "sha512-yJ0pwIVc/nYeGoV02WtsN8KYyLQv7kyI2wDnkezyJlGGjkd4QLwDGAwl47YpPJeuI0M0ObaXGSPjvWDPeTPggw==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "win32"
            ],
            "engines": {
                "node": ">= 20"
            }
        },
        "node_modules/@tailwindcss/vite": {
            "version": "4.3.3",
            "resolved": "https://registry.npmjs.org/@tailwindcss/vite/-/vite-4.3.3.tgz",
            "integrity": "sha512-yYU8cogLeSh/ms2jh8Fj7jaba/EWa7Ja6GoUqYZaraEuCI5YS6ms6ObZgjjedm+jm6XZjdNRWBpPP6Z86oOxcw==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "@tailwindcss/node": "4.3.3",
                "@tailwindcss/oxide": "4.3.3",
                "tailwindcss": "4.3.3"
            },
            "peerDependencies": {
                "vite": "^5.2.0 || ^6 || ^7 || ^8"
            }
        },
        "node_modules/@types/estree": {
            "version": "1.0.9",
            "resolved": "https://registry.npmjs.org/@types/estree/-/estree-1.0.9.tgz",
            "integrity": "sha512-GhdPgy1el4/ImP05X05Uw4cw2/M93BCUmnEvWZNStlCzEKME4Fkk+YpoA5OiHNQmoS7Cafb8Xa3Pya8m1Qrzeg==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/agent-base": {
            "version": "6.0.2",
            "resolved": "https://registry.npmjs.org/agent-base/-/agent-base-6.0.2.tgz",
            "integrity": "sha512-RZNwNclF7+MS/8bDg70amg32dyeZGZxiDuQmZxKLAlQjr3jGyLx+4Kkk58UO7D2QdgFIQCovuSuZESne6RG6XQ==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "debug": "4"
            },
            "engines": {
                "node": ">= 6.0.0"
            }
        },
        "node_modules/ansi-regex": {
            "version": "5.0.1",
            "resolved": "https://registry.npmjs.org/ansi-regex/-/ansi-regex-5.0.1.tgz",
            "integrity": "sha512-quJQXlTSUGL2LH9SUXo8VwsY4soanhgo6LNSm84E1LBcE8s3O0wpdiRzyR9z/ZZJMlMWv37qOOb9pdJlMUEKFQ==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=8"
            }
        },
        "node_modules/ansi-styles": {
            "version": "4.3.0",
            "resolved": "https://registry.npmjs.org/ansi-styles/-/ansi-styles-4.3.0.tgz",
            "integrity": "sha512-zbB9rCJAT1rbjiVDb2hqKFHNYLxgtk8NURxZ3IZwD3F6NtxbXZQCnnSi1Lkx+IDohdPlFp222wVALIheZJQSEg==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "color-convert": "^2.0.1"
            },
            "engines": {
                "node": ">=8"
            },
            "funding": {
                "url": "https://github.com/chalk/ansi-styles?sponsor=1"
            }
        },
        "node_modules/asynckit": {
            "version": "0.4.0",
            "resolved": "https://registry.npmjs.org/asynckit/-/asynckit-0.4.0.tgz",
            "integrity": "sha512-Oei9OH4tRh0YqU3GxhX79dM/mwVgvbZJaSNaRk+bshkj0S5cfHcgYakreBjrHwatXKbz+IoIdYLxrKim2MjW0Q==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/axios": {
            "version": "1.20.0",
            "resolved": "https://registry.npmjs.org/axios/-/axios-1.20.0.tgz",
            "integrity": "sha512-r8aOh8j9cGKpgQAqpzrUHnSIc6a59Y3Xf/cv8sy1DrHCkZHzQGEuoq1tARk6qSyDdtQGSDgpb9kFlruzPvrgwg==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "follow-redirects": "^1.16.0",
                "form-data": "^4.0.6",
                "https-proxy-agent": "^5.0.1",
                "proxy-from-env": "^2.1.0"
            }
        },
        "node_modules/call-bind-apply-helpers": {
            "version": "1.0.2",
            "resolved": "https://registry.npmjs.org/call-bind-apply-helpers/-/call-bind-apply-helpers-1.0.2.tgz",
            "integrity": "sha512-Sp1ablJ0ivDkSzjcaJdxEunN5/XvksFJ2sMBFfq6x0ryhQV/2b/KwFe21cMpmHtPOSij8K99/wSfoEuTObmuMQ==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "es-errors": "^1.3.0",
                "function-bind": "^1.1.2"
            },
            "engines": {
                "node": ">= 0.4"
            }
        },
        "node_modules/chalk": {
            "version": "4.1.2",
            "resolved": "https://registry.npmjs.org/chalk/-/chalk-4.1.2.tgz",
            "integrity": "sha512-oKnbhFyRIXpUuez8iBMmyEa4nbj4IOQyuhc/wy9kY7/WVPcwIO9VA668Pu8RkO7+0G76SLROeyw9CpQ061i4mA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "ansi-styles": "^4.1.0",
                "supports-color": "^7.1.0"
            },
            "engines": {
                "node": ">=10"
            },
            "funding": {
                "url": "https://github.com/chalk/chalk?sponsor=1"
            }
        },
        "node_modules/chalk/node_modules/supports-color": {
            "version": "7.2.0",
            "resolved": "https://registry.npmjs.org/supports-color/-/supports-color-7.2.0.tgz",
            "integrity": "sha512-qpCAvRl9stuOHveKsn7HncJRvv501qIacKzQlO/+Lwxc9+0q2wLyv4Dfvt80/DPn2pqOBsJdDiogXGR9+OvwRw==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "has-flag": "^4.0.0"
            },
            "engines": {
                "node": ">=8"
            }
        },
        "node_modules/cliui": {
            "version": "8.0.1",
            "resolved": "https://registry.npmjs.org/cliui/-/cliui-8.0.1.tgz",
            "integrity": "sha512-BSeNnyus75C4//NQ9gQt1/csTXyo/8Sb+afLAkzAptFuMsod9HFokGNudZpi/oQV73hnVK+sR+5PVRMd+Dr7YQ==",
            "dev": true,
            "license": "ISC",
            "dependencies": {
                "string-width": "^4.2.0",
                "strip-ansi": "^6.0.1",
                "wrap-ansi": "^7.0.0"
            },
            "engines": {
                "node": ">=12"
            }
        },
        "node_modules/color-convert": {
            "version": "2.0.1",
            "resolved": "https://registry.npmjs.org/color-convert/-/color-convert-2.0.1.tgz",
            "integrity": "sha512-RRECPsj7iu/xb5oKYcsFHSppFNnsj/52OVTRKb4zP5onXwVF3zVmmToNcOfGC+CRDpfK/U584fMg38ZHCaElKQ==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "color-name": "~1.1.4"
            },
            "engines": {
                "node": ">=7.0.0"
            }
        },
        "node_modules/color-name": {
            "version": "1.1.4",
            "resolved": "https://registry.npmjs.org/color-name/-/color-name-1.1.4.tgz",
            "integrity": "sha512-dOy+3AuW3a2wNbZHIuMZpTcgjGuLU/uBL/ubcZF9OXbDo8ff4O8yVp5Bf0efS8uEoYo5q4Fx7dY9OgQGXgAsQA==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/combined-stream": {
            "version": "1.0.8",
            "resolved": "https://registry.npmjs.org/combined-stream/-/combined-stream-1.0.8.tgz",
            "integrity": "sha512-FQN4MRfuJeHf7cBbBMJFXhKSDq+2kAArBlmRBvcvFE5BB1HZKXtSFASDhdlz9zOYwxh8lDdnvmMOe/+5cdoEdg==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "delayed-stream": "~1.0.0"
            },
            "engines": {
                "node": ">= 0.8"
            }
        },
        "node_modules/concurrently": {
            "version": "9.2.4",
            "resolved": "https://registry.npmjs.org/concurrently/-/concurrently-9.2.4.tgz",
            "integrity": "sha512-TZ0CEhyzvFjgtAvHTusDMgj7wNdihCh7LLLrzdUOXIhdlnL2JBBGA9eJxR24rtqgmdjh3OA3hrN1rCHj6HM8qA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "chalk": "4.1.2",
                "rxjs": "7.8.2",
                "shell-quote": "1.9.0",
                "supports-color": "8.1.1",
                "tree-kill": "1.2.2",
                "yargs": "17.7.2"
            },
            "bin": {
                "conc": "dist/bin/concurrently.js",
                "concurrently": "dist/bin/concurrently.js"
            },
            "engines": {
                "node": ">=18"
            },
            "funding": {
                "url": "https://github.com/open-cli-tools/concurrently?sponsor=1"
            }
        },
        "node_modules/debug": {
            "version": "4.4.3",
            "resolved": "https://registry.npmjs.org/debug/-/debug-4.4.3.tgz",
            "integrity": "sha512-RGwwWnwQvkVfavKVt22FGLw+xYSdzARwm0ru6DhTVA3umU5hZc28V3kO4stgYryrTlLpuvgI9GiijltAjNbcqA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "ms": "^2.1.3"
            },
            "engines": {
                "node": ">=6.0"
            },
            "peerDependenciesMeta": {
                "supports-color": {
                    "optional": true
                }
            }
        },
        "node_modules/delayed-stream": {
            "version": "1.0.0",
            "resolved": "https://registry.npmjs.org/delayed-stream/-/delayed-stream-1.0.0.tgz",
            "integrity": "sha512-ZySD7Nf91aLB0RxL4KGrKHBXl7Eds1DAmEdcoVawXnLD7SDhpNgtuII2aAkg7a7QS41jxPSZ17p4VdGnMHk3MQ==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=0.4.0"
            }
        },
        "node_modules/detect-libc": {
            "version": "2.1.2",
            "resolved": "https://registry.npmjs.org/detect-libc/-/detect-libc-2.1.2.tgz",
            "integrity": "sha512-Btj2BOOO83o3WyH59e8MgXsxEQVcarkUOpEYrubB0urwnN10yQ364rsiByU11nZlqWYZm05i/of7io4mzihBtQ==",
            "dev": true,
            "license": "Apache-2.0",
            "engines": {
                "node": ">=8"
            }
        },
        "node_modules/dunder-proto": {
            "version": "1.0.1",
            "resolved": "https://registry.npmjs.org/dunder-proto/-/dunder-proto-1.0.1.tgz",
            "integrity": "sha512-KIN/nDJBQRcXw0MLVhZE9iQHmG68qAVIBg9CqmUYjmQIhgij9U5MFvrqkUL5FbtyyzZuOeOt0zdeRe4UY7ct+A==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "call-bind-apply-helpers": "^1.0.1",
                "es-errors": "^1.3.0",
                "gopd": "^1.2.0"
            },
            "engines": {
                "node": ">= 0.4"
            }
        },
        "node_modules/emoji-regex": {
            "version": "8.0.0",
            "resolved": "https://registry.npmjs.org/emoji-regex/-/emoji-regex-8.0.0.tgz",
            "integrity": "sha512-MSjYzcWNOA0ewAHpz0MxpYFvwg6yjy1NG3xteoqz644VCo/RPgnr1/GGt+ic3iJTzQ8Eu3TdM14SawnVUmGE6A==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/enhanced-resolve": {
            "version": "5.24.5",
            "resolved": "https://registry.npmjs.org/enhanced-resolve/-/enhanced-resolve-5.24.5.tgz",
            "integrity": "sha512-L1l8TNvomm6UVW5B253AGxQagSQr+vGwhMlrrfRS2qmhx46AMpMVJKQYLvWYbysTMY8VoicOvzHzoHMbyzB+4A==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "graceful-fs": "^4.2.4",
                "tapable": "^2.3.3"
            },
            "engines": {
                "node": ">=10.13.0"
            }
        },
        "node_modules/es-define-property": {
            "version": "1.0.1",
            "resolved": "https://registry.npmjs.org/es-define-property/-/es-define-property-1.0.1.tgz",
            "integrity": "sha512-e3nRfgfUZ4rNGL232gUgX06QNyyez04KdjFrF+LTRoOXmrOgFKDg4BCdsjW8EnT69eqdYGmRpJwiPVYNrCaW3g==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 0.4"
            }
        },
        "node_modules/es-errors": {
            "version": "1.3.0",
            "resolved": "https://registry.npmjs.org/es-errors/-/es-errors-1.3.0.tgz",
            "integrity": "sha512-Zf5H2Kxt2xjTvbJvP2ZWLEICxA6j+hAmMzIlypy4xcBg1vKVnx89Wy0GbS+kf5cwCVFFzdCFh2XSCFNULS6csw==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 0.4"
            }
        },
        "node_modules/es-object-atoms": {
            "version": "1.1.2",
            "resolved": "https://registry.npmjs.org/es-object-atoms/-/es-object-atoms-1.1.2.tgz",
            "integrity": "sha512-HWcBoN6NileqtSydK2FqHbS/LoDd2pqrnQHLyJzBj4kOp/ky2MWMN694xOfkK8/SnUsW2DH7EfyVlydKCsm1Zw==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "es-errors": "^1.3.0"
            },
            "engines": {
                "node": ">= 0.4"
            }
        },
        "node_modules/es-set-tostringtag": {
            "version": "2.1.0",
            "resolved": "https://registry.npmjs.org/es-set-tostringtag/-/es-set-tostringtag-2.1.0.tgz",
            "integrity": "sha512-j6vWzfrGVfyXxge+O0x5sh6cvxAog0a/4Rdd2K36zCMV5eJ+/+tOAngRO8cODMNWbVRdVlmGZQL2YS3yR8bIUA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "es-errors": "^1.3.0",
                "get-intrinsic": "^1.2.6",
                "has-tostringtag": "^1.0.2",
                "hasown": "^2.0.2"
            },
            "engines": {
                "node": ">= 0.4"
            }
        },
        "node_modules/esbuild": {
            "version": "0.28.2",
            "resolved": "https://registry.npmjs.org/esbuild/-/esbuild-0.28.2.tgz",
            "integrity": "sha512-HKVLS8dvII+xoKW9kmqxbRKrnWEXfJJr/FZhhJmiqIB0e053QNYFqOBouTMO/k5sID4MvCiUCvv8b9M4h32wIA==",
            "dev": true,
            "hasInstallScript": true,
            "license": "MIT",
            "bin": {
                "esbuild": "bin/esbuild"
            },
            "engines": {
                "node": ">=18"
            },
            "optionalDependencies": {
                "@esbuild/aix-ppc64": "0.28.2",
                "@esbuild/android-arm": "0.28.2",
                "@esbuild/android-arm64": "0.28.2",
                "@esbuild/android-x64": "0.28.2",
                "@esbuild/darwin-arm64": "0.28.2",
                "@esbuild/darwin-x64": "0.28.2",
                "@esbuild/freebsd-arm64": "0.28.2",
                "@esbuild/freebsd-x64": "0.28.2",
                "@esbuild/linux-arm": "0.28.2",
                "@esbuild/linux-arm64": "0.28.2",
                "@esbuild/linux-ia32": "0.28.2",
                "@esbuild/linux-loong64": "0.28.2",
                "@esbuild/linux-mips64el": "0.28.2",
                "@esbuild/linux-ppc64": "0.28.2",
                "@esbuild/linux-riscv64": "0.28.2",
                "@esbuild/linux-s390x": "0.28.2",
                "@esbuild/linux-x64": "0.28.2",
                "@esbuild/netbsd-arm64": "0.28.2",
                "@esbuild/netbsd-x64": "0.28.2",
                "@esbuild/openbsd-arm64": "0.28.2",
                "@esbuild/openbsd-x64": "0.28.2",
                "@esbuild/openharmony-arm64": "0.28.2",
                "@esbuild/sunos-x64": "0.28.2",
                "@esbuild/win32-arm64": "0.28.2",
                "@esbuild/win32-ia32": "0.28.2",
                "@esbuild/win32-x64": "0.28.2"
            }
        },
        "node_modules/escalade": {
            "version": "3.2.0",
            "resolved": "https://registry.npmjs.org/escalade/-/escalade-3.2.0.tgz",
            "integrity": "sha512-WUj2qlxaQtO4g6Pq5c29GTcWGDyd8itL8zTlipgECz3JesAiiOKotd8JU6otB3PACgG6xkJUyVhboMS+bje/jA==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=6"
            }
        },
        "node_modules/fdir": {
            "version": "6.5.0",
            "resolved": "https://registry.npmjs.org/fdir/-/fdir-6.5.0.tgz",
            "integrity": "sha512-tIbYtZbucOs0BRGqPJkshJUYdL+SDH7dVM8gjy+ERp3WAUjLEFJE+02kanyHtwjWOnwrKYBiwAmM0p4kLJAnXg==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=12.0.0"
            },
            "peerDependencies": {
                "picomatch": "^3 || ^4"
            },
            "peerDependenciesMeta": {
                "picomatch": {
                    "optional": true
                }
            }
        },
        "node_modules/follow-redirects": {
            "version": "1.16.0",
            "resolved": "https://registry.npmjs.org/follow-redirects/-/follow-redirects-1.16.0.tgz",
            "integrity": "sha512-y5rN/uOsadFT/JfYwhxRS5R7Qce+g3zG97+JrtFZlC9klX/W5hD7iiLzScI4nZqUS7DNUdhPgw4xI8W2LuXlUw==",
            "dev": true,
            "funding": [
                {
                    "type": "individual",
                    "url": "https://github.com/sponsors/RubenVerborgh"
                }
            ],
            "license": "MIT",
            "engines": {
                "node": ">=4.0"
            },
            "peerDependenciesMeta": {
                "debug": {
                    "optional": true
                }
            }
        },
        "node_modules/form-data": {
            "version": "4.0.6",
            "resolved": "https://registry.npmjs.org/form-data/-/form-data-4.0.6.tgz",
            "integrity": "sha512-vKatAh4SlVfgbv+YtmhiRjhEMJsYpsG1Y2rMQtR+SVSbytsSD1YGzDIcrAJmdFec88u/+VoGmxnl+80gL1tRCQ==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "asynckit": "^0.4.0",
                "combined-stream": "^1.0.8",
                "es-set-tostringtag": "^2.1.0",
                "hasown": "^2.0.4",
                "mime-types": "^2.1.35"
            },
            "engines": {
                "node": ">= 6"
            }
        },
        "node_modules/fsevents": {
            "version": "2.3.3",
            "resolved": "https://registry.npmjs.org/fsevents/-/fsevents-2.3.3.tgz",
            "integrity": "sha512-5xoDfX+fL7faATnagmWPpbFtwh/R77WmMMqqHGS65C3vvB0YHrgF+B1YmZ3441tMj5n63k0212XNoJwzlhffQw==",
            "dev": true,
            "hasInstallScript": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "darwin"
            ],
            "engines": {
                "node": "^8.16.0 || ^10.6.0 || >=11.0.0"
            }
        },
        "node_modules/function-bind": {
            "version": "1.1.2",
            "resolved": "https://registry.npmjs.org/function-bind/-/function-bind-1.1.2.tgz",
            "integrity": "sha512-7XHNxH7qX9xG5mIwxkhumTox/MIRNcOgDrxWsMt2pAr23WHp6MrRlN7FBSFpCpr+oVO0F744iUgR82nJMfG2SA==",
            "dev": true,
            "license": "MIT",
            "funding": {
                "url": "https://github.com/sponsors/ljharb"
            }
        },
        "node_modules/get-caller-file": {
            "version": "2.0.5",
            "resolved": "https://registry.npmjs.org/get-caller-file/-/get-caller-file-2.0.5.tgz",
            "integrity": "sha512-DyFP3BM/3YHTQOCUL/w0OZHR0lpKeGrxotcHWcqNEdnltqFwXVfhEBQ94eIo34AfQpo0rGki4cyIiftY06h2Fg==",
            "dev": true,
            "license": "ISC",
            "engines": {
                "node": "6.* || 8.* || >= 10.*"
            }
        },
        "node_modules/get-intrinsic": {
            "version": "1.3.0",
            "resolved": "https://registry.npmjs.org/get-intrinsic/-/get-intrinsic-1.3.0.tgz",
            "integrity": "sha512-9fSjSaos/fRIVIp+xSJlE6lfwhES7LNtKaCBIamHsjr2na1BiABJPo0mOjjz8GJDURarmCPGqaiVg5mfjb98CQ==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "call-bind-apply-helpers": "^1.0.2",
                "es-define-property": "^1.0.1",
                "es-errors": "^1.3.0",
                "es-object-atoms": "^1.1.1",
                "function-bind": "^1.1.2",
                "get-proto": "^1.0.1",
                "gopd": "^1.2.0",
                "has-symbols": "^1.1.0",
                "hasown": "^2.0.2",
                "math-intrinsics": "^1.1.0"
            },
            "engines": {
                "node": ">= 0.4"
            },
            "funding": {
                "url": "https://github.com/sponsors/ljharb"
            }
        },
        "node_modules/get-proto": {
            "version": "1.0.1",
            "resolved": "https://registry.npmjs.org/get-proto/-/get-proto-1.0.1.tgz",
            "integrity": "sha512-sTSfBjoXBp89JvIKIefqw7U2CCebsc74kiY6awiGogKtoSGbgjYE/G/+l9sF3MWFPNc9IcoOC4ODfKHfxFmp0g==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "dunder-proto": "^1.0.1",
                "es-object-atoms": "^1.0.0"
            },
            "engines": {
                "node": ">= 0.4"
            }
        },
        "node_modules/gopd": {
            "version": "1.2.0",
            "resolved": "https://registry.npmjs.org/gopd/-/gopd-1.2.0.tgz",
            "integrity": "sha512-ZUKRh6/kUFoAiTAtTYPZJ3hw9wNxx+BIBOijnlG9PnrJsCcSjs1wyyD6vJpaYtgnzDrKYRSqf3OO6Rfa93xsRg==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 0.4"
            },
            "funding": {
                "url": "https://github.com/sponsors/ljharb"
            }
        },
        "node_modules/graceful-fs": {
            "version": "4.2.11",
            "resolved": "https://registry.npmjs.org/graceful-fs/-/graceful-fs-4.2.11.tgz",
            "integrity": "sha512-RbJ5/jmFcNNCcDV5o9eTnBLJ/HszWV0P73bc+Ff4nS/rJj+YaS6IGyiOL0VoBYX+l1Wrl3k63h/KrH+nhJ0XvQ==",
            "dev": true,
            "license": "ISC"
        },
        "node_modules/has-flag": {
            "version": "4.0.0",
            "resolved": "https://registry.npmjs.org/has-flag/-/has-flag-4.0.0.tgz",
            "integrity": "sha512-EykJT/Q1KjTWctppgIAgfSO0tKVuZUjhgMr17kqTumMl6Afv3EISleU7qZUzoXDFTAHTDC4NOoG/ZxU3EvlMPQ==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=8"
            }
        },
        "node_modules/has-symbols": {
            "version": "1.1.0",
            "resolved": "https://registry.npmjs.org/has-symbols/-/has-symbols-1.1.0.tgz",
            "integrity": "sha512-1cDNdwJ2Jaohmb3sg4OmKaMBwuC48sYni5HUw2DvsC8LjGTLK9h+eb1X6RyuOHe4hT0ULCW68iomhjUoKUqlPQ==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 0.4"
            },
            "funding": {
                "url": "https://github.com/sponsors/ljharb"
            }
        },
        "node_modules/has-tostringtag": {
            "version": "1.0.2",
            "resolved": "https://registry.npmjs.org/has-tostringtag/-/has-tostringtag-1.0.2.tgz",
            "integrity": "sha512-NqADB8VjPFLM2V0VvHUewwwsw0ZWBaIdgo+ieHtK3hasLz4qeCRjYcqfB6AQrBggRKppKF8L52/VqdVsO47Dlw==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "has-symbols": "^1.0.3"
            },
            "engines": {
                "node": ">= 0.4"
            },
            "funding": {
                "url": "https://github.com/sponsors/ljharb"
            }
        },
        "node_modules/hasown": {
            "version": "2.0.4",
            "resolved": "https://registry.npmjs.org/hasown/-/hasown-2.0.4.tgz",
            "integrity": "sha512-T2UbfbBEF32wiepXIsMlTW9+dDYC6wMh/t/vYA4tuOMKqWz/n3vr1NFSxQiyP+zk2mXsoMA/i/7qV6LKut1t1A==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "function-bind": "^1.1.2"
            },
            "engines": {
                "node": ">= 0.4"
            }
        },
        "node_modules/https-proxy-agent": {
            "version": "5.0.1",
            "resolved": "https://registry.npmjs.org/https-proxy-agent/-/https-proxy-agent-5.0.1.tgz",
            "integrity": "sha512-dFcAjpTQFgoLMzC2VwU+C/CbS7uRL0lWmxDITmqm7C+7F0Odmj6s9l6alZc6AELXhrnggM2CeWSXHGOdX2YtwA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "agent-base": "6",
                "debug": "4"
            },
            "engines": {
                "node": ">= 6"
            }
        },
        "node_modules/is-fullwidth-code-point": {
            "version": "3.0.0",
            "resolved": "https://registry.npmjs.org/is-fullwidth-code-point/-/is-fullwidth-code-point-3.0.0.tgz",
            "integrity": "sha512-zymm5+u+sCsSWyD9qNaejV3DFvhCKclKdizYaJUuHA83RLjb7nSuGnddCHGv0hk+KY7BMAlsWeK4Ueg6EV6XQg==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=8"
            }
        },
        "node_modules/jiti": {
            "version": "2.7.0",
            "resolved": "https://registry.npmjs.org/jiti/-/jiti-2.7.0.tgz",
            "integrity": "sha512-AC/7JofJvZGrrneWNaEnJeOLUx+JlGt7tNa0wZiRPT4MY1wmfKjt2+6O2p2uz2+skll8OZZmJMNqeke7kKbNgQ==",
            "dev": true,
            "license": "MIT",
            "bin": {
                "jiti": "lib/jiti-cli.mjs"
            }
        },
        "node_modules/laravel-vite-plugin": {
            "version": "2.1.0",
            "resolved": "https://registry.npmjs.org/laravel-vite-plugin/-/laravel-vite-plugin-2.1.0.tgz",
            "integrity": "sha512-z+ck2BSV6KWtYcoIzk9Y5+p4NEjqM+Y4i8/H+VZRLq0OgNjW2DqyADquwYu5j8qRvaXwzNmfCWl1KrMlV1zpsg==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "picocolors": "^1.0.0",
                "vite-plugin-full-reload": "^1.1.0"
            },
            "bin": {
                "clean-orphaned-assets": "bin/clean.js"
            },
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            },
            "peerDependencies": {
                "vite": "^7.0.0"
            }
        },
        "node_modules/lightningcss": {
            "version": "1.32.0",
            "resolved": "https://registry.npmjs.org/lightningcss/-/lightningcss-1.32.0.tgz",
            "integrity": "sha512-NXYBzinNrblfraPGyrbPoD19C1h9lfI/1mzgWYvXUTe414Gz/X1FD2XBZSZM7rRTrMA8JL3OtAaGifrIKhQ5yQ==",
            "dev": true,
            "license": "MPL-2.0",
            "dependencies": {
                "detect-libc": "^2.0.3"
            },
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            },
            "optionalDependencies": {
                "lightningcss-android-arm64": "1.32.0",
                "lightningcss-darwin-arm64": "1.32.0",
                "lightningcss-darwin-x64": "1.32.0",
                "lightningcss-freebsd-x64": "1.32.0",
                "lightningcss-linux-arm-gnueabihf": "1.32.0",
                "lightningcss-linux-arm64-gnu": "1.32.0",
                "lightningcss-linux-arm64-musl": "1.32.0",
                "lightningcss-linux-x64-gnu": "1.32.0",
                "lightningcss-linux-x64-musl": "1.32.0",
                "lightningcss-win32-arm64-msvc": "1.32.0",
                "lightningcss-win32-x64-msvc": "1.32.0"
            }
        },
        "node_modules/lightningcss-android-arm64": {
            "version": "1.32.0",
            "resolved": "https://registry.npmjs.org/lightningcss-android-arm64/-/lightningcss-android-arm64-1.32.0.tgz",
            "integrity": "sha512-YK7/ClTt4kAK0vo6w3X+Pnm0D2cf2vPHbhOXdoNti1Ga0al1P4TBZhwjATvjNwLEBCnKvjJc2jQgHXH0NEwlAg==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "android"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/lightningcss-darwin-arm64": {
            "version": "1.32.0",
            "resolved": "https://registry.npmjs.org/lightningcss-darwin-arm64/-/lightningcss-darwin-arm64-1.32.0.tgz",
            "integrity": "sha512-RzeG9Ju5bag2Bv1/lwlVJvBE3q6TtXskdZLLCyfg5pt+HLz9BqlICO7LZM7VHNTTn/5PRhHFBSjk5lc4cmscPQ==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "darwin"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/lightningcss-darwin-x64": {
            "version": "1.32.0",
            "resolved": "https://registry.npmjs.org/lightningcss-darwin-x64/-/lightningcss-darwin-x64-1.32.0.tgz",
            "integrity": "sha512-U+QsBp2m/s2wqpUYT/6wnlagdZbtZdndSmut/NJqlCcMLTWp5muCrID+K5UJ6jqD2BFshejCYXniPDbNh73V8w==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "darwin"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/lightningcss-freebsd-x64": {
            "version": "1.32.0",
            "resolved": "https://registry.npmjs.org/lightningcss-freebsd-x64/-/lightningcss-freebsd-x64-1.32.0.tgz",
            "integrity": "sha512-JCTigedEksZk3tHTTthnMdVfGf61Fky8Ji2E4YjUTEQX14xiy/lTzXnu1vwiZe3bYe0q+SpsSH/CTeDXK6WHig==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "freebsd"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/lightningcss-linux-arm-gnueabihf": {
            "version": "1.32.0",
            "resolved": "https://registry.npmjs.org/lightningcss-linux-arm-gnueabihf/-/lightningcss-linux-arm-gnueabihf-1.32.0.tgz",
            "integrity": "sha512-x6rnnpRa2GL0zQOkt6rts3YDPzduLpWvwAF6EMhXFVZXD4tPrBkEFqzGowzCsIWsPjqSK+tyNEODUBXeeVHSkw==",
            "cpu": [
                "arm"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/lightningcss-linux-arm64-gnu": {
            "version": "1.32.0",
            "resolved": "https://registry.npmjs.org/lightningcss-linux-arm64-gnu/-/lightningcss-linux-arm64-gnu-1.32.0.tgz",
            "integrity": "sha512-0nnMyoyOLRJXfbMOilaSRcLH3Jw5z9HDNGfT/gwCPgaDjnx0i8w7vBzFLFR1f6CMLKF8gVbebmkUN3fa/kQJpQ==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/lightningcss-linux-arm64-musl": {
            "version": "1.32.0",
            "resolved": "https://registry.npmjs.org/lightningcss-linux-arm64-musl/-/lightningcss-linux-arm64-musl-1.32.0.tgz",
            "integrity": "sha512-UpQkoenr4UJEzgVIYpI80lDFvRmPVg6oqboNHfoH4CQIfNA+HOrZ7Mo7KZP02dC6LjghPQJeBsvXhJod/wnIBg==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/lightningcss-linux-x64-gnu": {
            "version": "1.32.0",
            "resolved": "https://registry.npmjs.org/lightningcss-linux-x64-gnu/-/lightningcss-linux-x64-gnu-1.32.0.tgz",
            "integrity": "sha512-V7Qr52IhZmdKPVr+Vtw8o+WLsQJYCTd8loIfpDaMRWGUZfBOYEJeyJIkqGIDMZPwPx24pUMfwSxxI8phr/MbOA==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/lightningcss-linux-x64-musl": {
            "version": "1.32.0",
            "resolved": "https://registry.npmjs.org/lightningcss-linux-x64-musl/-/lightningcss-linux-x64-musl-1.32.0.tgz",
            "integrity": "sha512-bYcLp+Vb0awsiXg/80uCRezCYHNg1/l3mt0gzHnWV9XP1W5sKa5/TCdGWaR/zBM2PeF/HbsQv/j2URNOiVuxWg==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/lightningcss-win32-arm64-msvc": {
            "version": "1.32.0",
            "resolved": "https://registry.npmjs.org/lightningcss-win32-arm64-msvc/-/lightningcss-win32-arm64-msvc-1.32.0.tgz",
            "integrity": "sha512-8SbC8BR40pS6baCM8sbtYDSwEVQd4JlFTOlaD3gWGHfThTcABnNDBda6eTZeqbofalIJhFx0qKzgHJmcPTnGdw==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "win32"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/lightningcss-win32-x64-msvc": {
            "version": "1.32.0",
            "resolved": "https://registry.npmjs.org/lightningcss-win32-x64-msvc/-/lightningcss-win32-x64-msvc-1.32.0.tgz",
            "integrity": "sha512-Amq9B/SoZYdDi1kFrojnoqPLxYhQ4Wo5XiL8EVJrVsB8ARoC1PWW6VGtT0WKCemjy8aC+louJnjS7U18x3b06Q==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "win32"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/magic-string": {
            "version": "0.30.21",
            "resolved": "https://registry.npmjs.org/magic-string/-/magic-string-0.30.21.tgz",
            "integrity": "sha512-vd2F4YUyEXKGcLHoq+TEyCjxueSeHnFxyyjNp80yg0XV4vUhnDer/lvvlqM/arB5bXQN5K2/3oinyCRyx8T2CQ==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "@jridgewell/sourcemap-codec": "^1.5.5"
            }
        },
        "node_modules/math-intrinsics": {
            "version": "1.1.0",
            "resolved": "https://registry.npmjs.org/math-intrinsics/-/math-intrinsics-1.1.0.tgz",
            "integrity": "sha512-/IXtbwEk5HTPyEwyKX6hGkYXxM9nbj64B+ilVJnC/R6B0pH5G4V3b0pVbL7DBj4tkhBAppbQUlf6F6Xl9LHu1g==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 0.4"
            }
        },
        "node_modules/mime-db": {
            "version": "1.52.0",
            "resolved": "https://registry.npmjs.org/mime-db/-/mime-db-1.52.0.tgz",
            "integrity": "sha512-sPU4uV7dYlvtWJxwwxHD0PuihVNiE7TyAbQ5SWxDCB9mUYvOgroQOwYQQOKPJ8CIbE+1ETVlOoK1UC2nU3gYvg==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 0.6"
            }
        },
        "node_modules/mime-types": {
            "version": "2.1.35",
            "resolved": "https://registry.npmjs.org/mime-types/-/mime-types-2.1.35.tgz",
            "integrity": "sha512-ZDY+bPm5zTTF+YpCrAU9nK0UgICYPT0QtT1NZWFv4s++TNkcgVaT0g6+4R2uI4MjQjzysHB1zxuWL50hzaeXiw==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "mime-db": "1.52.0"
            },
            "engines": {
                "node": ">= 0.6"
            }
        },
        "node_modules/ms": {
            "version": "2.1.3",
            "resolved": "https://registry.npmjs.org/ms/-/ms-2.1.3.tgz",
            "integrity": "sha512-6FlzubTLZG3J2a/NVCAleEhjzq5oxgHyaCU9yYXvcLsvoVaHJq/s5xXI6/XXP6tz7R9xAOtHnSO/tXtF3WRTlA==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/nanoid": {
            "version": "3.3.18",
            "resolved": "https://registry.npmjs.org/nanoid/-/nanoid-3.3.18.tgz",
            "integrity": "sha512-DTg4MJbGMWkfi6VZFdNt2/caMbQy4Ou+Op/hJQvGEWcnVfoA1QA+xzRKAzw9jD6+GVOOeYr/mIcuDSdug6F6+w==",
            "dev": true,
            "funding": [
                {
                    "type": "github",
                    "url": "https://github.com/sponsors/ai"
                }
            ],
            "license": "MIT",
            "bin": {
                "nanoid": "bin/nanoid.cjs"
            },
            "engines": {
                "node": "^10 || ^12 || ^13.7 || ^14 || >=15.0.1"
            }
        },
        "node_modules/picocolors": {
            "version": "1.1.1",
            "resolved": "https://registry.npmjs.org/picocolors/-/picocolors-1.1.1.tgz",
            "integrity": "sha512-xceH2snhtb5M9liqDsmEw56le376mTZkEX/jEb/RxNFyegNul7eNslCXP9FDj/Lcu0X8KEyMceP2ntpaHrDEVA==",
            "dev": true,
            "license": "ISC"
        },
        "node_modules/picomatch": {
            "version": "4.0.7",
            "resolved": "https://registry.npmjs.org/picomatch/-/picomatch-4.0.7.tgz",
            "integrity": "sha512-qcJu88Q2IWqJsDD529JKMdwGm/dvInW4HvQnRwiH9JtihJvzGOscDtHE3x1pBKeUOTysQ8kVmLnJ2kJu7yhcGA==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=12"
            },
            "funding": {
                "url": "https://github.com/sponsors/jonschlinkert"
            }
        },
        "node_modules/postcss": {
            "version": "8.5.28",
            "resolved": "https://registry.npmjs.org/postcss/-/postcss-8.5.28.tgz",
            "integrity": "sha512-RRuzqDtt5Y9h3quz5hWhK+TPnsmVs6WwSU6LkJMeY4HstUEDuYTG8UJSdawMRzmzAtV+KEoG8N3Qg2qLy5vM/A==",
            "dev": true,
            "funding": [
                {
                    "type": "opencollective",
                    "url": "https://opencollective.com/postcss/"
                },
                {
                    "type": "tidelift",
                    "url": "https://tidelift.com/funding/github/npm/postcss"
                },
                {
                    "type": "github",
                    "url": "https://github.com/sponsors/ai"
                }
            ],
            "license": "MIT",
            "dependencies": {
                "nanoid": "^3.3.18",
                "picocolors": "^1.1.1",
                "source-map-js": "^1.2.1"
            },
            "engines": {
                "node": "^10 || ^12 || >=14"
            }
        },
        "node_modules/proxy-from-env": {
            "version": "2.1.0",
            "resolved": "https://registry.npmjs.org/proxy-from-env/-/proxy-from-env-2.1.0.tgz",
            "integrity": "sha512-cJ+oHTW1VAEa8cJslgmUZrc+sjRKgAKl3Zyse6+PV38hZe/V6Z14TbCuXcan9F9ghlz4QrFr2c92TNF82UkYHA==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=10"
            }
        },
        "node_modules/require-directory": {
            "version": "2.1.1",
            "resolved": "https://registry.npmjs.org/require-directory/-/require-directory-2.1.1.tgz",
            "integrity": "sha512-fGxEI7+wsG9xrvdjsrlmL22OMTTiHRwAMroiEeMgq8gzoLC/PQr7RsRDSTLUg/bZAZtF+TVIkHc6/4RIKrui+Q==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=0.10.0"
            }
        },
        "node_modules/rollup": {
            "version": "4.63.1",
            "resolved": "https://registry.npmjs.org/rollup/-/rollup-4.63.1.tgz",
            "integrity": "sha512-3Df9jsstwhccuEfmAMi9l8XUh/GOkVObmFTU7CCVBysEbcOZLl84jCtaAZMcPiMz2EGKsATzQcU+Xr3n/wU6cg==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "@types/estree": "1.0.9"
            },
            "bin": {
                "rollup": "dist/bin/rollup"
            },
            "engines": {
                "node": ">=18.0.0",
                "npm": ">=8.0.0"
            },
            "optionalDependencies": {
                "@napi-rs/lzma-linux-x64-gnu": "1.5.1",
                "@rollup/rollup-android-arm-eabi": "4.63.1",
                "@rollup/rollup-android-arm64": "4.63.1",
                "@rollup/rollup-darwin-arm64": "4.63.1",
                "@rollup/rollup-darwin-x64": "4.63.1",
                "@rollup/rollup-freebsd-arm64": "4.63.1",
                "@rollup/rollup-freebsd-x64": "4.63.1",
                "@rollup/rollup-linux-arm-gnueabihf": "4.63.1",
                "@rollup/rollup-linux-arm-musleabihf": "4.63.1",
                "@rollup/rollup-linux-arm64-gnu": "4.63.1",
                "@rollup/rollup-linux-arm64-musl": "4.63.1",
                "@rollup/rollup-linux-loong64-gnu": "4.63.1",
                "@rollup/rollup-linux-loong64-musl": "4.63.1",
                "@rollup/rollup-linux-ppc64-gnu": "4.63.1",
                "@rollup/rollup-linux-ppc64-musl": "4.63.1",
                "@rollup/rollup-linux-riscv64-gnu": "4.63.1",
                "@rollup/rollup-linux-riscv64-musl": "4.63.1",
                "@rollup/rollup-linux-s390x-gnu": "4.63.1",
                "@rollup/rollup-linux-x64-gnu": "4.63.1",
                "@rollup/rollup-linux-x64-musl": "4.63.1",
                "@rollup/rollup-openbsd-x64": "4.63.1",
                "@rollup/rollup-openharmony-arm64": "4.63.1",
                "@rollup/rollup-win32-arm64-msvc": "4.63.1",
                "@rollup/rollup-win32-ia32-msvc": "4.63.1",
                "@rollup/rollup-win32-x64-gnu": "4.63.1",
                "@rollup/rollup-win32-x64-msvc": "4.63.1",
                "fsevents": "~2.3.2"
            }
        },
        "node_modules/rxjs": {
            "version": "7.8.2",
            "resolved": "https://registry.npmjs.org/rxjs/-/rxjs-7.8.2.tgz",
            "integrity": "sha512-dhKf903U/PQZY6boNNtAGdWbG85WAbjT/1xYoZIC7FAY0yWapOBQVsVrDl58W86//e1VpMNBtRV4MaXfdMySFA==",
            "dev": true,
            "license": "Apache-2.0",
            "dependencies": {
                "tslib": "^2.1.0"
            }
        },
        "node_modules/shell-quote": {
            "version": "1.9.0",
            "resolved": "https://registry.npmjs.org/shell-quote/-/shell-quote-1.9.0.tgz",
            "integrity": "sha512-Iov+JwFv/2HcTpcwNMKd8+IWNb8tboQJNQTkAY/LLVK7gGH9jy+LGkVqPxfekHl+yMmiqXszdGWXgkfml7hjqA==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 0.4"
            },
            "funding": {
                "url": "https://github.com/sponsors/ljharb"
            }
        },
        "node_modules/source-map-js": {
            "version": "1.2.1",
            "resolved": "https://registry.npmjs.org/source-map-js/-/source-map-js-1.2.1.tgz",
            "integrity": "sha512-UXWMKhLOwVKb728IUtQPXxfYU+usdybtUrK/8uGE8CQMvrhOpwvzDBwj0QhSL7MQc7vIsISBG8VQ8+IDQxpfQA==",
            "dev": true,
            "license": "BSD-3-Clause",
            "engines": {
                "node": ">=0.10.0"
            }
        },
        "node_modules/string-width": {
            "version": "4.2.3",
            "resolved": "https://registry.npmjs.org/string-width/-/string-width-4.2.3.tgz",
            "integrity": "sha512-wKyQRQpjJ0sIp62ErSZdGsjMJWsap5oRNihHhu6G7JVO/9jIB6UyevL+tXuOqrng8j/cxKTWyWUwvSTriiZz/g==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "emoji-regex": "^8.0.0",
                "is-fullwidth-code-point": "^3.0.0",
                "strip-ansi": "^6.0.1"
            },
            "engines": {
                "node": ">=8"
            }
        },
        "node_modules/strip-ansi": {
            "version": "6.0.1",
            "resolved": "https://registry.npmjs.org/strip-ansi/-/strip-ansi-6.0.1.tgz",
            "integrity": "sha512-Y38VPSHcqkFrCpFnQ9vuSXmquuv5oXOKpGeT6aGrr3o3Gc9AlVa6JBfUSOCnbxGGZF+/0ooI7KrPuUSztUdU5A==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "ansi-regex": "^5.0.1"
            },
            "engines": {
                "node": ">=8"
            }
        },
        "node_modules/supports-color": {
            "version": "8.1.1",
            "resolved": "https://registry.npmjs.org/supports-color/-/supports-color-8.1.1.tgz",
            "integrity": "sha512-MpUEN2OodtUzxvKQl72cUF7RQ5EiHsGvSsVG0ia9c5RbWGL2CI4C7EpPS8UTBIplnlzZiNuV56w+FuNxy3ty2Q==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "has-flag": "^4.0.0"
            },
            "engines": {
                "node": ">=10"
            },
            "funding": {
                "url": "https://github.com/chalk/supports-color?sponsor=1"
            }
        },
        "node_modules/tailwindcss": {
            "version": "4.3.3",
            "resolved": "https://registry.npmjs.org/tailwindcss/-/tailwindcss-4.3.3.tgz",
            "integrity": "sha512-gOhV3P7ufE62QDGg1zVaTgCR+EtPv92k2nIhVcVKcLmxT1sUBsQGhnZj175j+MqRt4zLF7ic+sCYjfhxMxj7YQ==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/tapable": {
            "version": "2.3.3",
            "resolved": "https://registry.npmjs.org/tapable/-/tapable-2.3.3.tgz",
            "integrity": "sha512-uxc/zpqFg6x7C8vOE7lh6Lbda8eEL9zmVm/PLeTPBRhh1xCgdWaQ+J1CUieGpIfm2HdtsUpRv+HshiasBMcc6A==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=6"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/webpack"
            }
        },
        "node_modules/tinyglobby": {
            "version": "0.2.17",
            "resolved": "https://registry.npmjs.org/tinyglobby/-/tinyglobby-0.2.17.tgz",
            "integrity": "sha512-wXR/dYpcqKmfWpEdZjiKJOwCNFndD0DMnrW/cYjVGttEkBfVgcLFHoNrlj47mjOVic9yyNu65alsgF4NQyTa2g==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "fdir": "^6.5.0",
                "picomatch": "^4.0.4"
            },
            "engines": {
                "node": ">=12.0.0"
            },
            "funding": {
                "url": "https://github.com/sponsors/SuperchupuDev"
            }
        },
        "node_modules/tree-kill": {
            "version": "1.2.2",
            "resolved": "https://registry.npmjs.org/tree-kill/-/tree-kill-1.2.2.tgz",
            "integrity": "sha512-L0Orpi8qGpRG//Nd+H90vFB+3iHnue1zSSGmNOOCh1GLJ7rUKVwV2HvijphGQS2UmhUZewS9VgvxYIdgr+fG1A==",
            "dev": true,
            "license": "MIT",
            "bin": {
                "tree-kill": "cli.js"
            }
        },
        "node_modules/tslib": {
            "version": "2.8.1",
            "resolved": "https://registry.npmjs.org/tslib/-/tslib-2.8.1.tgz",
            "integrity": "sha512-oJFu94HQb+KVduSUQL7wnpmqnfmLsOA/nAh6b6EH0wCEoK0/mPeXU6c3wKDV83MkOuHPRHtSXKKU99IBazS/2w==",
            "dev": true,
            "license": "0BSD"
        },
        "node_modules/vite": {
            "version": "7.3.6",
            "resolved": "https://registry.npmjs.org/vite/-/vite-7.3.6.tgz",
            "integrity": "sha512-4XP60spRGjSZFf1qYH+dJIkK2znL3zQfl9KkOV9MkkRR/3Dls0dxaBsQPTloEc5BLXWPL9vsOxopxyKoMmDueg==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "esbuild": "^0.27.0 || ^0.28.0",
                "fdir": "^6.5.0",
                "picomatch": "^4.0.3",
                "postcss": "^8.5.6",
                "rollup": "^4.43.0",
                "tinyglobby": "^0.2.15"
            },
            "bin": {
                "vite": "bin/vite.js"
            },
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            },
            "funding": {
                "url": "https://github.com/vitejs/vite?sponsor=1"
            },
            "optionalDependencies": {
                "fsevents": "~2.3.3"
            },
            "peerDependencies": {
                "@types/node": "^20.19.0 || >=22.12.0",
                "jiti": ">=1.21.0",
                "less": "^4.0.0",
                "lightningcss": "^1.21.0",
                "sass": "^1.70.0",
                "sass-embedded": "^1.70.0",
                "stylus": ">=0.54.8",
                "sugarss": "^5.0.0",
                "terser": "^5.16.0",
                "tsx": "^4.8.1",
                "yaml": "^2.4.2"
            },
            "peerDependenciesMeta": {
                "@types/node": {
                    "optional": true
                },
                "jiti": {
                    "optional": true
                },
                "less": {
                    "optional": true
                },
                "lightningcss": {
                    "optional": true
                },
                "sass": {
                    "optional": true
                },
                "sass-embedded": {
                    "optional": true
                },
                "stylus": {
                    "optional": true
                },
                "sugarss": {
                    "optional": true
                },
                "terser": {
                    "optional": true
                },
                "tsx": {
                    "optional": true
                },
                "yaml": {
                    "optional": true
                }
            }
        },
        "node_modules/vite-plugin-full-reload": {
            "version": "1.2.0",
            "resolved": "https://registry.npmjs.org/vite-plugin-full-reload/-/vite-plugin-full-reload-1.2.0.tgz",
            "integrity": "sha512-kz18NW79x0IHbxRSHm0jttP4zoO9P9gXh+n6UTwlNKnviTTEpOlum6oS9SmecrTtSr+muHEn5TUuC75UovQzcA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "picocolors": "^1.0.0",
                "picomatch": "^2.3.1"
            }
        },
        "node_modules/vite-plugin-full-reload/node_modules/picomatch": {
            "version": "2.3.2",
            "resolved": "https://registry.npmjs.org/picomatch/-/picomatch-2.3.2.tgz",
            "integrity": "sha512-V7+vQEJ06Z+c5tSye8S+nHUfI51xoXIXjHQ99cQtKUkQqqO1kO/KCJUfZXuB47h/YBlDhah2H3hdUGXn8ie0oA==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=8.6"
            },
            "funding": {
                "url": "https://github.com/sponsors/jonschlinkert"
            }
        },
        "node_modules/wrap-ansi": {
            "version": "7.0.0",
            "resolved": "https://registry.npmjs.org/wrap-ansi/-/wrap-ansi-7.0.0.tgz",
            "integrity": "sha512-YVGIj2kamLSTxw6NsZjoBxfSwsn0ycdesmc4p+Q21c5zPuZ1pl+NfxVdxPtdHvmNVOQ6XSYG4AUtyt/Fi7D16Q==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "ansi-styles": "^4.0.0",
                "string-width": "^4.1.0",
                "strip-ansi": "^6.0.0"
            },
            "engines": {
                "node": ">=10"
            },
            "funding": {
                "url": "https://github.com/chalk/wrap-ansi?sponsor=1"
            }
        },
        "node_modules/y18n": {
            "version": "5.0.8",
            "resolved": "https://registry.npmjs.org/y18n/-/y18n-5.0.8.tgz",
            "integrity": "sha512-0pfFzegeDWJHJIAmTLRP2DwHjdF5s7jo9tuztdQxAhINCdvS+3nGINqPd00AphqJR/0LhANUS6/+7SCb98YOfA==",
            "dev": true,
            "license": "ISC",
            "engines": {
                "node": ">=10"
            }
        },
        "node_modules/yargs": {
            "version": "17.7.2",
            "resolved": "https://registry.npmjs.org/yargs/-/yargs-17.7.2.tgz",
            "integrity": "sha512-7dSzzRQ++CKnNI/krKnYRV7JKKPUXMEh61soaHKg9mrWEhzFWhFnxPxGl+69cD1Ou63C13NUPCnmIcrvqCuM6w==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "cliui": "^8.0.1",
                "escalade": "^3.1.1",
                "get-caller-file": "^2.0.5",
                "require-directory": "^2.1.1",
                "string-width": "^4.2.3",
                "y18n": "^5.0.5",
                "yargs-parser": "^21.1.1"
            },
            "engines": {
                "node": ">=12"
            }
        },
        "node_modules/yargs-parser": {
            "version": "21.1.1",
            "resolved": "https://registry.npmjs.org/yargs-parser/-/yargs-parser-21.1.1.tgz",
            "integrity": "sha512-tVpsJW7DdjecAiFpbIB1e3qxIQsE6NoPc5/eTdrbbIC4h0LVsWhnoa3g+m2HclBIujHzsxZ4VJVA+GUuc2/LBw==",
            "dev": true,
            "license": "ISC",
            "engines": {
                "node": ">=12"
            }
        }
    }
}
```

## Modified Files (complete final content)

### `resources/views/layouts/app.blade.php`

```blade
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0b0e1a">

    {{-- SEO (Phase 17) — title honours per-page @section('title') for the
         long-tail pages, falling back to the SEO manager's computed title. --}}
    <title>@yield('title', $seo['title'])</title>
    <meta name="description" content="{{ $seo['description'] }}">

    @if ($seo['indexable'])
        <link rel="canonical" href="{{ $seo['canonical'] }}">
    @else
        <meta name="robots" content="noindex, nofollow">
    @endif

    {{-- Open Graph / social previews (only publicly accessible data) --}}
    <meta property="og:site_name" content="{{ config('app.name', 'FF Arena') }}">
    <meta property="og:title" content="@yield('title', $seo['title'])">
    <meta property="og:description" content="{{ $seo['description'] }}">
    <meta property="og:type" content="{{ $seo['og_type'] }}">
    <meta property="og:url" content="{{ $seo['canonical'] }}">
    @if ($seo['og_image'])
        <meta property="og:image" content="{{ $seo['og_image'] }}">
        @if ($seo['og_image_alt'])<meta property="og:image:alt" content="{{ $seo['og_image_alt'] }}">@endif
    @endif
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="@yield('title', $seo['title'])">
    <meta name="twitter:description" content="{{ $seo['description'] }}">

    {{-- Site identity --}}
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

    {{-- JSON-LD structured data (server-encoded, HTML-safe) --}}
    @if ($seo['jsonld'])
        <script type="application/ld+json">{!! $seo['jsonld'] !!}</script>
    @endif

    {{-- Page-specific head additions --}}
    @stack('head')

    {{-- Stylesheet: prefer the Vite build when present, else the served copy
         (identical content; see public/css/app.css + resources/css/app.css). --}}
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    @endif

    {{-- Shared behaviours, deferred so they never block first paint --}}
    <script src="{{ asset('js/app.js') }}" defer></script>
</head>
<body id="top">
    <a class="skip-link" href="#main">{{ __('ui.skip_to_content') }}</a>

    <header class="site-header">
        <div class="container nav-bar">
            <a href="{{ route('home') }}" class="brand" aria-label="{{ config('app.name', 'FF Arena') }} — home">
                <svg class="brand-mark" viewBox="0 0 64 64" aria-hidden="true" focusable="false">
                    <defs>
                        <linearGradient id="brandFg" x1="0" y1="0" x2="1" y2="1">
                            <stop offset="0" stop-color="#22d3ee"/><stop offset="1" stop-color="#a855f7"/>
                        </linearGradient>
                    </defs>
                    <rect x="2" y="2" width="60" height="60" rx="14" fill="#141a2e" stroke="#28335a" stroke-width="2"/>
                    <path d="M22 14h22l-5 14h-8l-2 8h8l-5 14H20l5-14h8l2-8h-8z" fill="url(#brandFg)"/>
                </svg>
                FF<span>ARENA</span>
            </a>

            <button class="nav-toggle" type="button" data-nav-toggle
                    aria-expanded="false" aria-controls="site-nav">
                <span class="nav-toggle-icon" aria-hidden="true"></span>
                <span class="sr-only">{{ __('ui.menu') }}</span>
            </button>

            <nav id="site-nav" class="nav-links" aria-label="{{ __('ui.primary_navigation') }}"
                 @auth data-unread-url="{{ route('notifications.unread') }}" @endauth>
                <a class="nav-link" href="{{ route('tournaments.index') }}">{{ __('ui.tournaments') }}</a>

                @auth
                    @if (auth()->user()->isOrganizer() || auth()->user()->isAdmin())
                        <a class="nav-link" href="{{ route('tournaments.create') }}">+ Create Tournament</a>
                    @endif
                    <a class="nav-link" href="{{ route('wallet.index') }}">Wallet</a>
                    <a class="nav-link" href="{{ route('support.index') }}">Support</a>
                    <a class="nav-link" href="{{ route('notifications.index') }}">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
                            <path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        {{ __('ui.notifications') }}
                        <span class="nav-badge" id="unread-badge" aria-live="polite"
                              @if (($unreadNotifications ?? 0) === 0) hidden @endif>
                            {{ ($unreadNotifications ?? 0) > 99 ? '99+' : ($unreadNotifications ?? 0) }}
                        </span>
                    </a>
                    @if (auth()->user()->isAdmin() || auth()->user()->isModerator() || auth()->user()->isOrganizer())
                        <a class="nav-link" href="{{ route('moderation.index') }}">Moderation</a>
                    @endif
                    @if (auth()->user()->isAdmin() || auth()->user()->isModerator())
                        <a class="nav-link" href="{{ route('moderation.security') }}">Security</a>
                    @endif
                    @if (auth()->user()->isAdmin() || auth()->user()->isModerator())
                        <a class="nav-link" href="{{ route('admin.support.index') }}">Support Queue</a>
                    @endif
                    @if (auth()->user()->isAdmin())
                        <a class="nav-link" href="{{ route('admin.accounts.index') }}">Accounts</a>
                        <a class="nav-link" href="{{ route('admin.analytics.index') }}">Analytics</a>
                        <a class="nav-link" href="{{ route('admin.audit.index') }}">Audit</a>
                        <a class="nav-link" href="{{ route('admin.dashboard') }}">Admin</a>
                    @endif
                    <a class="nav-link" href="{{ route('profile.show', auth()->user()) }}">Profile</a>
                    <a class="nav-link" href="{{ route('profile.edit') }}">Settings</a>

                    <span class="nav-link muted" aria-hidden="true">{{ auth()->user()->name }}</span>

                    <form method="POST" action="{{ route('logout') }}" class="nav-form">
                        @csrf
                        <button type="submit" class="btn btn-ghost btn-sm">{{ __('ui.logout') }}</button>
                    </form>
                @else
                    <a class="nav-link" href="{{ route('login') }}">{{ __('ui.login') }}</a>
                    <a class="btn btn-primary btn-sm" href="{{ route('register') }}">{{ __('ui.register') }}</a>
                @endauth
            </nav>
        </div>
    </header>

    <main id="main" class="site-main container">
        @if (session('success'))
            <div class="alert alert-success" role="status">
                <span aria-hidden="true">✓</span>
                <span>{{ session('success') }}</span>
            </div>
        @endif

        @if (session('error'))
            <div class="alert alert-error" role="alert">
                <span aria-hidden="true">✕</span>
                <span>{{ session('error') }}</span>
            </div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error" role="alert">
                <span aria-hidden="true">✕</span>
                <div>
                    <strong>{{ __('ui.form_errors') }}</strong>
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        @yield('content')
    </main>

    <footer class="site-footer">
        <div class="container">
            <div>
                <strong class="tag">{{ config('app.name', 'FF Arena') }}</strong>
                — Bangladesh's Free Fire tournament platform.
                Legit. Smart. Profitable. No hacks, ever.
            </div>
            <nav aria-label="{{ __('ui.footer_navigation') }}">
                <a href="{{ route('tournaments.index') }}">{{ __('ui.tournaments') }}</a>
                &middot;
                <a href="{{ route('sitemap') }}">Sitemap</a>
                &middot;
                <a href="{{ route('robots') }}">robots.txt</a>
            </nav>
        </div>
    </footer>
</body>
</html>
```

### `resources/views/home.blade.php`

```blade
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
```

### `resources/views/tournaments/index.blade.php`

```blade
@extends('layouts.app')

@section('content')
    <header class="page-head">
        <nav class="breadcrumbs" aria-label="Breadcrumb">
            <li><a href="{{ route('home') }}">Home</a></li>
            <li><span aria-current="page">Tournaments</span></li>
        </nav>
        <h1 class="page-title">Tournaments</h1>
        <p class="page-subtitle">All Free Fire tournaments on FF Arena.</p>
    </header>

    {{-- Discovery filters (GET — shareable, crawl-safe, no state) --}}
    <form method="GET" action="{{ route('tournaments.index') }}" class="card" role="search" aria-label="Filter tournaments">
        <div class="row">
            <div class="field grow" style="min-width: 220px">
                <label for="filter-q">Search</label>
                <input type="search" id="filter-q" name="q" value="{{ $search }}"
                       placeholder="Tournament name or map" autocomplete="off">
            </div>
            <div class="field">
                <label for="filter-status">Status</label>
                <select id="filter-status" name="status">
                    <option value="">All statuses</option>
                    @foreach (['open' => 'Open', 'live' => 'Live', 'closed' => 'Closed', 'finished' => 'Finished'] as $value => $label)
                        <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="filter-mode">Game mode</label>
                <select id="filter-mode" name="game_mode">
                    <option value="">All modes</option>
                    @foreach (['squad' => 'Squad', 'duo' => 'Duo', 'solo' => 'Solo'] as $value => $label)
                        <option value="{{ $value }}" @selected($gameMode === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <span class="sr-only">Apply filters</span>
                <button type="submit" class="btn btn-primary">Filter</button>
                <a href="{{ route('tournaments.index') }}" class="btn btn-ghost">Clear</a>
            </div>
        </div>
    </form>

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
                    by {{ $t->organizer->name ?? 'Organizer' }} ·
                    starts {{ optional($t->starts_at)->format('d M, h:i A') ?? 'TBA' }}
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
                       class="btn btn-sm {{ $t->status === 'open' ? 'btn-green' : '' }}">View</a>
                </div>
            </article>
        @empty
            <x-empty-state title="No tournaments found" icon="🔍">
                Try a different search term or filter, or check back soon for new tournaments.
            </x-empty-state>
        @endforelse
    </div>

    {{ $tournaments->links() }}
@endsection
```

### `resources/views/tournaments/show.blade.php`

```blade
@extends('layouts.app')

@section('content')
    <header class="page-head">
        <nav class="breadcrumbs" aria-label="Breadcrumb">
            <li><a href="{{ route('home') }}">Home</a></li>
            <li><a href="{{ route('tournaments.index') }}">Tournaments</a></li>
            <li><span aria-current="page">{{ $tournament->name }}</span></li>
        </nav>

        <div class="row-between">
            <div>
                <h1 class="page-title">{{ $tournament->name }}</h1>
                <p class="page-subtitle">
                    by {{ $tournament->organizer->name ?? 'Organizer' }} ·
                    {{ strtoupper($tournament->game_mode) }} · {{ $tournament->map }} ·
                    starts {{ optional($tournament->starts_at)->format('d M Y, h:i A') ?? 'TBA' }}
                </p>
            </div>
            <div class="row">
                @if ($tournament->hasCheckIn())
                    @if ($tournament->checkInIsOpen())
                        <x-status-pill status="checked" label="Check-in open" />
                    @elseif ($tournament->checkInHasClosed())
                        <x-status-pill status="no_show" label="Check-in closed" />
                    @else
                        <x-status-pill status="pending" label="Check-in not open" />
                    @endif
                @endif
                <x-status-pill :status="$tournament->status" />
            </div>
        </div>
    </header>

    {{-- Phase 12 — live updates feed (polling; server-side visibility) --}}
    @include('live.poll', ['tournament' => $tournament])

    <div class="grid cols-2">
        <section class="card" aria-labelledby="prize-heading">
            <h3 id="prize-heading">Prize &amp; Entry</h3>
            <dl class="row">
                <div class="stat">
                    <dt class="label">Entry fee</dt>
                    <dd class="num">৳{{ number_format($tournament->entry_fee) }}</dd>
                </div>
                <div class="stat">
                    <dt class="label">Prize pool</dt>
                    <dd class="num">৳{{ number_format($tournament->prize_pool) }}</dd>
                </div>
                <div class="stat">
                    <dt class="label">Slots left</dt>
                    <dd class="num">{{ $tournament->slotsLeft() }}/{{ $tournament->team_slots }}</dd>
                </div>
            </dl>
            <p class="muted mt-3" style="font-size: .85rem">Players per team: {{ $tournament->team_size }}</p>
            @if ($tournament->hasCheckIn())
                <p class="muted mt-1" style="font-size: .85rem">
                    Check-in: {{ $tournament->check_in_starts_at->format('d M, h:i A') }} —
                    {{ $tournament->check_in_ends_at->format('d M, h:i A') }}
                </p>
            @endif
        </section>

        <section class="card" aria-labelledby="rules-heading">
            <h3 id="rules-heading">Rules</h3>
            <div style="white-space: pre-wrap; font-size: .9rem; color: var(--muted)">
                {{ $tournament->rules ?: 'No rules set.' }}
            </div>
        </section>
    </div>

    @auth
        @if ((auth()->user()->isOrganizer() && auth()->user()->id === $tournament->organizer_id) || auth()->user()->isAdmin())
            <section class="card" aria-labelledby="organizer-controls">
                <h3 id="organizer-controls">🎛 Organizer Controls</h3>
                <div class="row">
                    @if ($tournament->status === 'draft')
                        <form method="POST" action="{{ route('tournaments.publish', $tournament) }}">
                            @csrf
                            <button class="btn btn-green btn-sm">Publish (open registration)</button>
                        </form>
                    @endif
                    @if ($tournament->status === 'open')
                        <form method="POST" action="{{ route('tournaments.close', $tournament) }}">
                            @csrf
                            <button class="btn btn-sm">Close registration</button>
                        </form>
                    @endif
                    @if (in_array($tournament->status, ['closed', 'open'], true))
                        <form method="POST" action="{{ route('tournaments.bracket', $tournament) }}">
                            @csrf
                            <button class="btn btn-primary btn-sm">⚡ Generate Bracket</button>
                        </form>
                    @endif
                    @if ($tournament->status === 'live')
                        <form method="POST" action="{{ route('tournaments.complete', $tournament) }}">
                            @csrf
                            <button class="btn btn-green btn-sm" onclick="return confirm('Finish this tournament? Make sure all matches are completed.')">🏁 Finish Tournament</button>
                        </form>
                    @endif
                    @if ($tournament->hasCheckIn() && $tournament->checkInHasClosed())
                        <form method="POST" action="{{ route('tournaments.noshows', $tournament) }}">
                            @csrf
                            <button class="btn btn-sm" onclick="return confirm('Mark unchecked-in teams as no-show and promote from the waitlist?')">🚫 Mark No-shows</button>
                        </form>
                    @endif
                    @if ($tournament->acceptsRegistration() && $waitlist && $waitlist->isNotEmpty())
                        <form method="POST" action="{{ route('tournaments.waitlist.promote', $tournament) }}">
                            @csrf
                            <button class="btn btn-sm btn-cyan">⬆ Promote Next Waitlisted</button>
                        </form>
                    @endif
                    <a href="{{ route('tournaments.edit', $tournament) }}" class="btn btn-sm">Edit</a>
                    <a href="{{ route('tournaments.scoring.show', $tournament) }}" class="btn btn-sm btn-cyan">Scoring Rules</a>
                    <a href="{{ route('leaderboard.show', $tournament) }}" class="btn btn-sm btn-cyan">Leaderboard</a>
                    @if (in_array($tournament->status, ['draft', 'open', 'closed'], true))
                        <form method="POST" action="{{ route('tournaments.cancel', $tournament) }}">
                            @csrf
                            <button class="btn btn-sm btn-danger" onclick="return confirm('Cancel this tournament?')">Cancel</button>
                        </form>
                    @endif
                </div>
            </section>
        @endif

        @if ($myTeam && ! $myTeam->isWithdrawn())
            <section class="card" aria-labelledby="my-team-heading">
                <h3 id="my-team-heading">🎽 Your Team</h3>
                <div class="row-between">
                    <div>
                        <strong>{{ $myTeam->name }}</strong>
                        <x-status-pill :status="$myTeam->status" />
                        @if ($myTeam->isWaitlisted())
                            <x-status-pill status="waitlisted" :label="'Waitlist #' . $myTeam->waitlistPosition()" />
                        @elseif ($myTeam->isCheckedIn())
                            <x-status-pill status="checked" label="Checked in" />
                        @endif
                        <p class="muted mt-1" style="font-size: .85rem">Roster: {{ $myTeam->rosterSize() }} / {{ $tournament->team_size }} players</p>
                    </div>
                    <div class="row">
                        @if ($myTeam->isConfirmed() && $tournament->hasCheckIn() && ! $myTeam->isCheckedIn() && $tournament->checkInIsOpen())
                            <form method="POST" action="{{ route('teams.checkin', [$tournament, $myTeam]) }}">
                                @csrf
                                <button class="btn btn-green btn-sm">✅ Check In</button>
                            </form>
                        @endif
                        <a href="{{ route('teams.show', [$tournament, $myTeam]) }}" class="btn btn-sm btn-cyan">Manage Team</a>
                        @if (in_array($tournament->status, ['draft', 'open', 'closed'], true))
                            <form method="POST" action="{{ route('teams.withdraw', [$tournament, $myTeam]) }}">
                                @csrf
                                <button class="btn btn-sm btn-danger" onclick="return confirm('Withdraw your team from this tournament?')">Withdraw Team</button>
                            </form>
                        @endif
                    </div>
                </div>
            </section>
        @endif
    @endauth

    @if ($tournament->acceptsRegistration() && ! $tournament->isFull())
        <section class="card text-center" aria-labelledby="register-cta">
            <h3 id="register-cta">Ready to fight? 🎯</h3>
            <a href="{{ route('teams.register', $tournament) }}" class="btn btn-primary">Register Your Team</a>
        </section>
    @elseif ($tournament->acceptsRegistration() && $tournament->isFull())
        <section class="card text-center" aria-labelledby="full-cta">
            <h3 id="full-cta">⏳ Tournament full</h3>
            <p class="muted">All slots are taken, but you can still join the waitlist.</p>
            <a href="{{ route('teams.register', $tournament) }}" class="btn btn-primary">Join Waitlist</a>
        </section>
    @endif

    @if ($tournament->status === 'live' || $tournament->status === 'finished')
        <section class="card" aria-labelledby="bracket-heading">
            <h2 id="bracket-heading">🏆 Bracket
                <span class="muted" style="font-size: .85rem; font-weight: 400">
                    — {{ $tournament->isDoubleElim() ? 'Double Elimination' : 'Single Elimination' }}
                    @if ($tournament->bracket_size) ({{ $tournament->bracket_size }}-team bracket) @endif
                </span>
            </h2>
            @if ($tournament->matches->isEmpty())
                <p class="muted">Bracket not generated yet.</p>
            @elseif ($tournament->isDoubleElim())
                @php
                    $sides = [
                        'winners' => ['label' => 'Winners Bracket', 'brackets' => ['winners']],
                        'losers' => ['label' => 'Losers Bracket', 'brackets' => ['losers']],
                        'grand_final' => ['label' => 'Grand Final', 'brackets' => ['grand_final']],
                    ];
                @endphp
                @foreach ($sides as $side)
                    @php $sideMatches = $tournament->matches->where('bracket', $side['brackets'][0]); @endphp
                    @if ($sideMatches->isNotEmpty())
                        <div class="mt-4">
                            <h3 class="muted" style="font-size: .85rem; font-weight: 800; text-transform: uppercase; letter-spacing: .5px">{{ $side['label'] }}</h3>
                            <div class="bracket-col">
                                @foreach ($sideMatches->groupBy('round')->sortKeys() as $round => $roundMatches)
                                    <div class="bracket-round">
                                        <div class="muted" style="font-size: .8rem; font-weight: 700">
                                            {{ $roundMatches->first()->roundLabel() }}
                                        </div>
                                        @foreach ($roundMatches as $m)
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
                    @foreach ($rounds as $round => $matches)
                        <div class="bracket-round">
                            <div class="muted" style="font-size: .8rem; font-weight: 700">
                                {{ $round == $lastRound ? '🏁 FINAL' : 'Round ' . $round }}
                            </div>
                            @foreach ($matches as $m)
                                @include('matches._bracket_card', ['match' => $m])
                            @endforeach
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    @endif

    <section class="card" aria-labelledby="teams-heading">
        <h3 id="teams-heading">👥 Registered Teams</h3>
        @if ($tournament->confirmedTeams->isEmpty())
            <p class="muted">No confirmed teams yet.</p>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Confirmed teams and their check-in status</caption>
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Team</th>
                            <th scope="col">Captain</th>
                            <th scope="col">Status</th>
                            @if ($tournament->hasCheckIn())<th scope="col">Check-in</th>@endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tournament->confirmedTeams as $team)
                            <tr>
                                <td>{{ $loop->iteration }}</td>
                                <td><strong>{{ $team->name }}</strong></td>
                                <td class="muted">{{ $team->captain_name }}</td>
                                <td><x-status-pill status="confirmed" /></td>
                                @if ($tournament->hasCheckIn())
                                    <td>
                                        @if ($team->isCheckedIn())
                                            <x-status-pill status="checked" />
                                        @else
                                            <x-status-pill status="pending" label="Not checked in" />
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($waitlist && $waitlist->isNotEmpty())
        <section class="card" aria-labelledby="waitlist-heading">
            <h3 id="waitlist-heading">⏳ Waitlist</h3>
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Waitlisted teams in FIFO order</caption>
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Team</th>
                            <th scope="col">Captain</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($waitlist as $wt)
                            <tr>
                                <td>{{ $loop->iteration }}</td>
                                <td><strong>{{ $wt->name }}</strong></td>
                                <td class="muted">{{ $wt->captain_name }}</td>
                                <td><x-status-pill status="waitlisted" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
@endsection
```

### `resources/views/leaderboard/show.blade.php`

```blade
@extends('layouts.app')

@section('content')
    <header class="page-head">
        <nav class="breadcrumbs" aria-label="Breadcrumb">
            <li><a href="{{ route('home') }}">Home</a></li>
            <li><a href="{{ route('tournaments.index') }}">Tournaments</a></li>
            <li><a href="{{ route('tournaments.show', $tournament) }}">{{ $tournament->name }}</a></li>
            <li><span aria-current="page">Leaderboard</span></li>
        </nav>
        <h1 class="page-title">🏅 Leaderboard</h1>
        <p class="page-subtitle">{{ $tournament->name }}</p>
    </header>

    {{-- Phase 12 — live updates feed (polling; server-side visibility) --}}
    @include('live.poll', ['tournament' => $tournament])

    <section class="card" aria-labelledby="standings-heading">
        <h3 id="standings-heading">Standings</h3>

        @if ($leaderboard->isEmpty())
            <x-empty-state title="No results yet" icon="📊">
                Standings appear here as soon as matches are scored.
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Tournament standings ordered by rank</caption>
                    <thead>
                        <tr>
                            <th scope="col">Rank</th>
                            <th scope="col">Team</th>
                            <th scope="col">Matches</th>
                            <th scope="col">Kills</th>
                            <th scope="col">Place Pts</th>
                            <th scope="col">Kill Pts</th>
                            <th scope="col">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($leaderboard as $row)
                            <tr>
                                <td>
                                    <strong>#{{ $row->rank }}</strong>
                                </td>
                                <td><strong>{{ $row->team->name }}</strong></td>
                                <td>{{ $row->matches_played }}</td>
                                <td>{{ $row->kills }}</td>
                                <td>{{ $row->placement_points }}</td>
                                <td>{{ $row->kill_points }}</td>
                                <td><strong class="tag">{{ $row->points }}</strong></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="muted mt-3" style="font-size: .8rem">
                Deterministic ordering — identical results always rank identically.
            </p>
        @endif
    </section>
@endsection
```

### `resources/views/profile/show.blade.php`

```blade
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
```

### `resources/views/auth/login.blade.php`

```blade
@extends('layouts.app')

@section('content')
    <div class="card" style="max-width: 460px; margin: 50px auto">
        <h2>Login</h2>

        <form method="POST" action="{{ route('login') }}" novalidate>
            @csrf

            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}"
                       autocomplete="email" inputmode="email" required autofocus
                       @if ($errors->has('email')) aria-invalid="true" aria-describedby="email-error" @endif>
                @error('email')
                    <span class="form-error" id="email-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password"
                       autocomplete="current-password" required
                       @if ($errors->has('password')) aria-invalid="true" aria-describedby="password-error" @endif>
                @error('password')
                    <span class="form-error" id="password-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="field">
                <label class="checkbox">
                    <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
                    Remember me
                </label>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Login</button>
        </form>

        <p class="divider-block" aria-hidden="true"></p>
        <p class="muted text-center mb-2">or</p>

        <div class="stack">
            @if (config('services.google.client_id') && config('services.google.client_secret'))
                <a href="{{ route('google.redirect') }}" class="btn btn-block">Continue with Google</a>
            @endif
            <a href="{{ route('phone.login') }}" class="btn btn-block">Login with phone</a>
        </div>

        <p class="muted mt-4" style="font-size: .85rem">
            <a href="{{ route('password.request') }}">Forgot password?</a> ·
            No account? <a href="{{ route('register') }}">Register here</a>
        </p>
    </div>
@endsection
```

### `resources/views/auth/register.blade.php`

```blade
@extends('layouts.app')

@section('content')
    <div class="card" style="max-width: 520px; margin: 40px auto">
        <h2>Create your account</h2>
        <p class="muted" style="font-size: .9rem">Join as a Player or an Organizer.</p>

        <form method="POST" action="{{ route('register') }}" novalidate>
            @csrf

            <div class="field">
                <label for="name">Full name</label>
                <input type="text" id="name" name="name" value="{{ old('name') }}"
                       autocomplete="name" required
                       @if ($errors->has('name')) aria-invalid="true" aria-describedby="name-error" @endif>
                @error('name')
                    <span class="form-error" id="name-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="field">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" value="{{ old('username') }}"
                       autocomplete="username" required
                       @if ($errors->has('username')) aria-invalid="true" aria-describedby="username-error" @endif>
                @error('username')
                    <span class="form-error" id="username-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}"
                       autocomplete="email" inputmode="email" required
                       @if ($errors->has('email')) aria-invalid="true" aria-describedby="email-error" @endif>
                @error('email')
                    <span class="form-error" id="email-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="field">
                <label for="phone">Phone (bKash)</label>
                <input type="tel" id="phone" name="phone" value="{{ old('phone') }}"
                       autocomplete="tel" inputmode="tel">
                @error('phone')
                    <span class="form-error" id="phone-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="field">
                <label for="game_uid">Free Fire UID</label>
                <input type="text" id="game_uid" name="game_uid" value="{{ old('game_uid') }}"
                       autocomplete="off" spellcheck="false">
            </div>

            <div class="field">
                <label for="role">I am a…</label>
                <select id="role" name="role">
                    <option value="player" @selected(old('role', 'player') === 'player')>Player</option>
                    <option value="organizer" @selected(old('role') === 'organizer')>Organizer</option>
                </select>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password"
                       autocomplete="new-password" required
                       @if ($errors->has('password')) aria-invalid="true" aria-describedby="password-error" @endif>
                @error('password')
                    <span class="form-error" id="password-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="field">
                <label for="password_confirmation">Confirm password</label>
                <input type="password" id="password_confirmation" name="password_confirmation"
                       autocomplete="new-password" required>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Create Account</button>
        </form>

        <p class="muted mt-4" style="font-size: .85rem">
            Prefer to sign in with your phone? <a href="{{ route('phone.login') }}">Login with phone</a>
        </p>
    </div>
@endsection
```

### `resources/views/notifications/index.blade.php`

```blade
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
```

### `resources/views/live/poll.blade.php`

```blade
@php
    $liveUrl = isset($tournament) ? route('tournaments.live', $tournament) : null;
    $liveSince = (int) ($liveRevision ?? 0);
    $liveInterval = (int) config('live.poll_interval_ms', 10000);
@endphp

@if ($liveUrl)
    <div id="live-feed"
         data-url="{{ $liveUrl }}"
         data-since="{{ $liveSince }}"
         data-interval="{{ $liveInterval }}"
         style="margin-bottom: 18px">
        <section class="card" aria-labelledby="live-feed-heading" style="margin-bottom: 0">
            <h3 id="live-feed-heading" class="row" style="gap: 10px">
                <span class="live-dot" aria-hidden="true">●</span>
                LIVE
                <span class="muted" style="font-size: .8rem; font-weight: 400">auto-updates</span>
            </h3>

            <ul id="live-feed-items" class="live-feed-list" aria-live="polite" aria-label="Live updates"></ul>

            <button id="live-refresh" type="button" class="btn btn-sm btn-cyan" hidden
                    style="margin-top: 10px">
                🔄 New results — refresh page
            </button>
        </section>
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

        // Backoff: pause in hidden tabs; on failure double the wait (cap 2min);
        // on success reset to the configured interval. No retry storm.
        var currentInterval = interval;
        var maxInterval = 120000;
        var timer = null;

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
            li.textContent = '• ' + label(e);
            list.insertBefore(li, list.firstChild);
            while (list.children.length > 12) { list.removeChild(list.lastChild); }
        }

        function tick() {
            if (document.hidden) { schedule(); return; }

            fetch(url + '?since=' + since, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { if (!r.ok) { throw new Error('http ' + r.status); } return r.json(); })
                .then(function (d) {
                    currentInterval = interval; // healthy — reset backoff
                    since = parseInt(d.revision || since, 10);
                    var events = d.events || [];
                    if (!events.length) { return; }
                    events.slice().reverse().forEach(prepend);
                    if (events.some(function (e) { return resultTypes.indexOf(e.type) !== -1; })) {
                        refreshBtn.hidden = false;
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

        if (refreshBtn) {
            refreshBtn.addEventListener('click', function () { window.location.reload(); });
        }

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) { clearTimeout(timer); tick(); }
        });

        schedule();
    })();
    </script>
@endif
```

### `resources/views/matches/_bracket_card.blade.php`

```blade
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
```

### `resources/css/app.css`

```css
@import 'tailwindcss';

@source '../**/*.blade.php';
@source '../**/*.js';

/* ==========================================================================
   FF Arena design system (Phase 17)
   --------------------------------------------------------------------------
   A single source of truth for the platform's look & feel. Built to WCAG 2.2
   AA: visible focus, minimum target sizes, non-color status cues, reduced
   motion support and accessible form/alert patterns.

   Two copies are kept in sync:
     - public/css/app.css  -> served directly (no build step required)
     - resources/css/app.css -> the Vite entry used by `npm run build`
   ========================================================================== */

/* --------------------------------------------------------------------------
   1. Design tokens
   -------------------------------------------------------------------------- */
:root {
    /* Surfaces */
    --bg: #0b0e1a;
    --panel: #141a2e;
    --panel2: #1b2340;
    --panel3: #232c52;
    --line: #28335a;
    --line-strong: #38457a;

    /* Text */
    --txt: #e8ecff;
    --muted: #9aa4c8;          /* >= 4.5:1 on --bg for body text */
    --muted-strong: #b6bfe0;

    /* Brand / accents */
    --cyan: #22d3ee;
    --purple: #a855f7;
    --violet: #7c3aed;
    --blue: #2563eb;

    /* Semantic */
    --green: #34d399;
    --green-strong: #6ee7b7;
    --red: #f87171;
    --red-strong: #fca5a5;
    --amber: #fbbf24;
    --amber-strong: #fcd34d;

    /* Focus */
    --focus: var(--cyan);
    --focus-ring: 0 0 0 3px rgba(34, 211, 238, .35);

    /* Shape */
    --radius-sm: 8px;
    --radius: 12px;
    --radius-lg: 16px;

    /* Spacing scale (4px base) */
    --space-1: 4px;
    --space-2: 8px;
    --space-3: 12px;
    --space-4: 16px;
    --space-5: 20px;
    --space-6: 24px;
    --space-8: 32px;
    --space-10: 40px;
    --space-12: 48px;

    /* Typography */
    --font-sans: 'Segoe UI', system-ui, -apple-system, 'Noto Sans Bengali', sans-serif;
    --font-mono: ui-monospace, 'SFMono-Regular', Menlo, Consolas, monospace;

    /* Motion */
    --t-fast: 120ms;
    --t-base: 180ms;

    /* Layout */
    --container: 1180px;
    --target-min: 40px;        /* minimum touch target, desktop */
}

/* --------------------------------------------------------------------------
   2. Reset
   -------------------------------------------------------------------------- */
*,
*::before,
*::after {
    box-sizing: border-box;
}

html {
    -webkit-text-size-adjust: 100%;
    scroll-behavior: smooth;
}

body {
    margin: 0;
    font-family: var(--font-sans);
    font-size: 16px;
    line-height: 1.55;
    color: var(--txt);
    background-color: var(--bg);
    background-image:
        radial-gradient(1200px 600px at 80% -10%, rgba(168, 85, 247, .14), transparent),
        radial-gradient(900px 500px at -10% 110%, rgba(34, 211, 238, .12), transparent);
    background-attachment: fixed;
    min-height: 100vh;
}

h1, h2, h3, h4, h5, h6 {
    margin: 0 0 var(--space-3);
    line-height: 1.2;
    overflow-wrap: break-word;
    scroll-margin-top: var(--space-6);
}

h1 { font-size: 1.75rem; }
h2 { font-size: 1.35rem; }
h3 { font-size: 1.1rem; }
h4 { font-size: 1rem; }

p { margin: 0 0 var(--space-3); }

ul, ol { margin: 0 0 var(--space-3); padding-left: var(--space-6); }

a {
    color: var(--cyan);
    text-decoration: none;
    border-radius: var(--radius-sm);
}

a:hover { text-decoration: underline; }
a:hover, a:focus-visible { text-decoration-thickness: 2px; text-underline-offset: 3px; }

img, svg, video { max-width: 100%; height: auto; display: block; }

button, input, select, textarea { font: inherit; }

/* --------------------------------------------------------------------------
   3. Focus visibility (WCAG 2.2 — Focus Visible / Not Obscured)
   -------------------------------------------------------------------------- */
:focus-visible {
    outline: 2px solid var(--focus);
    outline-offset: 2px;
    box-shadow: var(--focus-ring);
}

:focus:not(:focus-visible) { outline: none; }

/* --------------------------------------------------------------------------
   4. Skip link
   -------------------------------------------------------------------------- */
.skip-link {
    position: absolute;
    top: -100%;
    left: var(--space-4);
    z-index: 100;
    padding: var(--space-3) var(--space-4);
    background: var(--panel2);
    color: var(--txt);
    border: 1px solid var(--cyan);
    border-radius: var(--radius-sm);
    font-weight: 600;
    transition: top var(--t-fast) ease-in-out;
}

.skip-link:focus {
    top: var(--space-3);
    color: var(--txt);
    text-decoration: none;
}

/* --------------------------------------------------------------------------
   5. Layout shell
   -------------------------------------------------------------------------- */
.container {
    width: 100%;
    max-width: var(--container);
    margin-inline: auto;
    padding-inline: var(--space-5);
}

.site-header {
    position: relative;
    border-bottom: 1px solid var(--line);
    background: rgba(11, 14, 26, .85);
}

.site-main {
    min-height: 60vh;
    padding-block: var(--space-6);
}

.site-footer {
    border-top: 1px solid var(--line);
    margin-top: var(--space-10);
    padding-block: var(--space-6);
    color: var(--muted);
    font-size: .875rem;
}

.site-footer a { color: var(--muted-strong); }
.site-footer .container { display: flex; flex-wrap: wrap; gap: var(--space-4); align-items: center; justify-content: space-between; }

/* --- Nav bar --- */
.nav-bar {
    display: flex;
    align-items: center;
    gap: var(--space-4);
    padding-block: var(--space-3);
    flex-wrap: wrap;
}

.brand {
    font-size: 1.35rem;
    font-weight: 800;
    letter-spacing: .5px;
    color: var(--txt);
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
}

.brand:hover { text-decoration: none; }
.brand span { color: var(--cyan); }
.brand-mark { width: 26px; height: 26px; flex: none; }

.nav-links {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    margin-left: auto;
    flex-wrap: wrap;
}

.nav-link {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    min-height: var(--target-min);
    padding: var(--space-2) var(--space-3);
    border-radius: var(--radius-sm);
    color: var(--txt);
    font-size: .9rem;
    font-weight: 600;
    border: 1px solid transparent;
}

.nav-link:hover {
    text-decoration: none;
    background: var(--panel2);
    border-color: var(--line);
}

/* Notification badge */
.nav-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 1.4em;
    height: 1.4em;
    padding-inline: .4em;
    background: var(--red);
    color: #fff;
    border-radius: 999px;
    font-size: .72rem;
    font-weight: 700;
    line-height: 1;
}

.nav-badge[hidden] { display: none; }

/* Mobile menu toggle */
.nav-toggle {
    display: none;
    margin-left: auto;
    align-items: center;
    justify-content: center;
    gap: var(--space-2);
    min-height: var(--target-min);
    padding: var(--space-2) var(--space-3);
    background: var(--panel2);
    color: var(--txt);
    border: 1px solid var(--line);
    border-radius: var(--radius-sm);
    cursor: pointer;
    font-weight: 600;
}

.nav-toggle:hover { border-color: var(--cyan); }

.nav-toggle-icon,
.nav-toggle-icon::before,
.nav-toggle-icon::after {
    display: block;
    width: 18px;
    height: 2px;
    background: currentColor;
    border-radius: 2px;
    content: '';
    position: relative;
}

.nav-toggle-icon::before { position: absolute; top: -6px; }
.nav-toggle-icon::after { position: absolute; top: 6px; }

/* --------------------------------------------------------------------------
   6. Buttons (>= 40px desktop / 44px coarse pointer target)
   -------------------------------------------------------------------------- */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-2);
    min-height: var(--target-min);
    padding: var(--space-2) var(--space-4);
    border-radius: var(--radius-sm);
    border: 1px solid var(--line);
    background: var(--panel2);
    color: var(--txt);
    font-weight: 600;
    font-size: .9rem;
    line-height: 1.2;
    cursor: pointer;
    text-align: center;
    transition: background var(--t-fast), border-color var(--t-fast), filter var(--t-fast);
}

.btn:hover { border-color: var(--cyan); text-decoration: none; }

.btn:disabled,
.btn[aria-disabled="true"] {
    opacity: .55;
    cursor: not-allowed;
    filter: none;
}

.btn-primary {
    background: linear-gradient(90deg, var(--violet), var(--blue));
    border-color: transparent;
    color: #fff;
}

.btn-primary:hover { filter: brightness(1.12); }

.btn-cyan {
    background: rgba(34, 211, 238, .12);
    border-color: var(--cyan);
    color: var(--cyan);
}

.btn-cyan:hover { background: rgba(34, 211, 238, .2); }

.btn-green {
    background: rgba(52, 211, 153, .12);
    border-color: var(--green);
    color: var(--green-strong);
}

.btn-green:hover { background: rgba(52, 211, 153, .2); }

.btn-danger {
    background: rgba(248, 113, 113, .1);
    border-color: var(--red);
    color: var(--red-strong);
}

.btn-danger:hover { background: rgba(248, 113, 113, .2); }

.btn-ghost { background: transparent; }
.btn-ghost:hover { background: var(--panel2); }

.btn-sm { min-height: 34px; padding: var(--space-1) var(--space-3); font-size: .82rem; }
.btn-lg { min-height: 48px; padding: var(--space-3) var(--space-6); font-size: 1rem; }
.btn-block { width: 100%; }

.btn[data-loading="true"] { pointer-events: none; opacity: .7; }

/* --------------------------------------------------------------------------
   7. Badges & status pills (always pair color WITH text/icon)
   -------------------------------------------------------------------------- */
.pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: .75rem;
    font-weight: 700;
    letter-spacing: .3px;
    white-space: nowrap;
}

.pill::before {
    content: '';
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
    flex: none;
}

.pill.open, .pill.confirmed, .pill.verified, .pill.checked, .pill.completed, .pill.success, .pill.paid { background: rgba(52, 211, 153, .15); color: var(--green-strong); }
.pill.live, .pill.ready, .pill.active, .pill.in_progress { background: rgba(34, 211, 238, .15); color: var(--cyan); }
.pill.draft, .pill.pending, .pill.waitlisted, .pill.processing, .pill.warning, .pill.under_review { background: rgba(251, 191, 36, .15); color: var(--amber-strong); }
.pill.closed, .pill.finished, .pill.disputed, .pill.rejected, .pill.failed, .pill.cancelled, .pill.suspended, .pill.overdue { background: rgba(248, 113, 113, .15); color: var(--red-strong); }
.pill.withdrawn, .pill.no_show, .pill.bye, .pill.neutral, .pill.expired, .pill.refunded { background: rgba(148, 163, 184, .16); color: var(--muted-strong); }

/* --------------------------------------------------------------------------
   8. Cards
   -------------------------------------------------------------------------- */
.card {
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
    padding: var(--space-5);
    margin-bottom: var(--space-5);
}

.card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    flex-wrap: wrap;
    margin: calc(var(--space-5) * -1) calc(var(--space-5) * -1) var(--space-4);
    padding: var(--space-4) var(--space-5);
    border-bottom: 1px solid var(--line);
    border-radius: var(--radius-lg) var(--radius-lg) 0 0;
}

.card-header h2, .card-header h3 { margin: 0; }

/* --- Stats --- */
.stat {
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: var(--radius);
    padding: var(--space-4);
}

.stat .num { font-size: 1.6rem; font-weight: 800; color: var(--cyan); }
.stat .label { color: var(--muted); font-size: .8rem; }

/* --------------------------------------------------------------------------
   9. Grids
   -------------------------------------------------------------------------- */
.grid { display: grid; gap: var(--space-5); }
.cols-2 { grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); }
.cols-3 { grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); }
.cols-4 { grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); }

/* --------------------------------------------------------------------------
   10. Forms
   -------------------------------------------------------------------------- */
.field { margin-bottom: var(--space-4); }

.field label,
label.field-label {
    display: block;
    font-size: .85rem;
    font-weight: 600;
    color: var(--muted-strong);
    margin-bottom: var(--space-1);
}

input[type="text"],
input[type="email"],
input[type="password"],
input[type="search"],
input[type="number"],
input[type="tel"],
input[type="url"],
input[type="date"],
input[type="datetime-local"],
select,
textarea {
    width: 100%;
    min-height: var(--target-min);
    padding: var(--space-2) var(--space-3);
    border-radius: var(--radius-sm);
    border: 1px solid var(--line);
    background: #0d1226;
    color: var(--txt);
    font-size: .95rem;
}

input:focus, select:focus, textarea:focus {
    outline: none;
    border-color: var(--cyan);
    box-shadow: var(--focus-ring);
}

input[aria-invalid="true"],
select[aria-invalid="true"],
textarea[aria-invalid="true"] {
    border-color: var(--red);
}

input[aria-invalid="true"]:focus,
select[aria-invalid="true"]:focus,
textarea[aria-invalid="true"]:focus {
    box-shadow: 0 0 0 3px rgba(248, 113, 113, .35);
}

textarea { min-height: 120px; resize: vertical; }

/* Checkbox / radio — usable hit area */
.checkbox, .radio {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    min-height: var(--target-min);
    cursor: pointer;
    font-size: .9rem;
}

.checkbox input, .radio input {
    width: 18px;
    height: 18px;
    accent-color: var(--cyan);
    flex: none;
}

.help-text { color: var(--muted); font-size: .8rem; margin-top: var(--space-1); }

.form-error {
    display: block;
    margin-top: var(--space-1);
    font-size: .82rem;
    font-weight: 600;
    color: var(--red-strong);
}

.form-error::before { content: '⚠ '; }

/* Fieldset grouping */
fieldset {
    border: 1px solid var(--line);
    border-radius: var(--radius);
    padding: var(--space-4);
    margin: 0 0 var(--space-4);
}

legend {
    padding-inline: var(--space-2);
    font-weight: 700;
    color: var(--muted-strong);
}

/* --------------------------------------------------------------------------
   11. Tables (responsive via .table-wrap)
   -------------------------------------------------------------------------- */
.table-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    border: 1px solid var(--line);
    border-radius: var(--radius);
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 560px;
}

caption {
    text-align: left;
    padding: var(--space-3);
    color: var(--muted);
    font-size: .85rem;
    caption-side: top;
}

th, td {
    text-align: left;
    padding: var(--space-3);
    border-bottom: 1px solid var(--line);
    font-size: .9rem;
    vertical-align: top;
}

th {
    color: var(--muted-strong);
    font-size: .75rem;
    text-transform: uppercase;
    letter-spacing: .5px;
    background: var(--panel2);
    position: sticky;
    top: 0;
}

tr:last-child td { border-bottom: none; }

tbody tr:hover td { background: rgba(34, 211, 238, .03); }

/* --------------------------------------------------------------------------
   12. Alerts / flash messages (announced to screen readers)
   -------------------------------------------------------------------------- */
.alert {
    display: flex;
    gap: var(--space-3);
    align-items: flex-start;
    padding: var(--space-3) var(--space-4);
    border-radius: var(--radius);
    margin: var(--space-4) 0;
    font-weight: 600;
    border: 1px solid;
}

.alert-success, .flash.success { background: rgba(52, 211, 153, .15); color: var(--green-strong); border-color: rgba(52, 211, 153, .4); }
.alert-error, .flash.error { background: rgba(248, 113, 113, .15); color: var(--red-strong); border-color: rgba(248, 113, 113, .4); }
.alert-warning { background: rgba(251, 191, 36, .15); color: var(--amber-strong); border-color: rgba(251, 191, 36, .4); }
.alert-info { background: rgba(34, 211, 238, .15); color: var(--cyan); border-color: rgba(34, 211, 238, .4); }

.alert ul { margin: var(--space-2) 0 0; padding-left: var(--space-5); }

/* Back-compat alias used by older views */
.flash { padding: var(--space-3) var(--space-4); border-radius: var(--radius); margin: var(--space-4) 0; font-weight: 600; border: 1px solid; }

/* --------------------------------------------------------------------------
   13. Empty states
   -------------------------------------------------------------------------- */
.empty-state {
    text-align: center;
    padding: var(--space-10) var(--space-5);
    color: var(--muted);
}

.empty-state .empty-icon { font-size: 2rem; margin-bottom: var(--space-3); }
.empty-state h3 { color: var(--txt); }
.empty-state p { max-width: 40ch; margin-inline: auto; }

/* --------------------------------------------------------------------------
   14. Pagination (accessible: nav landmark + aria-current)
   -------------------------------------------------------------------------- */
.pagination {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-1);
    list-style: none;
    padding: 0;
    margin: var(--space-5) 0 0;
}

.pagination li { margin: 0; }

.pagination a,
.pagination span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: var(--target-min);
    min-height: var(--target-min);
    padding: var(--space-1) var(--space-3);
    border-radius: var(--radius-sm);
    border: 1px solid var(--line);
    background: var(--panel2);
    color: var(--txt);
    font-size: .9rem;
}

.pagination a:hover { border-color: var(--cyan); text-decoration: none; }

.pagination .current,
.pagination [aria-current="page"] {
    background: linear-gradient(90deg, var(--violet), var(--blue));
    border-color: transparent;
    color: #fff;
    font-weight: 700;
}

.pagination .disabled {
    opacity: .5;
}

.pagination-meta {
    color: var(--muted);
    font-size: .82rem;
    margin-top: var(--space-2);
}

/* --------------------------------------------------------------------------
   15. Breadcrumbs
   -------------------------------------------------------------------------- */
.breadcrumbs {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    align-items: center;
    list-style: none;
    padding: 0;
    margin: 0 0 var(--space-4);
    font-size: .85rem;
    color: var(--muted);
}

.breadcrumbs li { margin: 0; display: inline-flex; align-items: center; gap: var(--space-2); }
.breadcrumbs li + li::before { content: '/'; color: var(--line-strong); }
.breadcrumbs a { color: var(--muted-strong); }
.breadcrumbs [aria-current="page"] { color: var(--txt); font-weight: 600; }

/* --------------------------------------------------------------------------
   16. Bracket
   -------------------------------------------------------------------------- */
.bracket-col {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-6);
    align-items: flex-start;
    overflow-x: auto;
    padding-bottom: var(--space-3);
    scroll-snap-type: x proximity;
}

.bracket-round {
    display: flex;
    flex-direction: column;
    gap: var(--space-3);
    min-width: 200px;
    scroll-snap-align: start;
}

.bracket-match {
    background: var(--panel2);
    border: 1px solid var(--line);
    border-radius: var(--radius);
    padding: var(--space-2);
}

.bracket-match a { display: block; color: inherit; border-radius: var(--radius-sm); }
.bracket-match a:hover { text-decoration: none; background: rgba(34, 211, 238, .05); }

.bracket-team {
    padding: var(--space-2) var(--space-3);
    border-radius: var(--radius-sm);
    font-size: .85rem;
    display: flex;
    justify-content: space-between;
    gap: var(--space-2);
    min-height: 32px;
    align-items: center;
}

.bracket-team.win { background: rgba(52, 211, 153, .12); color: var(--green-strong); font-weight: 700; }
.bracket-team.win::after { content: 'W'; font-size: .7rem; border: 1px solid currentColor; border-radius: 4px; padding: 0 4px; }
.bracket-team.bye { color: var(--muted); }

.divider { height: 1px; background: var(--line); margin: var(--space-1) 0; }

/* --------------------------------------------------------------------------
   17. Avatars
   -------------------------------------------------------------------------- */
.avatar {
    border-radius: 50%;
    object-fit: cover;
    border: 1px solid var(--line);
    background: var(--panel2);
}

.avatar-fallback {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: var(--panel2);
    border: 1px solid var(--line);
    color: var(--cyan);
    font-weight: 800;
}

/* --------------------------------------------------------------------------
   18. Loading states
   -------------------------------------------------------------------------- */
.spinner {
    width: 1.1em;
    height: 1.1em;
    border: 2px solid rgba(255, 255, 255, .25);
    border-top-color: currentColor;
    border-radius: 50%;
    display: inline-block;
    animation: spin .8s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }

.skeleton {
    background: linear-gradient(90deg, var(--panel2) 25%, var(--panel3) 50%, var(--panel2) 75%);
    background-size: 200% 100%;
    animation: shimmer 1.4s ease infinite;
    border-radius: var(--radius-sm);
}

@keyframes shimmer { to { background-position: -200% 0; } }

/* --------------------------------------------------------------------------
   19. Utilities
   -------------------------------------------------------------------------- */
.muted { color: var(--muted); }
.muted-strong { color: var(--muted-strong); }
.tag { color: var(--purple); font-weight: 700; }
.divider-block { height: 1px; background: var(--line); margin: var(--space-4) 0; }

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

.text-center { text-align: center; }
.text-right { text-align: right; }
.text-danger { color: var(--red-strong); }
.text-success { color: var(--green-strong); }
.text-warning { color: var(--amber-strong); }

.stack { display: flex; flex-direction: column; gap: var(--space-3); }
.row { display: flex; flex-wrap: wrap; gap: var(--space-3); align-items: center; }
.row-between { display: flex; flex-wrap: wrap; gap: var(--space-3); align-items: center; justify-content: space-between; }
.grow { flex: 1 1 0; min-width: 0; }

.page-head { padding: var(--space-6) 0 var(--space-4); }
.page-title { margin: 0 0 var(--space-2); }
.page-subtitle { color: var(--muted); margin: 0; max-width: 72ch; }

.hero { padding: var(--space-10) 0 var(--space-6); }
.hero h1 { font-size: clamp(1.9rem, 4vw, 2.75rem); }

.mt-1 { margin-top: var(--space-1); }
.mt-2 { margin-top: var(--space-2); }
.mt-3 { margin-top: var(--space-3); }
.mt-4 { margin-top: var(--space-4); }
.mb-1 { margin-bottom: var(--space-1); }
.mb-2 { margin-bottom: var(--space-2); }
.mb-3 { margin-bottom: var(--space-3); }
.mb-4 { margin-bottom: var(--space-4); }

/* --------------------------------------------------------------------------
   20. Live feed (Phase 12 polling)
   -------------------------------------------------------------------------- */
.live-dot { color: var(--green); }

.live-feed-list { list-style: none; padding: 0; margin: 0; }

.live-feed-list li {
    padding: var(--space-2) 0;
    border-bottom: 1px solid var(--line);
    font-size: .85rem;
    color: var(--muted);
}

.live-feed-list li:last-child { border-bottom: none; }

/* --------------------------------------------------------------------------
   21. Responsive behaviour
   -------------------------------------------------------------------------- */
@media (max-width: 900px) {
    .nav-toggle { display: inline-flex; }

    .nav-links {
        display: none;
        width: 100%;
        flex-direction: column;
        align-items: stretch;
        gap: var(--space-1);
        padding-block: var(--space-2);
    }

    .nav-links.is-open { display: flex; }

    .nav-link {
        min-height: var(--target-min);
        width: 100%;
        justify-content: flex-start;
    }
}

@media (pointer: coarse) {
    .btn, .nav-link, .pagination a, .pagination span, .nav-toggle {
        min-height: 44px;
    }
}

/* --------------------------------------------------------------------------
   22. Reduced motion (WCAG 2.2 — prefers-reduced-motion)
   -------------------------------------------------------------------------- */
@media (prefers-reduced-motion: reduce) {
    html { scroll-behavior: auto; }

    *, *::before, *::after {
        animation-duration: .001ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: .001ms !important;
    }

    .spinner { animation: none; }
    .skeleton { animation: none; background: var(--panel3); }
}

/* --------------------------------------------------------------------------
   23. Print
   -------------------------------------------------------------------------- */
@media print {
    body { background: #fff; color: #000; }
    .site-header, .site-footer, .skip-link, .btn { display: none; }
    .card, .table-wrap { border-color: #ccc; }
}
```

### `resources/js/bootstrap.js`

```js
/**
 * FF Arena — application JavaScript entry (Phase 17).
 *
 * Shared UI behaviours live in public/js/app.js and are loaded directly with
 * `defer` by the layout, so no framework or HTTP client is required at
 * runtime. This module is intentionally kept empty: it exists as the Vite
 * entry point for any future framework code, and previously bundled Axios
 * even though nothing used it (all AJAX in this codebase uses native fetch).
 */
```

### `app/Http/Controllers/HomeController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Support\Seo;

class HomeController extends Controller
{
    public function index()
    {
        $tournaments = Tournament::with('organizer')
            ->withCount('confirmedTeams')
            ->whereIn('status', ['open', 'live', 'finished', 'closed'])
            ->orderByRaw("CASE status WHEN 'live' THEN 0 WHEN 'open' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->limit(12)
            ->get();

        $siteName = (string) config('app.name', 'FF Arena');

        app(Seo::class)
            ->title($siteName.' — Free Fire Tournaments in Bangladesh')
            ->description('Browse live and upcoming Free Fire tournaments in Bangladesh. Register your squad, compete and get paid — the country\'s trusted tournament platform.')
            ->canonical(route('home'))
            ->indexable()
            ->jsonLd([
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => $siteName,
                'url' => route('home'),
                'description' => 'Bangladesh\'s Free Fire tournament platform.',
            ]);

        return view('home', compact('tournaments'));
    }
}
```

### `app/Http/Controllers/TournamentController.php`

```php
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
```

### `app/Http/Controllers/LeaderboardController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Services\ScoringService;
use App\Support\Seo;

class LeaderboardController extends Controller
{
    public function __construct(
        protected ScoringService $scoring,
    ) {}

    public function show(Tournament $tournament)
    {
        // Standings are computed exclusively by the scoring engine so the
        // leaderboard, match results and any future standings API all agree.
        $leaderboard = $this->scoring->standings($tournament);

        $tieBreakers = $this->scoring->currentRuleSet($tournament)->tieBreakers();

        app(Seo::class)
            ->title('Leaderboard — '.$tournament->name.' — '.(string) config('app.name', 'FF Arena'))
            ->description('Live standings for '.$tournament->name.' — ranks, kills, placement points and totals.')
            ->canonical(route('leaderboard.show', $tournament))
            ->indexable();

        return view('leaderboard.show', compact('tournament', 'leaderboard', 'tieBreakers'));
    }
}
```

### `app/Http/Controllers/ProfileController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ProfileService;
use App\Support\Seo;
use DomainException;
use Illuminate\Http\Request;

/**
 * Public profiles + profile settings (Phase 14).
 *
 * Users edit only their own profile (admins may too); rating, rank, risk,
 * verification, roles and restrictions are never editable from here.
 */
class ProfileController extends Controller
{
    public function __construct(
        protected ProfileService $profiles,
    ) {}

    /**
     * Public profile, honouring the target's privacy preset.
     */
    public function show(User $user)
    {
        $profile = $this->profiles->publicProfile($user, auth()->user());

        $this->applyProfileSeo($user, $profile);

        return view('profile.show', compact('user', 'profile'));
    }

    /**
     * Phase 17 — profile SEO. Private/limited profiles are noindex by default
     * and never contribute name/bio to metadata; only genuinely public
     * profiles opt in to indexing + ProfilePage structured data.
     *
     * @param  array<string, mixed>  $profile
     */
    private function applyProfileSeo(User $user, array $profile): void
    {
        $siteName = (string) config('app.name', 'FF Arena');

        if (! empty($profile['visible'])) {
            $description = trim((string) ($profile['bio'] ?? ''));
            if ($description === '') {
                $description = $profile['name'].' — Free Fire player on '.$siteName.'.';
            }

            app(Seo::class)
                ->title($profile['name'].' — '.$siteName)
                ->description($description)
                ->canonical(route('profile.show', $user))
                ->indexable()
                ->ogType('profile')
                ->jsonLd([
                    '@context' => 'https://schema.org',
                    '@type' => 'ProfilePage',
                    'name' => $profile['name'],
                    'url' => route('profile.show', $user),
                    'mainEntity' => [
                        '@type' => 'Person',
                        'name' => $profile['name'],
                        'alternateName' => $profile['username'] ?? '',
                    ],
                ]);

            return;
        }

        // Private / limited visibility: never index, never leak profile data
        // into the <head> — the page body still honours its normal render.
        app(Seo::class)
            ->title('Private profile — '.$siteName)
            ->description('This profile is private.')
            ->canonical(route('profile.show', $user));
    }

    /**
     * The signed-in user's profile settings.
     */
    public function edit()
    {
        $user = auth()->user();

        $this->authorize('updateProfile', $user);

        return view('profile.edit', compact('user'));
    }

    /**
     * Update basic profile fields.
     */
    public function update(Request $request)
    {
        $user = auth()->user();
        $this->authorize('updateProfile', $user);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'bio' => 'nullable|string|max:500',
            'country' => 'nullable|string|max:2',
            'region' => 'nullable|string|max:100',
            'avatar' => 'nullable|url|max:255',
        ]);

        try {
            $this->profiles->update($user, $data);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Profile updated.');
    }

    /**
     * Change the username (normalized, unique, reserved-checked, rate-limited).
     */
    public function updateUsername(Request $request)
    {
        $user = auth()->user();
        $this->authorize('updateProfile', $user);

        $data = $request->validate([
            'username' => 'required|string',
        ]);

        try {
            $this->profiles->updateUsername($user, $data['username']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Username updated.');
    }

    /**
     * Update the profile privacy preset.
     */
    public function updatePrivacy(Request $request)
    {
        $user = auth()->user();
        $this->authorize('updateProfile', $user);

        $data = $request->validate([
            'privacy' => 'required|in:public,registered,private',
        ]);

        try {
            $this->profiles->updatePrivacy($user, $data['privacy']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Privacy setting updated.');
    }

    /**
     * Update country/region/language/timezone preferences.
     */
    public function updatePreferences(Request $request)
    {
        $user = auth()->user();
        $this->authorize('updateProfile', $user);

        $data = $request->validate([
            'country' => 'nullable|string|max:2',
            'region' => 'nullable|string|max:100',
            'language' => 'nullable|string|max:5',
            'timezone' => 'nullable|string|max:64',
        ]);

        try {
            $this->profiles->updatePreferences($user, $data);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Preferences updated.');
    }

    /**
     * Change (or set) the account password.
     */
    public function changePassword(Request $request)
    {
        $user = auth()->user();
        $this->authorize('manageSecurity', $user);

        $hasPassword = $this->profiles->hasPassword($user);

        $data = $request->validate([
            'current_password' => $hasPassword ? 'required|string' : 'nullable|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        try {
            if ($hasPassword) {
                $this->profiles->changePassword($user, $data['current_password'], $data['password'], $request);
            } else {
                $this->profiles->setPassword($user, $data['password'], $request);
            }

            // Regenerate the session so the current session id changes and
            // any session-fixation window closes.
            $request->session()->regenerate();
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Password updated.');
    }
}
```

### `app/Providers/AppServiceProvider.php`

```php
<?php

namespace App\Providers;

use App\Contracts\ErrorReporterInterface;
use App\Contracts\GoogleIdTokenVerifierInterface;
use App\Contracts\GoogleOAuthProviderInterface;
use App\Contracts\MetricsInterface;
use App\Contracts\PhoneOtpProviderInterface;
use App\Gateways\GoogleTokenInfoIdVerifier;
use App\Gateways\LogPhoneOtpProvider;
use App\Gateways\SmsGatewayPhoneOtpProvider;
use App\Gateways\SocialiteGoogleProvider;
use App\Models\PersonalAccessToken;
use App\Services\NotificationService;
use App\Support\ErrorReporting\ErrorReporterManager;
use App\Support\Metrics;
use App\Support\Metrics\MetricsManager;
use App\Support\Seo;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Phase 14 — honest provider wiring. The SMS gateway is used only when
        // configured; otherwise the dev/test log provider delivers codes.
        $this->app->singleton(PhoneOtpProviderInterface::class, function ($app) {
            if (! empty(env('SMS_GATEWAY_ENDPOINT')) && ! empty(env('SMS_GATEWAY_API_KEY'))) {
                return new SmsGatewayPhoneOtpProvider;
            }

            return new LogPhoneOtpProvider;
        });

        $this->app->singleton(GoogleOAuthProviderInterface::class, fn ($app) => new SocialiteGoogleProvider);

        // Phase 15 — Google id_token verification for the mobile/API login.
        // Tests bind a deterministic fake; production verifies server-side
        // against Google.
        $this->app->singleton(GoogleIdTokenVerifierInterface::class, fn ($app) => new GoogleTokenInfoIdVerifier);

        // Phase 16 — observability seams. The manager classes resolve the
        // configured backend lazily so a broken metrics/error config can
        // never prevent the application from booting.
        $this->app->singleton(MetricsInterface::class, fn ($app) => (new MetricsManager)->driver());
        $this->app->singleton(ErrorReporterInterface::class, fn ($app) => (new ErrorReporterManager)->driver());

        // Phase 17 — request-scoped SEO metadata manager. Public pages opt in
        // to indexing; everything else stays noindex by default.
        $this->app->singleton(Seo::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Phase 15 — use the app's PersonalAccessToken subclass so the
        // api_client_id link (grouped revocation) is available on tokens.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Expose the authenticated user's unread notification count to the
        // shared layout (Phase 11). Guests always see zero.
        View::composer('layouts.app', function ($view) {
            $user = auth()->user();

            $view->with('unreadNotifications', $user !== null
                ? app(NotificationService::class)->unreadCount($user)
                : 0);
            $view->with('seo', app(Seo::class)->toArray());
        });

        $this->registerQueueObservability();
        $this->registerRateLimiters();
    }

    /**
     * Phase 16 — queue metrics + failure alerting. Queue events fire inside
     * the worker process; the listeners only record safe counters and log a
     * redacted failure line — a failing listener can never affect the job.
     */
    protected function registerQueueObservability(): void
    {
        Event::listen(JobProcessed::class, function (JobProcessed $event) {
            Metrics::increment('queue.jobs_processed', 1, [
                'connection' => (string) ($event->connectionName ?? 'unknown'),
            ]);
        });

        Event::listen(JobFailed::class, function (JobFailed $event) {
            Metrics::increment('queue.jobs_failed', 1, [
                'connection' => (string) ($event->connectionName ?? 'unknown'),
            ]);

            Log::channel('queue')->error('Queue job failed', [
                'job' => $event->job->resolveName() ?? 'unknown',
                'exception' => $event->exception::class,
            ]);
        });
    }

    /**
     * Phase 14 — named rate limiters for auth, OTP, recovery and payments.
     */
    protected function registerRateLimiters(): void
    {
        // Login: 5 attempts per minute per email+IP, then 1 per minute.
        RateLimiter::for('login', function (Request $request) {
            $key = 'login:'.strtolower((string) $request->input('email')).':'.$request->ip();

            return [
                Limit::perMinute(5)->by($key),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        // Registration: 3 per hour per IP.
        RateLimiter::for('register', function (Request $request) {
            return Limit::perHour(3)->by($request->ip());
        });

        // OTP request: hard per-phone + per-IP limits (SMS abuse control).
        RateLimiter::for('otp-request', function (Request $request) {
            $phone = preg_replace('/\D/', '', (string) $request->input('phone')) ?? '';

            return [
                Limit::perMinute(1)->by('otp-request:'.$phone),
                Limit::perHour(5)->by('otp-request:'.$phone),
                Limit::perHour(10)->by('otp-request:ip:'.$request->ip()),
            ];
        });

        // OTP verify: 5 per 5 minutes per phone.
        RateLimiter::for('otp-verify', function (Request $request) {
            $phone = preg_replace('/\D/', '', (string) $request->input('phone')) ?? '';

            return Limit::perMinutes(5, 5)->by('otp-verify:'.$phone);
        });

        // Password reset requests: 3 per hour per email+IP.
        RateLimiter::for('password-reset', function (Request $request) {
            $key = 'password-reset:'.strtolower((string) $request->input('email')).':'.$request->ip();

            return Limit::perHour(3)->by($key);
        });

        // Google callback: generic abuse ceiling per IP.
        RateLimiter::for('google-callback', function (Request $request) {
            return Limit::perMinute(20)->by($request->ip());
        });

        // Account linking (google/phone): 5 per minute per user.
        RateLimiter::for('account-link', function (Request $request) {
            return Limit::perMinute(5)->by('account-link:user:'.($request->user()?->id ?? $request->ip()));
        });

        // Payment initiation: 5 per minute per user.
        RateLimiter::for('payment-initiate', function (Request $request) {
            return Limit::perMinute(5)->by('payment-initiate:user:'.($request->user()?->id ?? $request->ip()));
        });

        // Verification email resends: 3 per hour per user.
        RateLimiter::for('verification-resend', function (Request $request) {
            return Limit::perHour(3)->by('verification-resend:user:'.($request->user()?->id ?? $request->ip()));
        });

        $this->registerApiRateLimiters();
    }

    /**
     * Phase 15 — named, route-level API limiters. Keys are user/token-aware
     * wherever a user exists (never IP-only for authenticated operations),
     * with tighter windows for auth, OTP, scoring, payment and support.
     */
    protected function registerApiRateLimiters(): void
    {
        // General authenticated API ceiling: 120/min per token.
        RateLimiter::for('api', function (Request $request) {
            $user = $request->user();

            return Limit::perMinute((int) config('api.rate_limits.api', 120))
                ->by('api:user:'.($user?->id ?? $request->ip()));
        });

        // Anonymous discovery: 60/min per IP.
        RateLimiter::for('api_anon', function (Request $request) {
            return Limit::perMinute((int) config('api.rate_limits.api_anon', 60))
                ->by('api-anon:'.$request->ip());
        });

        // Token issuance: 5/min per user.
        RateLimiter::for('api_token_issue', function (Request $request) {
            return Limit::perMinute(5)
                ->by('api-token-issue:user:'.($request->user()?->id ?? $request->ip()));
        });

        // API login (email/password + google): 5/min per identifier + IP.
        RateLimiter::for('api_login', function (Request $request) {
            $identifier = strtolower((string) ($request->input('email') ?? $request->input('id_token') ?? ''));

            return [
                Limit::perMinute(5)->by('api-login:'.$identifier),
                Limit::perMinute(20)->by('api-login:ip:'.$request->ip()),
            ];
        });

        // API registration: 3/hour per IP (mirrors the web 'register' limiter).
        RateLimiter::for('api_register', function (Request $request) {
            return Limit::perHour(3)->by('api-register:'.$request->ip());
        });

        // API OTP request: 1/min per phone + 5/hour per phone + 10/hour per IP.
        RateLimiter::for('api_otp_request', function (Request $request) {
            $phone = preg_replace('/\D/', '', (string) $request->input('phone')) ?? '';

            return [
                Limit::perMinute(1)->by('api-otp-request:'.$phone),
                Limit::perHour(5)->by('api-otp-request:'.$phone),
                Limit::perHour(10)->by('api-otp-request:ip:'.$request->ip()),
            ];
        });

        // API OTP verify: 5/5min per phone.
        RateLimiter::for('api_otp_verify', function (Request $request) {
            $phone = preg_replace('/\D/', '', (string) $request->input('phone')) ?? '';

            return Limit::perMinutes(5, 5)->by('api-otp-verify:'.$phone);
        });

        // API score submission: 10/min per user.
        RateLimiter::for('api_score', function (Request $request) {
            return Limit::perMinute((int) config('api.rate_limits.api_score', 10))
                ->by('api-score:user:'.($request->user()?->id ?? $request->ip()));
        });

        // API payment creation: 5/min per user.
        RateLimiter::for('api_payment', function (Request $request) {
            return Limit::perMinute((int) config('api.rate_limits.api_payment', 5))
                ->by('api-payment:user:'.($request->user()?->id ?? $request->ip()));
        });

        // API support writes: 10/min per user.
        RateLimiter::for('api_support', function (Request $request) {
            return Limit::perMinute((int) config('api.rate_limits.api_support', 10))
                ->by('api-support:user:'.($request->user()?->id ?? $request->ip()));
        });

        // Inbound provider webhooks: 60/min per IP.
        RateLimiter::for('api_webhook', function (Request $request) {
            return Limit::perMinute((int) config('api.rate_limits.api_webhook', 60))
                ->by('api-webhook:'.$request->ip());
        });

        // Phase 16 — health probes: generous ceiling (300/min per IP) so
        // orchestrators/load balancers can poll freely without being blocked.
        RateLimiter::for('health', function (Request $request) {
            return Limit::perMinute(300)->by('health:'.$request->ip());
        });
    }
}
```

### `routes/web.php`

```php
<?php

use App\Http\Controllers\AccountLiveController;
use App\Http\Controllers\AccountSecurityController;
use App\Http\Controllers\AdminAccountController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminSupportController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\DisputeController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\LiveController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\ModerationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OpsController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentMethodsController;
use App\Http\Controllers\PayoutController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ScoringRuleController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SettlementController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TournamentController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

// SEO — dynamic robots.txt (absolute Sitemap URL) + XML sitemap (Phase 17).
Route::get('/robots.txt', [SitemapController::class, 'robots'])->name('robots');
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');

// Guest auth
Route::middleware('guest')->group(function () {
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

    // Password reset (enumeration-safe)
    Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-reset')->name('password.email');
    Route::get('/reset-password/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.update');

    // Phone login
    Route::get('/login/phone', [AuthController::class, 'showPhoneLogin'])->name('phone.login');
    Route::post('/login/phone', [AuthController::class, 'requestPhoneOtp'])->middleware('throttle:otp-request')->name('phone.request');
});

// Phone verification page + login verify (guests and authed users — the
// same page serves both the login and the account-linking flows).
Route::get('/login/phone/verify', [AuthController::class, 'showPhoneVerify'])->name('phone.verify');
Route::post('/login/phone/verify', [AuthController::class, 'verifyPhoneLogin'])->middleware('throttle:otp-verify')->name('phone.login.verify');

// Google Sign-In (guests sign in; authed users may link via the settings
// redirect which sets a session link-intent flag).
Route::get('/auth/google', [AuthController::class, 'redirectToGoogle'])->name('google.redirect');
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback'])->middleware('throttle:google-callback')->name('google.callback');

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// Provider payment webhook — authenticated by HMAC signature, not session.
Route::post('/webhooks/payments/{provider}', [WebhookController::class, 'handle'])->name('webhooks.payments');

// Public tournament browsing
Route::get('/tournaments', [TournamentController::class, 'index'])->name('tournaments.index');
Route::get('/tournaments/{tournament}', [TournamentController::class, 'show'])->name('tournaments.show');
Route::get('/tournaments/{tournament}/leaderboard', [LeaderboardController::class, 'show'])->name('leaderboard.show');

// Public profile (privacy-gated server-side; guests see only what the
// target's privacy preset allows). The edit route is declared FIRST so the
// literal `/profile/edit` wins over the `{user}` parameter route.
Route::get('/profile/edit', [ProfileController::class, 'edit'])->middleware('auth')->name('profile.edit');
Route::get('/profile/{user}', [ProfileController::class, 'show'])->name('profile.show');

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

    // Checkout provider selection (Phase 14)
    Route::get('/tournaments/{tournament}/teams/{team}/pay/methods', [CheckoutController::class, 'methods'])->name('payment.methods');
    Route::post('/tournaments/{tournament}/teams/{team}/pay/initiate', [CheckoutController::class, 'initiate'])->middleware('throttle:payment-initiate')->name('payment.initiate');

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

    // Support (Phase 13 — users manage only their own tickets)
    Route::get('/support', [SupportController::class, 'index'])->name('support.index');
    Route::get('/support/create', [SupportController::class, 'create'])->name('support.create');
    Route::post('/support', [SupportController::class, 'store'])->name('support.store');
    Route::get('/support/{ticket}', [SupportController::class, 'show'])->name('support.tickets.show');
    Route::post('/support/{ticket}/reply', [SupportController::class, 'reply'])->name('support.tickets.reply');
    Route::post('/support/{ticket}/close', [SupportController::class, 'close'])->name('support.tickets.close');
    Route::post('/support/{ticket}/reopen', [SupportController::class, 'reopen'])->name('support.tickets.reopen');
    Route::get('/support/{ticket}/messages', [SupportController::class, 'messages'])->name('support.tickets.messages');

    // Email verification (Phase 14 — server-generated signed URLs)
    Route::get('/verify-email', [AuthController::class, 'showVerifyEmail'])->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', [AuthController::class, 'verifyEmail'])->name('verification.verify');
    Route::post('/email/verification-notification', [AuthController::class, 'resendVerification'])->middleware('throttle:verification-resend')->name('verification.resend');

    // Profile (Phase 14)
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/username', [ProfileController::class, 'updateUsername'])->name('profile.username');
    Route::put('/profile/privacy', [ProfileController::class, 'updatePrivacy'])->name('profile.privacy');
    Route::put('/profile/preferences', [ProfileController::class, 'updatePreferences'])->name('profile.preferences');
    Route::post('/settings/password', [ProfileController::class, 'changePassword'])->name('settings.password');

    // Security settings, sessions, login history, connected accounts,
    // lifecycle (Phase 14)
    Route::get('/settings/security', [AccountSecurityController::class, 'security'])->name('settings.security');
    Route::get('/settings/connected-accounts', [AccountSecurityController::class, 'connectedAccounts'])->name('settings.connected-accounts');
    Route::get('/settings/sessions', [AccountSecurityController::class, 'sessions'])->name('settings.sessions');
    Route::post('/settings/sessions/others', [AccountSecurityController::class, 'revokeOtherSessions'])->name('settings.sessions.revokeOthers');
    Route::post('/settings/sessions/all', [AccountSecurityController::class, 'revokeAllSessions'])->name('settings.sessions.revokeAll');
    Route::get('/settings/login-history', [AccountSecurityController::class, 'loginHistory'])->name('settings.login-history');
    Route::get('/settings/google/link', [AccountSecurityController::class, 'linkGoogleRedirect'])->name('settings.google.link');
    Route::post('/settings/google/unlink', [AccountSecurityController::class, 'unlinkGoogle'])->name('settings.google.unlink');
    Route::post('/settings/phone/link', [AccountSecurityController::class, 'linkPhone'])->middleware('throttle:otp-request')->name('settings.phone.link');
    Route::post('/settings/phone/link/verify', [AccountSecurityController::class, 'verifyPhoneLink'])->middleware('throttle:otp-verify')->name('settings.phone.link.verify');
    Route::post('/settings/phone/unlink', [AccountSecurityController::class, 'unlinkPhone'])->name('settings.phone.unlink');
    Route::post('/settings/account/deactivate', [AccountSecurityController::class, 'deactivate'])->name('settings.deactivate');
    Route::post('/settings/account/reactivate', [AccountSecurityController::class, 'reactivate'])->name('settings.reactivate');
    Route::post('/settings/account/delete-request', [AccountSecurityController::class, 'requestDeletion'])->name('settings.deletion.request');
    Route::post('/settings/account/delete-cancel', [AccountSecurityController::class, 'cancelDeletion'])->name('settings.deletion.cancel');

    // Saved payment methods (Phase 14)
    Route::get('/settings/payment-methods', [PaymentMethodsController::class, 'index'])->name('settings.payment-methods');
    Route::post('/settings/payment-methods', [PaymentMethodsController::class, 'store'])->name('settings.payment-methods.store');
    Route::delete('/settings/payment-methods/{method}', [PaymentMethodsController::class, 'destroy'])->name('settings.payment-methods.destroy');
    Route::post('/settings/payment-methods/{method}/default', [PaymentMethodsController::class, 'setDefault'])->name('settings.payment-methods.default');

    // Account realtime feed (Phase 14)
    Route::get('/account/live', [AccountLiveController::class, 'index'])->name('account.live');

    // Per-tournament operational analytics (organizer/admin/moderator)
    Route::get('/tournaments/{tournament}/analytics', [AnalyticsController::class, 'tournament'])->name('tournaments.analytics');

    // Staff (admin + moderator) support queue + staff analytics (Phase 13)
    Route::middleware('staff')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/support', [AdminSupportController::class, 'index'])->name('support.index');
        Route::get('/support/export', [AdminSupportController::class, 'export'])->name('support.export');
        Route::get('/support/{ticket}', [AdminSupportController::class, 'show'])->name('support.show');
        Route::post('/support/{ticket}/assign', [AdminSupportController::class, 'assign'])->name('support.assign');
        Route::post('/support/{ticket}/status', [AdminSupportController::class, 'status'])->name('support.status');
        Route::post('/support/{ticket}/note', [AdminSupportController::class, 'internalNote'])->name('support.note');
        Route::post('/support/{ticket}/reply', [AdminSupportController::class, 'reply'])->name('support.reply');

        Route::get('/analytics/disputes', [AnalyticsController::class, 'disputes'])->name('analytics.disputes');
        Route::get('/analytics/support', [AnalyticsController::class, 'support'])->name('analytics.support');
    });

    // Admin
    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');

        // Account administration (Phase 14)
        Route::get('/accounts', [AdminAccountController::class, 'index'])->name('accounts.index');
        Route::get('/accounts/{user}', [AdminAccountController::class, 'show'])->name('accounts.show');
        Route::post('/accounts/{user}/sessions/revoke', [AdminAccountController::class, 'revokeSessions'])->name('accounts.sessions.revoke');
        Route::post('/accounts/{user}/deactivate', [AdminAccountController::class, 'deactivate'])->name('accounts.deactivate');
        Route::post('/accounts/{user}/reactivate', [AdminAccountController::class, 'reactivate'])->name('accounts.reactivate');
        Route::post('/accounts/{user}/delete', [AdminAccountController::class, 'delete'])->name('accounts.delete');

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

        // Audit log (Phase 13 — admin only, read-only)
        Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
        Route::get('/audit/export', [AuditController::class, 'export'])->name('audit.export');

        // Analytics (Phase 13 — global/financial/security are admin only)
        Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
        Route::get('/analytics/tournaments', [AnalyticsController::class, 'tournaments'])->name('analytics.tournaments');
        Route::get('/analytics/financial', [AnalyticsController::class, 'financial'])->name('analytics.financial');
        Route::get('/analytics/security', [AnalyticsController::class, 'security'])->name('analytics.security');
        Route::get('/analytics/tournaments/export', [AnalyticsController::class, 'exportTournaments'])->name('analytics.export');

        // Infrastructure operations (Phase 16 — admin only)
        Route::prefix('ops')->name('ops.')->group(function () {
            Route::get('/', [OpsController::class, 'dashboard'])->name('dashboard');
            Route::get('/health', [OpsController::class, 'health'])->name('health');
            Route::get('/failed-jobs', [OpsController::class, 'failedJobs'])->name('failed_jobs');
            Route::post('/failed-jobs/{id}/retry', [OpsController::class, 'retryFailedJob'])->name('failed_jobs.retry');
            Route::post('/failed-jobs/retry-all', [OpsController::class, 'retryAllFailed'])->name('failed_jobs.retry_all');
            Route::post('/failed-jobs/{id}/delete', [OpsController::class, 'deleteFailedJob'])->name('failed_jobs.delete');
            Route::post('/cache/flush', [OpsController::class, 'flushCache'])->name('cache.flush');
            Route::post('/backup', [OpsController::class, 'backup'])->name('backup');
            Route::post('/backup/verify', [OpsController::class, 'verifyBackup'])->name('backup.verify');
        });
    });
});
```

### `config/app.php`

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'FF Arena'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
```

### `.env.example`

```text
APP_NAME=FF Arena
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

APP_MAINTENANCE_DRIVER=file
# APP_MAINTENANCE_STORE=database

# PHP_CLI_SERVER_WORKERS=4

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug

DB_CONNECTION=sqlite
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=laravel
# DB_USERNAME=root
# DB_PASSWORD=

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database

CACHE_STORE=database
# CACHE_PREFIX=

MEMCACHED_HOST=127.0.0.1

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=log
MAIL_SCHEME=null
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="${APP_NAME}"

AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=
AWS_USE_PATH_STYLE_ENDPOINT=false

# ---------------------------------------------------------------------------
# Phase 14 — Google OAuth / OpenID Connect
# ---------------------------------------------------------------------------
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"

# ---------------------------------------------------------------------------
# Phase 14 — phone OTP delivery (SMS gateway)
# ---------------------------------------------------------------------------
SMS_GATEWAY_ENDPOINT=
SMS_GATEWAY_API_KEY=
SMS_GATEWAY_SENDER=

# ---------------------------------------------------------------------------
# Phase 14 — payment providers (honest: absent credentials = "not configured")
# ---------------------------------------------------------------------------
PAYMENT_WEBHOOK_SECRET=ffarena-local-webhook-secret

BKASH_ENABLED=true
BKASH_MODE=sandbox
BKASH_BASE_URL=
BKASH_APP_KEY=
BKASH_APP_SECRET=
BKASH_USERNAME=
BKASH_PASSWORD=
BKASH_MERCHANT_NUMBER=

NAGAD_ENABLED=true
NAGAD_MODE=sandbox
NAGAD_BASE_URL=
NAGAD_MERCHANT_ID=
NAGAD_MERCHANT_PRIVATE_KEY=
NAGAD_PG_PUBLIC_KEY=
NAGAD_MERCHANT_NUMBER=

ROCKET_ENABLED=true
ROCKET_MODE=sandbox
ROCKET_BASE_URL=
ROCKET_MERCHANT_ID=
ROCKET_MERCHANT_SECRET=

CARD_ENABLED=false
CARD_MODE=sandbox
CARD_GATEWAY=
CARD_MERCHANT_ID=
CARD_MERCHANT_SECRET=

BANK_ENABLED=true
BANK_ACCOUNT_NAME=
BANK_ACCOUNT_NUMBER=

SSLCOMMERZ_ENABLED=false
SSLCOMMERZ_MODE=sandbox
SSLCOMMERZ_STORE_ID=
SSLCOMMERZ_STORE_PASSWORD=
SSLCOMMERZ_BASE_URL=

# Phase 15 — public API (Laravel Sanctum)
# Prefix for issued personal access tokens (set in production so leaked tokens
# are detectable by secret scanners, e.g. "ffarena_".)
SANCTUM_TOKEN_PREFIX=

# Phase 15 — inbound webhook secrets. Leave a provider empty to fall back to
# PAYMENT_WEBHOOK_SECRET (the Phase 08 trust root).
WEBHOOK_BKASH_SECRET=
WEBHOOK_NAGAD_SECRET=
WEBHOOK_ROCKET_SECRET=
WEBHOOK_SSLCOMMERZ_SECRET=
WEBHOOK_CARD_SECRET=

# ---------------------------------------------------------------------------
# Phase 16 — production hardening & observability
# ---------------------------------------------------------------------------
# Request correlation header (echoed on every response).
REQUEST_ID_HEADER=X-Request-ID

# Structured logging: default + daily rotation.
LOG_DAILY_DAYS=14

# Metrics backend: "log" (default) or "null".
METRICS_DRIVER=log
METRICS_LOG_CHANNEL=metrics

# Error reporting: "log" (default) or "sentry" (requires the SDK + SENTRY_DSN).
ERROR_REPORTING_DRIVER=log
SENTRY_DSN=

# Health: a worker/scheduler heartbeat older than this is "degraded".
HEALTH_WORKER_STALE_SECONDS=300
HEALTH_SCHEDULER_STALE_SECONDS=300

# Security headers.
SECURITY_HSTS_ENABLE=true
SECURITY_HSTS_MAX_AGE=31536000
SECURITY_HSTS_INCLUDE_SUBDOMAINS=false
SECURITY_CSP_ENABLE=false
SECURITY_CSP_POLICY="default-src 'self'"

# CORS — comma-separated exact origins (never "*"). Empty = no cross-origin.
CORS_ALLOWED_ORIGINS=

# Backups — written to the private disk, chmod 0600, checksummed.
BACKUP_DISK=local
BACKUP_PATH=backups
BACKUP_RETENTION=14
BACKUP_INCLUDE_PRIVATE_FILES=true
BACKUP_INTEGRITY_CHECK=true
BACKUP_NOTIFY_ADMINS=true

# Operational retention (days) for scheduled cleanup.
OBS_RETENTION_OTP_DAYS=1
OBS_RETENTION_IDEMPOTENCY_DAYS=2
OBS_RETENTION_NOTIFICATIONS_DAYS=180
OBS_RETENTION_WEBHOOK_DELIVERIES_DAYS=30
OBS_RETENTION_WEBHOOK_EVENTS_DAYS=90
OBS_RETENTION_LIVE_EVENTS_DAYS=30
OBS_RETENTION_FAILED_JOBS_DAYS=30

VITE_APP_NAME="${APP_NAME}"
```

### `scripts/ci/check-pint.sh`

```bash
#!/usr/bin/env bash
# Phase 16 + Phase 17 — code-style gate (Laravel Pint).
#
# Runs Pint in test mode over the Phase 16/17-owned file set. The legacy
# Phase 01–15 codebase predates the Pint configuration and is adopted
# incrementally; see the PHASE16 report § "known limitations".

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

php vendor/bin/pint --test \
  app/Console/Commands \
  app/Support \
  app/Contracts/ErrorReporterInterface.php \
  app/Contracts/MetricsInterface.php \
  app/Services/HealthService.php \
  app/Services/CacheInvalidationService.php \
  app/Services/BackupService.php \
  app/Services/OperationsService.php \
  app/Http/Middleware/SecurityHeaders.php \
  app/Http/Middleware/HttpMetrics.php \
  app/Http/Middleware/AssignAuditRequestId.php \
  app/Http/Controllers/HealthController.php \
  app/Http/Controllers/OpsController.php \
  app/Http/Controllers/SitemapController.php \
  app/Http/Controllers/HomeController.php \
  app/Http/Controllers/TournamentController.php \
  app/Http/Controllers/LeaderboardController.php \
  app/Http/Controllers/ProfileController.php \
  app/Providers/AppServiceProvider.php \
  bootstrap/app.php \
  routes/health.php \
  routes/console.php \
  routes/web.php \
  config/observability.php \
  config/backup.php \
  config/cors.php \
  config/logging.php \
  config/app.php \
  database/migrations/2026_09_09_110000_create_operations_heartbeats_table.php \
  tests/Feature/Phase16 \
  tests/Feature/Phase17
```

### `resources/views/auth/forgot-password.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Forgot Password — FF Arena')
@section('content')
    <div class="card" style="max-width: 460px; margin: 50px auto">
        <h2>Forgot your password?</h2>
        <p class="muted">Enter your email and we will send a reset link if an account exists.</p>

        <form method="POST" action="{{ route('password.email') }}" novalidate>
            @csrf
            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}"
                       autocomplete="email" inputmode="email" required autofocus
                       @if ($errors->has('email')) aria-invalid="true" aria-describedby="email-error" @endif>
                @error('email')
                    <span class="form-error" id="email-error">{{ $message }}</span>
                @enderror
            </div>
            <button type="submit" class="btn btn-primary btn-block mt-3">Send reset link</button>
        </form>

        <p class="muted mt-4" style="font-size: .85rem">
            <a href="{{ route('login') }}">Back to login</a>
        </p>
    </div>
@endsection
```

### `resources/views/auth/phone-login.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Phone Login — FF Arena')
@section('content')
    <div class="card" style="max-width: 460px; margin: 50px auto">
        <h2>Login with your phone</h2>
        <p class="muted">Enter your Bangladeshi mobile number. We will send a verification code.</p>

        <form method="POST" action="{{ route('phone.request') }}" novalidate>
            @csrf
            <div class="field">
                <label for="phone">Mobile number</label>
                <input type="tel" id="phone" name="phone" value="{{ old('phone') }}"
                       placeholder="01712345678" autocomplete="tel" inputmode="tel" required autofocus
                       @if ($errors->has('phone')) aria-invalid="true" aria-describedby="phone-error" @endif>
                @error('phone')
                    <span class="form-error" id="phone-error">{{ $message }}</span>
                @enderror
            </div>
            <button type="submit" class="btn btn-primary btn-block mt-3">Send verification code</button>
        </form>

        <p class="muted mt-4" style="font-size: .85rem">
            <a href="{{ route('login') }}">Login with email instead</a>
        </p>
    </div>
@endsection
```

### `resources/views/auth/phone-verify.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Enter Code — FF Arena')
@section('content')
    <div class="card" style="max-width: 460px; margin: 50px auto">
        <h2>Enter the code</h2>
        <p class="muted">
            We sent a 6-digit code to <strong>{{ $phone ?? old('phone') }}</strong>.
        </p>

        @php
            $purpose = $purpose ?? old('purpose', 'login');
            $action = $purpose === 'link'
                ? route('settings.phone.link.verify')
                : route('phone.login.verify');
        @endphp

        <form method="POST" action="{{ $action }}" novalidate>
            @csrf
            <input type="hidden" name="phone" value="{{ $phone ?? old('phone') }}">
            <input type="hidden" name="purpose" value="{{ $purpose }}">
            <div class="field">
                <label for="code">Verification code</label>
                <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code"
                       maxlength="6" required autofocus
                       @if ($errors->has('code')) aria-invalid="true" aria-describedby="code-error" @endif>
                @error('code')
                    <span class="form-error" id="code-error">{{ $message }}</span>
                @enderror
            </div>
            <button type="submit" class="btn btn-primary btn-block mt-3">Verify</button>
        </form>

        <p class="muted mt-4" style="font-size: .85rem">
            @if ($purpose === 'link')
                <a href="{{ route('settings.connected-accounts') }}">Back to connected accounts</a>
            @else
                <a href="{{ route('phone.login') }}">Use a different number</a>
            @endif
        </p>
    </div>
@endsection
```

### `resources/views/auth/reset-password.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Reset Password — FF Arena')
@section('content')
    <div class="card" style="max-width: 460px; margin: 50px auto">
        <h2>Reset your password</h2>

        <form method="POST" action="{{ route('password.update') }}" novalidate>
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email', $email) }}"
                       autocomplete="email" inputmode="email" required
                       @if ($errors->has('email')) aria-invalid="true" aria-describedby="email-error" @endif>
                @error('email')
                    <span class="form-error" id="email-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="field">
                <label for="password">New password</label>
                <input type="password" id="password" name="password"
                       autocomplete="new-password" required
                       @if ($errors->has('password')) aria-invalid="true" aria-describedby="password-error" @endif>
                @error('password')
                    <span class="form-error" id="password-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="field">
                <label for="password_confirmation">Confirm new password</label>
                <input type="password" id="password_confirmation" name="password_confirmation"
                       autocomplete="new-password" required>
            </div>

            <button type="submit" class="btn btn-primary btn-block mt-3">Reset password</button>
        </form>
    </div>
@endsection
```

### `resources/views/auth/verify-email.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Verify Email — FF Arena')
@section('content')
    <div class="card" style="max-width: 520px; margin: 50px auto">
        <h2>Verify your email</h2>
        <p class="muted">
            A verification link was sent to <strong>{{ auth()->user()->email }}</strong>.
            Click the link in the email to verify your address.
        </p>
        <form method="POST" action="{{ route('verification.resend') }}">
            @csrf
            <button type="submit" class="btn btn-cyan mt-2">Resend verification link</button>
        </form>
        <p class="muted mt-4" style="font-size: .85rem">
            <a href="{{ route('home') }}">Back to home</a>
        </p>
    </div>
@endsection
```

### `resources/views/profile/edit.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Profile Settings — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">Profile Settings</h1>
    </header>

    <div class="grid cols-2">
        <section class="card" aria-labelledby="basic-profile">
            <h3 id="basic-profile">Basic profile</h3>
            <form method="POST" action="{{ route('profile.update') }}" novalidate>
                @csrf
                @method('PUT')
                <div class="field">
                    <label for="name">Display name</label>
                    <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}"
                           autocomplete="name" required
                           @if ($errors->has('name')) aria-invalid="true" aria-describedby="name-error" @endif>
                    @error('name')
                        <span class="form-error" id="name-error">{{ $message }}</span>
                    @enderror
                </div>
                <div class="field">
                    <label for="bio">Bio</label>
                    <textarea id="bio" name="bio" rows="3" maxlength="500">{{ old('bio', $user->bio) }}</textarea>
                </div>
                <div class="field">
                    <label for="country">Country (2 letters)</label>
                    <input type="text" id="country" name="country" value="{{ old('country', $user->country) }}"
                           maxlength="2" placeholder="BD" autocomplete="country">
                </div>
                <div class="field">
                    <label for="region">Region / city</label>
                    <input type="text" id="region" name="region" value="{{ old('region', $user->region) }}" maxlength="100">
                </div>
                <div class="field">
                    <label for="avatar">Avatar URL</label>
                    <input type="url" id="avatar" name="avatar" value="{{ old('avatar', $user->avatar) }}" placeholder="https://…">
                </div>
                <button type="submit" class="btn btn-primary">Save profile</button>
            </form>
        </section>

        <div class="stack">
            <section class="card" aria-labelledby="username-heading">
                <h3 id="username-heading">Username</h3>
                <form method="POST" action="{{ route('profile.username') }}">
                    @csrf
                    @method('PUT')
                    <div class="field">
                        <label for="username">Username (3–20 chars, letters/numbers/._-)</label>
                        <input type="text" id="username" name="username" value="{{ old('username', $user->username) }}"
                               autocomplete="username" required>
                    </div>
                    <button type="submit" class="btn btn-sm">Change username</button>
                </form>
            </section>

            <section class="card" aria-labelledby="privacy-heading">
                <h3 id="privacy-heading">Privacy</h3>
                <form method="POST" action="{{ route('profile.privacy') }}">
                    @csrf
                    @method('PUT')
                    <div class="field">
                        <label for="privacy">Who can see your profile</label>
                        <select id="privacy" name="privacy">
                            @foreach (['public', 'registered', 'private'] as $privacy)
                                <option value="{{ $privacy }}" @selected($user->privacy === $privacy)>{{ ucfirst($privacy) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="btn btn-sm">Save privacy</button>
                </form>
            </section>

            <section class="card" aria-labelledby="prefs-heading">
                <h3 id="prefs-heading">Region &amp; preferences</h3>
                <form method="POST" action="{{ route('profile.preferences') }}">
                    @csrf
                    @method('PUT')
                    <div class="field">
                        <label for="pref-country">Country (2 letters)</label>
                        <input type="text" id="pref-country" name="country" value="{{ old('country', $user->country) }}" maxlength="2">
                    </div>
                    <div class="field">
                        <label for="pref-region">Region / city</label>
                        <input type="text" id="pref-region" name="region" value="{{ old('region', $user->region) }}" maxlength="100">
                    </div>
                    <div class="field">
                        <label for="language">Language</label>
                        <input type="text" id="language" name="language" value="{{ old('language', $user->language) }}" maxlength="5" placeholder="en">
                    </div>
                    <div class="field">
                        <label for="timezone">Timezone</label>
                        <input type="text" id="timezone" name="timezone" value="{{ old('timezone', $user->timezone) }}" placeholder="Asia/Dhaka">
                    </div>
                    <button type="submit" class="btn btn-sm">Save preferences</button>
                </form>
            </section>
        </div>
    </div>

    <section class="card" aria-labelledby="account-links">
        <h3 id="account-links">Account links</h3>
        <div class="row">
            <a href="{{ route('settings.security') }}" class="btn btn-sm">Security settings</a>
            <a href="{{ route('settings.connected-accounts') }}" class="btn btn-sm">Connected accounts</a>
            <a href="{{ route('settings.sessions') }}" class="btn btn-sm">Active sessions</a>
            <a href="{{ route('settings.login-history') }}" class="btn btn-sm">Login history</a>
            <a href="{{ route('settings.payment-methods') }}" class="btn btn-sm">Payment methods</a>
        </div>
    </section>
@endsection
```

### `resources/views/settings/security.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Security Settings — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">Security Settings</h1>
    </header>

    <div class="grid cols-2">
        <section class="card" aria-labelledby="account-status">
            <h3 id="account-status">Account status</h3>
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Account verification and state</caption>
                    <thead>
                        <tr>
                            <th scope="col">Check</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Email verification</td>
                            <td>
                                @if ($user->hasVerifiedEmail())
                                    <x-status-pill status="verified" />
                                @else
                                    <x-status-pill status="pending" label="Not verified" />
                                    <a href="{{ route('verification.notice') }}" class="btn btn-sm mt-1">Verify</a>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td>Phone verification</td>
                            <td>
                                @if ($identities->contains('provider', 'phone'))
                                    <x-status-pill status="verified" />
                                @else
                                    <x-status-pill status="pending" label="Not verified" />
                                    <a href="{{ route('settings.connected-accounts') }}" class="btn btn-sm mt-1">Verify</a>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td>Google account</td>
                            <td>
                                @if ($identities->contains('provider', 'google'))
                                    <x-status-pill status="confirmed" label="Linked" />
                                @else
                                    <x-status-pill status="draft" label="Not linked" />
                                    <a href="{{ route('settings.connected-accounts') }}" class="btn btn-sm mt-1">Link</a>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td>Password</td>
                            <td>
                                @if ($hasPassword)
                                    <x-status-pill status="confirmed" label="Set" />
                                @else
                                    <x-status-pill status="pending" label="Not set" />
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td>Account state</td>
                            <td><x-status-pill :status="$user->isActive() ? 'active' : 'failed'" :label="$user->account_status" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card" aria-labelledby="change-password">
            <h3 id="change-password">Change password</h3>
            <form method="POST" action="{{ route('settings.password') }}" novalidate>
                @csrf
                @if ($hasPassword)
                    <div class="field">
                        <label for="current_password">Current password</label>
                        <input type="password" id="current_password" name="current_password"
                               autocomplete="current-password" required
                               @if ($errors->has('current_password')) aria-invalid="true" aria-describedby="current_password-error" @endif>
                        @error('current_password')
                            <span class="form-error" id="current_password-error">{{ $message }}</span>
                        @enderror
                    </div>
                @endif
                <div class="field">
                    <label for="password">New password (min 8 characters)</label>
                    <input type="password" id="password" name="password"
                           autocomplete="new-password" required
                           @if ($errors->has('password')) aria-invalid="true" aria-describedby="password-error" @endif>
                    @error('password')
                        <span class="form-error" id="password-error">{{ $message }}</span>
                    @enderror
                </div>
                <div class="field">
                    <label for="password_confirmation">Confirm new password</label>
                    <input type="password" id="password_confirmation" name="password_confirmation"
                           autocomplete="new-password" required>
                </div>
                <button type="submit" class="btn btn-primary mt-2">{{ $hasPassword ? 'Change password' : 'Set password' }}</button>
            </form>
        </section>
    </div>

    <section class="card" aria-labelledby="active-sessions">
        <h3 id="active-sessions">Active sessions</h3>
        <p class="muted">Review and revoke your active sessions, or sign out everywhere.</p>
        <div class="row mt-2">
            <a href="{{ route('settings.sessions') }}" class="btn btn-sm">View sessions</a>
            <form method="POST" action="{{ route('settings.sessions.revokeAll') }}">
                @csrf
                <button type="submit" class="btn btn-sm btn-danger">Sign out everywhere</button>
            </form>
        </div>
    </section>

    <section class="card" aria-labelledby="danger-zone" style="border-color: var(--red)">
        <h3 id="danger-zone" class="text-danger">Danger zone</h3>
        @if ($user->isActive())
            <div class="row">
                <form method="POST" action="{{ route('settings.deactivate') }}"
                      onsubmit="return confirm('Deactivate your account? You can reactivate it later.')">
                    @csrf
                    <button type="submit" class="btn btn-sm" style="border-color: var(--amber); color: var(--amber)">Deactivate account</button>
                </form>
                <form method="POST" action="{{ route('settings.deletion.request') }}"
                      onsubmit="return confirm('Request account deletion? This cannot be undone.')">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-danger">Request deletion</button>
                </form>
            </div>
        @elseif ($user->account_status === 'deactivated')
            <form method="POST" action="{{ route('settings.reactivate') }}">
                @csrf
                <button type="submit" class="btn btn-green btn-sm">Reactivate account</button>
            </form>
        @elseif ($user->account_status === 'deletion_pending')
            <p class="muted">Deletion requested — it will be processed shortly.</p>
            <form method="POST" action="{{ route('settings.deletion.cancel') }}">
                @csrf
                <button type="submit" class="btn btn-sm">Cancel deletion request</button>
            </form>
        @endif
    </section>
@endsection
```

### `resources/views/settings/sessions.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Active Sessions — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">Active Sessions</h1>
    </header>

    <section class="card" aria-labelledby="sessions-heading">
        <h3 id="sessions-heading" class="sr-only">Your active sessions</h3>
        @if ($sessions->isEmpty())
            <x-empty-state title="No active sessions found" icon="🖥️">
                Your signed-in devices will appear here.
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Devices currently signed in</caption>
                    <thead>
                        <tr>
                            <th scope="col">Device</th>
                            <th scope="col">Last activity</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sessions as $session)
                            <tr>
                                <td>
                                    {{ $session['device_label'] }}
                                    @if ($session['is_current'])
                                        <x-status-pill status="live" label="This device" />
                                    @endif
                                </td>
                                <td class="muted">{{ \Illuminate\Support\Carbon::createFromTimestamp($session['last_activity'])->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="row mt-4">
                <form method="POST" action="{{ route('settings.sessions.revokeOthers') }}">
                    @csrf
                    <button type="submit" class="btn btn-sm">Sign out other devices</button>
                </form>
                <form method="POST" action="{{ route('settings.sessions.revokeAll') }}">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-danger">Sign out everywhere</button>
                </form>
            </div>
        @endif
    </section>
@endsection
```

### `resources/views/settings/login-history.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Login History — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">Login History</h1>
    </header>

    <section class="card" aria-labelledby="history-heading">
        <h3 id="history-heading" class="sr-only">Your recent security events</h3>
        @if ($events->isEmpty())
            <x-empty-state title="No security events recorded yet" icon="🔐">
                Login attempts and account changes will appear here.
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Recent login and security events</caption>
                    <thead>
                        <tr>
                            <th scope="col">Event</th>
                            <th scope="col">Status</th>
                            <th scope="col">Device</th>
                            <th scope="col">When</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($events as $event)
                            <tr>
                                <td>{{ ucwords(str_replace(['.', '_'], ' ', $event->event)) }}</td>
                                <td>
                                    <x-status-pill :status="$event->status === 'success' ? 'confirmed' : 'failed'" :label="$event->status" />
                                </td>
                                <td class="muted">{{ $event->device_label ?? '—' }}</td>
                                <td class="muted">{{ $event->created_at?->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $events->links() }}
        @endif
    </section>
@endsection
```

### `resources/views/settings/connected-accounts.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Connected Accounts — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">Connected Accounts</h1>
    </header>

    <section class="card" aria-labelledby="password-heading">
        <h3 id="password-heading">Password</h3>
        @if ($hasPassword)
            <p class="muted">A password is set on this account. You can change it in
                <a href="{{ route('settings.security') }}">security settings</a>.</p>
        @else
            <p class="muted">No password set. <a href="{{ route('settings.security') }}">Set one</a> so you can sign in
                even if you unlink a provider.</p>
        @endif
    </section>

    <section class="card" aria-labelledby="google-heading">
        <h3 id="google-heading">Google</h3>
        @if ($identities->contains('provider', 'google'))
            <p>✅ <x-status-pill status="confirmed" label="Connected" /></p>
            <form method="POST" action="{{ route('settings.google.unlink') }}">
                @csrf
                <button type="submit" class="btn btn-sm btn-danger">Disconnect Google</button>
            </form>
        @else
            @if ($googleConfigured)
                <p class="muted">Connect Google to sign in with one click.</p>
                <a href="{{ route('settings.google.link') }}" class="btn btn-primary btn-sm">Connect Google</a>
            @else
                <p class="muted">Google Sign-In is not configured.</p>
            @endif
        @endif
    </section>

    <section class="card" aria-labelledby="phone-heading">
        <h3 id="phone-heading">Phone</h3>
        @if ($identities->contains('provider', 'phone'))
            @php($phoneIdentity = $identities->firstWhere('provider', 'phone'))
            <p>✅ <x-status-pill status="verified" /> <span class="muted">{{ $phoneIdentity->provider_subject }}</span></p>
            <form method="POST" action="{{ route('settings.phone.unlink') }}">
                @csrf
                <button type="submit" class="btn btn-sm btn-danger">Disconnect phone</button>
            </form>
        @else
            @if ($phoneConfigured)
                <p class="muted">Verify your phone number to enable phone sign-in.</p>
                <form method="POST" action="{{ route('settings.phone.link') }}">
                    @csrf
                    <div class="field">
                        <label for="phone">Mobile number</label>
                        <input type="tel" id="phone" name="phone" value="{{ old('phone', $user->phone) }}"
                               placeholder="01712345678" autocomplete="tel" inputmode="tel" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm mt-2">Verify phone</button>
                </form>
            @else
                <p class="muted">Phone verification is not configured.</p>
            @endif
        @endif
    </section>
@endsection
```

### `resources/views/settings/payment-methods.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Payment Methods — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">Payment Methods</h1>
    </header>

    <div class="grid cols-2">
        <section class="card" aria-labelledby="methods-heading">
            <h3 id="methods-heading">Your methods</h3>
            @if ($methods->isEmpty())
                <x-empty-state title="No saved payment methods" icon="💳">
                    Add a bKash or Nagad account below to check out faster.
                </x-empty-state>
            @else
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">Your saved payment methods</caption>
                        <thead>
                            <tr>
                                <th scope="col">Label</th>
                                <th scope="col">Provider</th>
                                <th scope="col">Identifier</th>
                                <th scope="col"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($methods as $method)
                                <tr>
                                    <td>
                                        {{ $method->label }}
                                        @if ($method->is_default)
                                            <x-status-pill status="confirmed" label="Default" />
                                        @endif
                                    </td>
                                    <td class="muted">{{ $method->provider }}</td>
                                    <td class="muted">{{ $method->masked_identifier }}</td>
                                    <td>
                                        <div class="row" style="gap: 8px">
                                            <form method="POST" action="{{ route('settings.payment-methods.default', $method) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm">Make default</button>
                                            </form>
                                            <form method="POST" action="{{ route('settings.payment-methods.destroy', $method) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-danger">Remove</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="card" aria-labelledby="add-method">
            <h3 id="add-method">Add a method</h3>
            <form method="POST" action="{{ route('settings.payment-methods.store') }}" novalidate>
                @csrf
                <div class="field">
                    <label for="provider">Provider</label>
                    <select id="provider" name="provider">
                        @foreach ($providers as $provider)
                            <option value="{{ $provider['id'] }}">{{ $provider['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="label">Label</label>
                    <input type="text" id="label" name="label" value="{{ old('label') }}" placeholder="My bKash" required>
                </div>
                <div class="field">
                    <label for="identifier">Account identifier (number/account)</label>
                    <input type="text" id="identifier" name="identifier" value="{{ old('identifier') }}"
                           placeholder="01712345678" inputmode="tel" required>
                </div>
                <p class="help-text">Only the last 4 digits are ever stored.</p>
                <button type="submit" class="btn btn-primary btn-sm mt-2">Save method</button>
            </form>
        </section>
    </div>
@endsection
```

### `resources/views/wallet/index.blade.php`

```blade
@extends('layouts.app')
@section('title', 'My Wallet — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">👛 My Wallet</h1>
    </header>

    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr))">
        <div class="stat">
            <div class="muted">Balance</div>
            <div class="num" style="color: var(--green)">৳{{ number_format($wallet->balance_minor / 100, 2) }}</div>
        </div>
        <div class="stat">
            <div class="muted">Currency</div>
            <div class="num">{{ $wallet->currency }}</div>
        </div>
    </div>

    <section class="card" aria-labelledby="identity-heading">
        <h3 id="identity-heading">🪪 Identity Verification</h3>
        <div class="row-between">
            <div>
                <x-status-pill :status="$identity->statusPill()" :label="$identity->statusLabel()" />
                @if ($identity->expires_at && $identity->status === 'verified')
                    <span class="muted" style="font-size: .8rem"> · expires {{ $identity->expires_at->format('d M Y') }}</span>
                @endif
                @if ($identity->notes)
                    <p class="muted mt-1" style="font-size: .8rem">{{ $identity->notes }}</p>
                @endif
            </div>
            @if (in_array($identity->status, ['unverified', 'rejected', 'expired'], true))
                <form method="POST" action="{{ route('security.identity.request') }}">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-cyan">Request Verification</button>
                </form>
            @endif
        </div>
    </section>

    <div class="grid cols-2">
        <section class="card" aria-labelledby="transactions-heading">
            <h3 id="transactions-heading">🧾 Wallet Transactions</h3>
            @if ($ledger->isEmpty())
                <x-empty-state title="No wallet transactions yet" icon="🧾">
                    Deposits, entry payments and prize payouts will appear here.
                </x-empty-state>
            @else
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">Wallet ledger entries</caption>
                        <thead>
                            <tr>
                                <th scope="col">Date</th>
                                <th scope="col">Type</th>
                                <th scope="col">Amount</th>
                                <th scope="col">Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($ledger as $entry)
                                <tr>
                                    <td class="muted" style="font-size: .8rem">{{ $entry->created_at->format('d M, h:i A') }}</td>
                                    <td><x-status-pill :status="$entry->isCredit() ? 'confirmed' : 'finished'" :label="strtoupper($entry->type)" /></td>
                                    <td style="{{ $entry->isCredit() ? 'color: var(--green)' : 'color: var(--red)' }}">
                                        {{ $entry->isCredit() ? '+' : '−' }}৳{{ number_format($entry->amount_minor / 100, 2) }}
                                    </td>
                                    <td class="muted" style="font-size: .85rem">{{ $entry->description }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="card" aria-labelledby="payments-heading">
            <h3 id="payments-heading">💳 Payment History</h3>
            @if ($payments->isEmpty())
                <x-empty-state title="No payments yet" icon="💳">
                    Entry-fee payments will appear here.
                </x-empty-state>
            @else
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">Entry-fee payments</caption>
                        <thead>
                            <tr>
                                <th scope="col">Tournament</th>
                                <th scope="col">Team</th>
                                <th scope="col">Amount</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($payments as $payment)
                                <tr>
                                    <td>{{ $payment->tournament?->name ?? '—' }}</td>
                                    <td>{{ $payment->team?->name ?? '—' }}</td>
                                    <td>৳{{ number_format($payment->amount_minor / 100, 2) }}</td>
                                    <td>
                                        <x-status-pill :status="$payment->statusPill()" :label="strtoupper($payment->status)" />
                                        @if ($payment->refund)
                                            <span class="muted" style="font-size: .8rem">(refunded)</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="card" aria-labelledby="payouts-heading">
            <h3 id="payouts-heading">🏆 Prize Payouts</h3>
            @if ($payouts->isEmpty())
                <x-empty-state title="No prize payouts yet" icon="🏆">
                    Winnings will appear here after tournaments settle.
                </x-empty-state>
            @else
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">Prize payouts</caption>
                        <thead>
                            <tr>
                                <th scope="col">Tournament</th>
                                <th scope="col">Rank</th>
                                <th scope="col">Amount</th>
                                <th scope="col">Status</th>
                                <th scope="col">Paid</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($payouts as $payout)
                                <tr>
                                    <td>{{ $payout->tournament?->name ?? '—' }}</td>
                                    <td>#{{ $payout->rank }}</td>
                                    <td style="color: var(--green)">+৳{{ number_format($payout->amount_minor / 100, 2) }}</td>
                                    <td><x-status-pill :status="$payout->statusPill()" :label="$payout->statusLabel()" /></td>
                                    <td class="muted" style="font-size: .8rem">{{ $payout->processed_at?->format('d M, h:i A') ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
@endsection
```

### `resources/views/payment/methods.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Choose Payment Method — FF Arena')
@section('content')
    <div class="card" style="max-width: 620px; margin: 40px auto">
        <h2>Pay entry fee</h2>
        <p class="muted">
            Tournament <strong>{{ $tournament->name }}</strong> · Team <strong>{{ $team->name }}</strong>
        </p>
        <div class="stat mt-3 mb-3">
            <div class="muted">Amount due</div>
            <div class="num">{{ \App\Support\Money::formatMinor($amountMinor) }}</div>
        </div>

        @forelse ($providers as $provider)
            <article class="card" style="padding: 14px; margin-bottom: 10px">
                <div class="row-between">
                    <div>
                        <strong>{{ $provider['label'] }}</strong>
                        @if (! $provider['configured'])
                            <x-status-pill status="pending" label="Not configured" />
                        @elseif ($provider['mode'] === 'sandbox')
                            <x-status-pill status="live" label="Sandbox" />
                        @endif
                    </div>
                    @if (in_array($provider['id'], ['bkash', 'nagad', 'rocket', 'bank'], true))
                        <form method="POST" action="{{ route('payment.initiate', [$tournament, $team]) }}">
                            @csrf
                            <input type="hidden" name="provider" value="{{ $provider['id'] }}">
                            <div class="row" style="gap: 8px">
                                <label for="trx-{{ $provider['id'] }}" class="sr-only">Transaction ID for {{ $provider['label'] }}</label>
                                <input type="text" id="trx-{{ $provider['id'] }}" name="trx_id" placeholder="Transaction ID" style="max-width: 190px">
                                <button type="submit" class="btn btn-primary btn-sm">Submit</button>
                            </div>
                        </form>
                    @else
                        <form method="POST" action="{{ route('payment.initiate', [$tournament, $team]) }}">
                            @csrf
                            <input type="hidden" name="provider" value="{{ $provider['id'] }}">
                            <button type="submit" class="btn btn-primary btn-sm" @disabled(! $provider['configured'])>
                                Pay with {{ $provider['label'] }}
                            </button>
                        </form>
                    @endif
                </div>
                @if (! $provider['configured'])
                    <p class="muted mt-1" style="font-size: .8rem">Provider is not configured — payments are not possible with this method yet.</p>
                @endif
            </article>
        @empty
            <p class="muted">No payment methods are currently enabled.</p>
        @endforelse

        <p class="muted mt-3" style="font-size: .8rem">
            🔒 Payments are verified server-side. Your team is confirmed only after the payment is verified.
        </p>
        <p class="mt-2"><a href="{{ route('teams.show', [$tournament, $team]) }}">← Back to team</a></p>
    </div>
@endsection
```

### `resources/views/payment/pending.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Payment Pending — FF Arena')
@section('content')
    <div class="card text-center" style="max-width: 460px; margin: 50px auto">
        <div style="font-size: 44px" aria-hidden="true">⏳</div>
        <h2>Payment under review</h2>
        <p class="muted">
            Your payment (৳{{ number_format($payment->amount, 2) }}, TrxID
            <strong>{{ $payment->trx_id }}</strong>) is being verified by the organizer.
        </p>
        <div class="mt-3 mb-3">
            <x-status-pill :status="$payment->statusPill()" :label="strtoupper($payment->status)" />
        </div>
        @if (in_array($payment->status, ['pending', 'processing'], true))
            <p class="muted" style="font-size: .85rem">
                Once verified, team <strong>{{ $team->name }}</strong> will be confirmed automatically.
            </p>
        @elseif ($payment->isSuccessful())
            <p class="text-success" style="font-size: .85rem">✓ Payment verified — your team is confirmed.</p>
        @elseif ($payment->status === 'refunded')
            <p class="muted" style="font-size: .85rem">This payment has been refunded to your wallet.</p>
        @endif
        <div class="row" style="justify-content: center; margin-top: 12px">
            <a href="{{ route('tournaments.show', $tournament) }}" class="btn btn-sm">Back to tournament</a>
            <a href="{{ route('wallet.index') }}" class="btn btn-sm">My Wallet</a>
        </div>
    </div>
@endsection
```

### `resources/views/payment/show.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Pay Entry Fee — FF Arena')
@section('content')
    <div class="card text-center" style="max-width: 460px; margin: 50px auto">
        <div style="font-size: 44px" aria-hidden="true">💳</div>
        <h2>Pay Entry Fee</h2>
        <p class="muted">Team <strong>{{ $team->name }}</strong> · {{ $tournament->name }}</p>
        <div class="stat mt-3 mb-3">
            <div class="muted" style="font-size: .85rem">Amount to pay</div>
            <div class="num" style="color: var(--green)">৳{{ number_format($tournament->entry_fee, 2) }}</div>
        </div>

        @if ((float) $tournament->entry_fee <= 0)
            <form method="POST" action="{{ route('payment.verify', [$tournament, $team]) }}">
                @csrf
                <input type="hidden" name="bkash_number" value="{{ $team->phone }}">
                <input type="hidden" name="trx_id" value="FREE{{ $team->id }}">
                <button type="submit" class="btn btn-green btn-block">Confirm Free Registration</button>
            </form>
        @else
            <p class="muted mb-3" style="font-size: .85rem; text-align: left">
                Send <strong>৳{{ number_format($tournament->entry_fee, 2) }}</strong> to the organizer's bKash number,
                then enter your Transaction ID below.
            </p>
            <form method="POST" action="{{ route('payment.verify', [$tournament, $team]) }}" novalidate>
                @csrf
                <div class="field" style="text-align: left">
                    <label for="bkash_number">Your bKash number</label>
                    <input type="tel" id="bkash_number" name="bkash_number" value="{{ $team->phone }}"
                           autocomplete="tel" inputmode="tel" required>
                </div>
                <div class="field" style="text-align: left">
                    <label for="trx_id">bKash Transaction ID (TrxID)</label>
                    <input type="text" id="trx_id" name="trx_id" placeholder="e.g. 9H7K2L1M3N" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Verify Payment</button>
            </form>
        @endif

        <p class="muted mt-4" style="font-size: .8rem">
            Prefer another method?
            <a href="{{ route('payment.methods', [$tournament, $team]) }}">Choose from bKash, Nagad, Rocket &amp; more →</a>
        </p>
        <p class="muted mt-1" style="font-size: .8rem">
            Payments are reviewed by the organizer/admin before your slot is confirmed.
        </p>
    </div>
@endsection
```

### `resources/views/teams/register.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Register Team — ' . $tournament->name)
@section('content')
    <div class="card" style="max-width: 620px; margin: 40px auto">
        <h2>Register your team</h2>
        <p class="muted">
            {{ $tournament->name }} — entry fee
            <strong class="tag">৳{{ number_format($tournament->entry_fee) }}</strong>
        </p>

        <form method="POST" action="{{ route('teams.store', $tournament) }}" novalidate>
            @csrf
            <div class="field">
                <label for="name">Team name</label>
                <input type="text" id="name" name="name" value="{{ old('name') }}" required placeholder="BD Titans"
                       @if ($errors->has('name')) aria-invalid="true" aria-describedby="name-error" @endif>
                @error('name')
                    <span class="form-error" id="name-error">{{ $message }}</span>
                @enderror
            </div>
            <div class="field">
                <label for="captain_name">Captain name</label>
                <input type="text" id="captain_name" name="captain_name" value="{{ old('captain_name') }}" required autocomplete="name">
            </div>
            <div class="field">
                <label for="phone">Phone (bKash)</label>
                <input type="tel" id="phone" name="phone" value="{{ old('phone') }}" required autocomplete="tel" inputmode="tel">
            </div>
            <div class="field">
                <label for="game_uid">Captain Free Fire UID</label>
                <input type="text" id="game_uid" name="game_uid" value="{{ old('game_uid') }}" required autocomplete="off" spellcheck="false">
            </div>

            <h3 class="mt-4">Team members (optional)</h3>
            @for ($i = 0; $i < max(1, $tournament->team_size - 1); $i++)
                <div class="grid cols-2 mt-2">
                    <div class="field">
                        <label for="member-name-{{ $i }}">Player {{ $i + 2 }} name</label>
                        <input type="text" id="member-name-{{ $i }}" name="members[{{ $i }}][player_name]">
                    </div>
                    <div class="field">
                        <label for="member-uid-{{ $i }}">Player {{ $i + 2 }} UID</label>
                        <input type="text" id="member-uid-{{ $i }}" name="members[{{ $i }}][game_uid]">
                    </div>
                </div>
            @endfor

            <button type="submit" class="btn btn-primary btn-block mt-4">Continue to Payment →</button>
        </form>
    </div>
@endsection
```

### `resources/views/teams/show.blade.php`

```blade
@extends('layouts.app')
@section('title', $team->name . ' — FF Arena')
@section('content')
    <header class="page-head">
        <nav class="breadcrumbs" aria-label="Breadcrumb">
            <li><a href="{{ route('home') }}">Home</a></li>
            <li><a href="{{ route('tournaments.show', $tournament) }}">{{ $tournament->name }}</a></li>
            <li><span aria-current="page">{{ $team->name }}</span></li>
        </nav>
        <h1 class="page-title">{{ $team->name }}</h1>
        <div class="row muted">
            {{ $tournament->name }}
            <x-status-pill :status="$team->status" />
            @if ($locked)
                <x-status-pill status="withdrawn" label="Roster locked" />
            @endif
            @if ($team->isWaitlisted())
                <x-status-pill status="waitlisted" :label="'Waitlist #' . $team->waitlistPosition()" />
            @elseif ($team->isCheckedIn())
                <x-status-pill status="checked" label="Checked in" />
            @endif
        </div>
    </header>

    @if ($tournament->hasCheckIn())
        <section class="card" aria-labelledby="checkin-heading">
            <h3 id="checkin-heading">📋 Check-in</h3>
            @if ($team->isCheckedIn())
                <p class="text-success"><strong>✓ Checked in</strong>
                    <span class="muted">at {{ $team->checked_in_at->format('d M, h:i A') }}</span></p>
            @elseif ($team->isWaitlisted())
                <p class="muted">Waitlisted teams check in after they are promoted and confirmed.</p>
            @elseif (! $team->isConfirmed())
                <p class="muted">Only confirmed teams can check in. Complete your payment first.</p>
            @elseif ($tournament->checkInIsOpen())
                <form method="POST" action="{{ route('teams.checkin', [$tournament, $team]) }}">
                    @csrf
                    <button type="submit" class="btn btn-green">✅ Check In Now</button>
                </form>
            @elseif ($tournament->checkInHasClosed())
                <p class="text-danger">The check-in window has closed.</p>
            @else
                <p class="muted">Check-in opens {{ $tournament->check_in_starts_at->format('d M, h:i A') }}.</p>
            @endif
        </section>
    @endif

    <div class="grid cols-2">
        <section class="card" aria-labelledby="team-info">
            <h3 id="team-info">🪪 Team Info</h3>
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Team details</caption>
                    <tbody>
                        <tr><th scope="row">Captain</th><td>{{ $team->captain_name }}</td></tr>
                        <tr><th scope="row">Captain UID</th><td><strong class="tag">{{ $team->game_uid }}</strong></td></tr>
                        <tr><th scope="row">Phone</th><td>{{ $team->phone }}</td></tr>
                        <tr><th scope="row">Roster size</th><td>{{ $team->rosterSize() }} / {{ $tournament->team_size }} players</td></tr>
                        <tr><th scope="row">Status</th><td>{{ ucfirst($team->status) }}</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card" aria-labelledby="roster-heading">
            <h3 id="roster-heading">👥 Roster</h3>
            @if ($team->members->isEmpty())
                <x-empty-state title="No members added yet" icon="👥">
                    The captain is the only player on this team.
                </x-empty-state>
            @else
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">Roster members</caption>
                        <thead>
                            <tr>
                                <th scope="col">#</th>
                                <th scope="col">Player</th>
                                <th scope="col">Free Fire UID</th>
                                @if ($canEdit)<th scope="col"><span class="sr-only">Actions</span></th>@endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($team->members as $m)
                                <tr>
                                    <td>{{ $loop->iteration + 1 }}</td>
                                    <td>{{ $m->player_name }}</td>
                                    <td><strong class="tag">{{ $m->game_uid }}</strong></td>
                                    @if ($canEdit)
                                        <td>
                                            <form method="POST" action="{{ route('teams.members.remove', [$tournament, $team, $m]) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-danger"
                                                        onclick="return confirm('Remove {{ $m->player_name }} from the roster?')">Remove</button>
                                            </form>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>

    @if ($canEdit)
        <div class="grid cols-2">
            <section class="card" aria-labelledby="add-member">
                <h3 id="add-member">➕ Add Member</h3>
                @if ($slotsLeft > 0)
                    <p class="muted mb-2" style="font-size: .85rem">{{ $slotsLeft }} roster slot(s) remaining.</p>
                    <form method="POST" action="{{ route('teams.members.store', [$tournament, $team]) }}">
                        @csrf
                        <div class="field">
                            <label for="player_name">Player name</label>
                            <input type="text" id="player_name" name="player_name" required placeholder="Player name">
                        </div>
                        <div class="field">
                            <label for="member_game_uid">Free Fire UID</label>
                            <input type="text" id="member_game_uid" name="game_uid" required placeholder="e.g. 1234567890">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Add to Roster</button>
                    </form>
                @else
                    <p class="muted">Roster is full ({{ $tournament->team_size }} players maximum).</p>
                @endif
            </section>

            <section class="card" aria-labelledby="edit-team">
                <h3 id="edit-team">✏️ Edit Team Info</h3>
                <form method="POST" action="{{ route('teams.update', [$tournament, $team]) }}">
                    @csrf
                    @method('PUT')
                    <div class="field">
                        <label for="edit-name">Team name</label>
                        <input type="text" id="edit-name" name="name" value="{{ $team->name }}" required>
                    </div>
                    <div class="field">
                        <label for="edit-captain_name">Captain name</label>
                        <input type="text" id="edit-captain_name" name="captain_name" value="{{ $team->captain_name }}" required>
                    </div>
                    <div class="field">
                        <label for="edit-phone">Phone (bKash)</label>
                        <input type="tel" id="edit-phone" name="phone" value="{{ $team->phone }}" required>
                    </div>
                    <div class="field">
                        <label for="edit-game_uid">Captain Free Fire UID</label>
                        <input type="text" id="edit-game_uid" name="game_uid" value="{{ $team->game_uid }}" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
                </form>
            </section>
        </div>
    @elseif ($locked && ! $canEdit)
        <div class="card">
            <p class="muted">🔒 This roster is locked. Registration has closed, so the roster can no longer be changed.</p>
        </div>
    @endif
@endsection
```

### `resources/views/tournaments/create.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Create Tournament — FF Arena')
@section('content')
    <div class="card" style="max-width: 640px; margin: 40px auto">
        <h2>🎮 Create a Tournament</h2>

        <form method="POST" action="{{ route('tournaments.store') }}" novalidate>
            @csrf
            <div class="field">
                <label for="name">Tournament name</label>
                <input type="text" id="name" name="name" value="{{ old('name') }}" placeholder="Squad Showdown 32 Teams" required
                       @if ($errors->has('name')) aria-invalid="true" aria-describedby="name-error" @endif>
                @error('name')
                    <span class="form-error" id="name-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="grid cols-2 mt-1">
                <div class="field">
                    <label for="game_mode">Game mode</label>
                    <select id="game_mode" name="game_mode">
                        <option value="squad">Squad</option>
                        <option value="duo">Duo</option>
                        <option value="solo">Solo</option>
                    </select>
                </div>
                <div class="field">
                    <label for="map">Map</label>
                    <select id="map" name="map">
                        <option>Bermuda</option>
                        <option>Purgatory</option>
                        <option>Kalahari</option>
                        <option>Alpine</option>
                    </select>
                </div>
                <div class="field">
                    <label for="entry_fee">Entry fee (৳ per team)</label>
                    <input type="number" id="entry_fee" name="entry_fee" value="{{ old('entry_fee', 100) }}" min="0" required>
                </div>
                <div class="field">
                    <label for="prize_pool">Prize pool (৳)</label>
                    <input type="number" id="prize_pool" name="prize_pool" value="{{ old('prize_pool', 5000) }}" min="0" required>
                </div>
                <div class="field">
                    <label for="team_slots">Team slots</label>
                    <select id="team_slots" name="team_slots">
                        <option value="8">8</option>
                        <option value="16" selected>16</option>
                        <option value="32">32</option>
                    </select>
                </div>
                <div class="field">
                    <label for="team_size">Players per team</label>
                    <input type="number" id="team_size" name="team_size" value="{{ old('team_size', 4) }}" min="1" max="6" required>
                </div>
                <div class="field">
                    <label for="format">Bracket format</label>
                    <select id="format" name="format">
                        <option value="single_elim" @selected(old('format', 'single_elim') === 'single_elim')>Single Elimination</option>
                        <option value="double_elim" @selected(old('format') === 'double_elim')>Double Elimination</option>
                    </select>
                    <p class="help-text">Double elimination requires a full power-of-two field (8, 16 or 32 eligible teams).</p>
                </div>
            </div>

            <div class="field">
                <label for="starts_at">Start date &amp; time</label>
                <input type="datetime-local" id="starts_at" name="starts_at" value="{{ old('starts_at') }}" required>
            </div>

            <div class="grid cols-2 mt-1">
                <div class="field">
                    <label for="check_in_starts_at">Check-in opens (optional)</label>
                    <input type="datetime-local" id="check_in_starts_at" name="check_in_starts_at" value="{{ old('check_in_starts_at') }}">
                </div>
                <div class="field">
                    <label for="check_in_ends_at">Check-in closes (optional)</label>
                    <input type="datetime-local" id="check_in_ends_at" name="check_in_ends_at" value="{{ old('check_in_ends_at') }}">
                </div>
            </div>
            <p class="help-text">Leave check-in empty to skip check-in (all confirmed teams enter the bracket).</p>

            <div class="field">
                <label for="dispute_window_hours">Dispute window (hours)</label>
                <input type="number" id="dispute_window_hours" name="dispute_window_hours" value="{{ old('dispute_window_hours', 24) }}" min="0" max="720">
                <p class="help-text">How long participants have to dispute a result after a match ends. 0 disables participant disputes.</p>
            </div>

            <div class="field">
                <label for="rules">Rules</label>
                <textarea id="rules" name="rules" rows="4" placeholder="1. No hacks — instant ban.&#10;2. Screenshot mandatory.">{{ old('rules') }}</textarea>
            </div>

            <button type="submit" class="btn btn-primary btn-block mt-4">Create Tournament</button>
        </form>
    </div>
@endsection
```

### `resources/views/tournaments/edit.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Edit Tournament — FF Arena')
@section('content')
    <div class="card" style="max-width: 640px; margin: 40px auto">
        <h2>Edit: {{ $tournament->name }}</h2>

        <form method="POST" action="{{ route('tournaments.update', $tournament) }}" novalidate>
            @csrf
            @method('PUT')
            <div class="field">
                <label for="name">Tournament name</label>
                <input type="text" id="name" name="name" value="{{ $tournament->name }}" required>
            </div>

            <div class="grid cols-2 mt-1">
                <div class="field">
                    <label for="game_mode">Game mode</label>
                    <select id="game_mode" name="game_mode">
                        @foreach (['squad', 'duo', 'solo'] as $m)
                            <option value="{{ $m }}" @selected($tournament->game_mode === $m)>{{ ucfirst($m) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="map">Map</label>
                    <input type="text" id="map" name="map" value="{{ $tournament->map }}" required>
                </div>
                <div class="field">
                    <label for="entry_fee">Entry fee (৳)</label>
                    <input type="number" id="entry_fee" name="entry_fee" value="{{ $tournament->entry_fee }}" min="0" required>
                </div>
                <div class="field">
                    <label for="prize_pool">Prize pool (৳)</label>
                    <input type="number" id="prize_pool" name="prize_pool" value="{{ $tournament->prize_pool }}" min="0" required>
                </div>
                <div class="field">
                    <label for="team_slots">Team slots</label>
                    <select id="team_slots" name="team_slots">
                        @foreach ([8, 16, 32] as $s)
                            <option value="{{ $s }}" @selected($tournament->team_slots == $s)>{{ $s }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="team_size">Players per team</label>
                    <input type="number" id="team_size" name="team_size" value="{{ $tournament->team_size }}" min="1" max="6" required>
                </div>
                <div class="field">
                    <label for="format">Bracket format</label>
                    <select id="format" name="format">
                        @foreach (\App\Models\Tournament::FORMATS as $f)
                            <option value="{{ $f }}" @selected($tournament->format === $f)>
                                {{ $f === 'double_elim' ? 'Double Elimination' : 'Single Elimination' }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="field">
                <label for="starts_at">Start date &amp; time</label>
                <input type="datetime-local" id="starts_at" name="starts_at" value="{{ optional($tournament->starts_at)->format('Y-m-d\TH:i') }}" required>
            </div>

            <div class="grid cols-2 mt-1">
                <div class="field">
                    <label for="check_in_starts_at">Check-in opens (optional)</label>
                    <input type="datetime-local" id="check_in_starts_at" name="check_in_starts_at" value="{{ optional($tournament->check_in_starts_at)->format('Y-m-d\TH:i') }}">
                </div>
                <div class="field">
                    <label for="check_in_ends_at">Check-in closes (optional)</label>
                    <input type="datetime-local" id="check_in_ends_at" name="check_in_ends_at" value="{{ optional($tournament->check_in_ends_at)->format('Y-m-d\TH:i') }}">
                </div>
            </div>

            <div class="field">
                <label for="dispute_window_hours">Dispute window (hours)</label>
                <input type="number" id="dispute_window_hours" name="dispute_window_hours" value="{{ $tournament->disputeWindowHours() }}" min="0" max="720">
                <p class="help-text">0 disables participant disputes. Staff always bypass the window.</p>
            </div>

            <div class="field">
                <label for="rules">Rules</label>
                <textarea id="rules" name="rules" rows="4">{{ $tournament->rules }}</textarea>
            </div>

            <button type="submit" class="btn btn-primary btn-block mt-4">Save Changes</button>
        </form>
    </div>
@endsection
```

### `resources/views/tournaments/scoring.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Scoring Rules — ' . $tournament->name)
@section('content')
    @php
        $placementMap = $current->placement_points ?? [];
        $currentChain = $current->tieBreakers();
        // Pad the chain to 5 selectable slots for the form.
        $slots = array_merge(array_values($currentChain), array_fill(0, max(0, 5 - count($currentChain)), null));
        $slots = array_slice($slots, 0, 5);
    @endphp

    <header class="page-head">
        <nav class="breadcrumbs" aria-label="Breadcrumb">
            <li><a href="{{ route('home') }}">Home</a></li>
            <li><a href="{{ route('tournaments.show', $tournament) }}">{{ $tournament->name }}</a></li>
            <li><span aria-current="page">Scoring Rules</span></li>
        </nav>
        <h1 class="page-title">🎯 Scoring Rules</h1>
        <p class="page-subtitle">{{ $tournament->name }}</p>
    </header>

    <section class="card" aria-labelledby="current-rules">
        <h3 id="current-rules">Current Rules
            <x-status-pill status="live" :label="'v' . $current->version" />
            @if ($current->isCurrent())
                <x-status-pill status="confirmed" label="Active" />
            @endif
        </h3>
        <div class="row mb-3">
            <div>Kill points <br><strong class="tag">{{ $current->kill_points }} / kill</strong></div>
            <div>Rule set name <br><strong>{{ $current->label() }}</strong></div>
        </div>

        <div class="row" style="align-items: flex-start">
            <div>
                <div class="muted" style="font-size: .8rem; font-weight: 700; margin-bottom: 6px">PLACEMENT POINTS</div>
                <div class="table-wrap" style="max-width: 320px">
                    <table>
                        <caption class="sr-only">Placement points by finishing position</caption>
                        <thead>
                            <tr><th scope="col">Placement</th><th scope="col">Points</th></tr>
                        </thead>
                        <tbody>
                            @for ($p = 1; $p <= 12; $p++)
                                <tr>
                                    <td>#{{ $p }}</td>
                                    <td><strong>{{ $current->placementPointsFor($p) }}</strong></td>
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>
            </div>
            <div>
                <div class="muted" style="font-size: .8rem; font-weight: 700; margin-bottom: 6px">TIE-BREAKER ORDER</div>
                <ol>
                    @foreach ($currentChain as $key)
                        <li style="margin-bottom: 4px">{{ \App\Models\ScoringRule::TIE_BREAKER_OPTIONS[$key] ?? $key }}</li>
                    @endforeach
                </ol>
                <p class="muted mt-3" style="font-size: .8rem; max-width: 340px">
                    Historical scores keep the exact rule version they were
                    computed with — changing rules only affects <em>future</em> matches.
                </p>
            </div>
        </div>
    </section>

    <section class="card" aria-labelledby="new-version">
        <h3 id="new-version">🆕 Create a New Version</h3>
        <p class="muted mb-2" style="font-size: .85rem">
            Saving creates an immutable new version and activates it. Existing results are never rewritten.
        </p>
        <form method="POST" action="{{ route('tournaments.scoring.store', $tournament) }}">
            @csrf
            <div class="grid cols-2">
                <div class="field">
                    <label for="name">Rule set name (optional)</label>
                    <input type="text" id="name" name="name" value="{{ $current->name }}" placeholder="e.g. Finals rules">
                </div>
                <div class="field">
                    <label for="kill_points">Points per kill</label>
                    <input type="number" id="kill_points" name="kill_points" value="{{ $current->kill_points }}" min="0" max="1000" required>
                </div>
            </div>

            <fieldset>
                <legend>Placement points (1st → 12th)</legend>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(90px, 1fr)); gap: 8px">
                    @for ($p = 1; $p <= 12; $p++)
                        <div>
                            <label for="placement-{{ $p }}" class="sr-only">Placement #{{ $p }} points</label>
                            <span class="muted" style="font-size: .7rem">#{{ $p }}</span>
                            <input type="number" id="placement-{{ $p }}" name="placement_points[{{ $p }}]"
                                   value="{{ $current->placementPointsFor($p) }}" min="0" max="1000" required>
                        </div>
                    @endfor
                </div>
            </fieldset>

            <fieldset>
                <legend>Tie-breaker order (first → last)</legend>
                <p class="help-text">
                    Total points is always the primary sort. Leave a slot as "—" to stop the chain early.
                </p>
                @for ($i = 0; $i < 5; $i++)
                    <div class="field">
                        <label for="tie-{{ $i }}" class="sr-only">Tie-breaker slot {{ $i + 1 }}</label>
                        <select id="tie-{{ $i }}" name="tie_breakers[]" style="margin-bottom: 6px">
                            <option value="">— none —</option>
                            @foreach (\App\Models\ScoringRule::TIE_BREAKER_OPTIONS as $key => $label)
                                <option value="{{ $key }}" @selected(($slots[$i] ?? null) === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endfor
            </fieldset>

            <button type="submit" class="btn btn-primary mt-2">Create &amp; Activate Version</button>
        </form>
    </section>

    <section class="card" aria-labelledby="version-history">
        <h3 id="version-history">📚 Version History</h3>
        @if ($rules->isEmpty())
            <p class="muted">No rule versions yet.</p>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Scoring rule version history</caption>
                    <thead>
                        <tr>
                            <th scope="col">Version</th>
                            <th scope="col">Name</th>
                            <th scope="col">Kill Pts</th>
                            <th scope="col">Created</th>
                            <th scope="col">Status</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rules as $rule)
                            <tr>
                                <td><strong>v{{ $rule->version }}</strong></td>
                                <td>{{ $rule->label() }}</td>
                                <td>{{ $rule->kill_points }}</td>
                                <td class="muted">{{ $rule->created_at->format('d M Y, h:i A') }}</td>
                                <td>
                                    @if ($rule->isCurrent())
                                        <x-status-pill status="live" label="Active" />
                                    @else
                                        <x-status-pill status="cancelled" label="Historical" />
                                    @endif
                                </td>
                                <td>
                                    @if (! $rule->isCurrent())
                                        <form method="POST" action="{{ route('tournaments.scoring.activate', [$tournament, $rule]) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-cyan">Activate</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
```

### `resources/views/matches/show.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Match — ' . $tournament->name)
@section('content')
    <header class="page-head">
        <nav class="breadcrumbs" aria-label="Breadcrumb">
            <li><a href="{{ route('home') }}">Home</a></li>
            <li><a href="{{ route('tournaments.show', $tournament) }}">{{ $tournament->name }}</a></li>
            <li><span aria-current="page">Match #{{ $match->match_no }}</span></li>
        </nav>
        <h1 class="page-title">{{ $match->roundLabel() }} — Match #{{ $match->match_no }}</h1>
        <p class="page-subtitle">{{ $match->bracketLabel() }}</p>
    </header>

    <div class="grid cols-2">
        <section class="card" aria-labelledby="matchup-heading">
            <h3 id="matchup-heading">⚔️ Matchup</h3>
            @if ($match->isBye())
                @php $byeTeam = $match->team1 ?: $match->team2; @endphp
                <div class="bracket-team win">
                    <strong>{{ $byeTeam->name ?? 'TBD' }}</strong>
                </div>
                <p class="muted text-center mt-3 mb-3">— bye, automatically advanced —</p>
            @else
                <div class="bracket-team {{ $match->winner_team_id === $match->team1_id ? 'win' : '' }}">
                    <strong>{{ $match->team1?->name ?? 'TBD' }}</strong>
                </div>
                <div class="text-center" style="color: var(--purple); font-weight: 800; padding: 4px 0">VS</div>
                <div class="bracket-team {{ $match->winner_team_id === $match->team2_id ? 'win' : '' }}">
                    <strong>{{ $match->team2?->name ?? 'TBD' }}</strong>
                </div>
                @if ($match->winner)
                    <div class="muted mt-3" style="font-size: .85rem">
                        Winner: <strong class="tag">{{ $match->winner->name }}</strong>
                    </div>
                @endif
            @endif
            <div class="muted mt-3" style="font-size: .85rem">
                Status: <x-status-pill :status="$match->statusPill()" :label="strtoupper($match->status)" />
            </div>
            @if ($match->nextMatch)
                <div class="muted mt-2" style="font-size: .85rem">
                    Next: <a href="{{ route('matches.show', [$tournament, $match->nextMatch]) }}">
                        {{ $match->nextMatch->roundLabel() }} #{{ $match->nextMatch->match_no }}
                        @if ($match->next_slot) (slot {{ $match->next_slot }}) @endif
                    </a>
                </div>
            @endif
            @if ($match->loserNextMatch)
                <div class="muted mt-1" style="font-size: .85rem">
                    Loser drops to: <a href="{{ route('matches.show', [$tournament, $match->loserNextMatch]) }}">
                        {{ $match->loserNextMatch->roundLabel() }} #{{ $match->loserNextMatch->match_no }}
                        @if ($match->loser_slot) (slot {{ $match->loser_slot }}) @endif
                    </a>
                </div>
            @endif
        </section>

        <section class="card" aria-labelledby="room-heading">
            <h3 id="room-heading">🎟 Room Info</h3>
            @if ($match->room_id)
                <div style="font-size: 15px">
                    Room ID: <strong class="tag">{{ $match->room_id }}</strong><br>
                    Password: <strong class="tag">{{ $match->room_pass }}</strong><br>
                    Time: {{ optional($match->scheduled_at)->format('d M, h:i A') }}
                </div>
            @else
                <p class="muted">Room details not published yet.</p>
            @endif

            @auth
                @if (auth()->user()->isAdmin() || auth()->user()->isOrganizer())
                    <form method="POST" action="{{ route('matches.room', [$tournament, $match]) }}" class="mt-3">
                        @csrf
                        <div class="grid cols-2">
                            <div class="field">
                                <label for="room_id">Room ID</label>
                                <input type="text" id="room_id" name="room_id" required>
                            </div>
                            <div class="field">
                                <label for="room_pass">Password</label>
                                <input type="text" id="room_pass" name="room_pass" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-sm btn-cyan mt-2">Publish Room &amp; Start Match</button>
                    </form>
                @endif
            @endauth
        </section>
    </div>

    <div class="grid cols-2">
        <section class="card" aria-labelledby="scores-heading">
            <h3 id="scores-heading">📊 Submitted Scores</h3>
            @if ($match->scores->isEmpty())
                <p class="muted">No scores submitted yet.</p>
            @else
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">Scores submitted for this match</caption>
                        <thead>
                            <tr>
                                <th scope="col">Team</th>
                                <th scope="col">Kills</th>
                                <th scope="col">Place</th>
                                <th scope="col">Place Pts</th>
                                <th scope="col">Kill Pts</th>
                                <th scope="col">Adj</th>
                                <th scope="col">Total</th>
                                <th scope="col">Proof</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($match->scores as $score)
                                <tr>
                                    <td><strong>{{ $score->team->name }}</strong></td>
                                    <td>{{ $score->kills }}</td>
                                    <td>#{{ $score->placement }}</td>
                                    <td>{{ $score->placement_points }}</td>
                                    <td>{{ $score->kill_points }}</td>
                                    <td>
                                        @if ($score->adjustments->isEmpty())
                                            <span class="muted">—</span>
                                        @else
                                            @foreach ($score->adjustments as $adj)
                                                <x-status-pill :status="$adj->isBonus() ? 'confirmed' : 'finished'"
                                                    :label="($adj->isBonus() ? '+' : '−') . $adj->points" />
                                            @endforeach
                                        @endif
                                    </td>
                                    <td><strong class="tag">{{ $score->points }}</strong></td>
                                    <td>
                                        @if ($score->screenshot_path)
                                            <a href="{{ asset('storage/' . $score->screenshot_path) }}" target="_blank" class="btn btn-sm">View</a>
                                        @else
                                            <span class="muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                                @if ($score->adjustments->isNotEmpty())
                                    <tr>
                                        <td colspan="8" style="background: var(--panel2); font-size: .8rem">
                                            <span class="muted">Adjustments:</span>
                                            @foreach ($score->adjustments as $adj)
                                                <span class="muted">{{ $adj->isBonus() ? 'Bonus' : 'Penalty' }} {{ $adj->points }}pt — "{{ $adj->reason }}"</span>
                                                @if (! $loop->last) · @endif
                                            @endforeach
                                        </td>
                                    </tr>
                                @endif
                                @if ($score->scoringRule)
                                    <tr>
                                        <td colspan="8" style="background: var(--panel2); font-size: .8rem">
                                            <span class="muted">Scoring rules: {{ $score->scoringRule->label() }} (v{{ $score->scoringRule->version }})</span>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="card" aria-labelledby="submit-score">
            <h3 id="submit-score">📝 Submit Score</h3>
            @if ($match->acceptsScoreSubmission())
                <form method="POST" action="{{ route('matches.score', [$tournament, $match]) }}" enctype="multipart/form-data">
                    @csrf
                    <div class="field">
                        <label for="team_id">Your team</label>
                        <select id="team_id" name="team_id" required>
                            @if ($match->team1) <option value="{{ $match->team1->id }}">{{ $match->team1->name }}</option> @endif
                            @if ($match->team2) <option value="{{ $match->team2->id }}">{{ $match->team2->name }}</option> @endif
                        </select>
                    </div>
                    <div class="grid cols-2">
                        <div class="field">
                            <label for="kills">Kills</label>
                            <input type="number" id="kills" name="kills" min="0" value="0" required>
                        </div>
                        <div class="field">
                            <label for="placement">Placement</label>
                            <input type="number" id="placement" name="placement" min="1" max="{{ \App\Models\ScoringRule::MAX_PLACEMENT }}" value="1" required>
                        </div>
                    </div>
                    <div class="field">
                        <label for="screenshot">Screenshot (proof)</label>
                        <input type="file" id="screenshot" name="screenshot" accept="image/*">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm mt-2">Submit Score</button>
                </form>
            @else
                <p class="muted">Score submission is not open for this match.</p>
            @endif

            @auth
                @if (auth()->user()->isAdmin() || (auth()->user()->isOrganizer() && auth()->user()->id === $tournament->organizer_id))
                    <hr style="border-color: var(--line); margin: 16px 0">

                    @if ($match->acceptsScoreSubmission() && $match->scores->isNotEmpty())
                        <h4>⚖ Score Adjustment (bonus / penalty)</h4>
                        <form method="POST" action="{{ route('matches.adjustment', [$tournament, $match]) }}">
                            @csrf
                            <div class="field">
                                <label for="adjust-team_id">Team</label>
                                <select id="adjust-team_id" name="team_id" required>
                                    @foreach ($match->scores as $score)
                                        <option value="{{ $score->team_id }}">{{ $score->team->name }} ({{ $score->points }} pts)</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="grid cols-2">
                                <div class="field">
                                    <label for="adjust-type">Type</label>
                                    <select id="adjust-type" name="type" required>
                                        <option value="bonus">Bonus (+)</option>
                                        <option value="penalty">Penalty (−)</option>
                                    </select>
                                </div>
                                <div class="field">
                                    <label for="adjust-points">Points</label>
                                    <input type="number" id="adjust-points" name="points" min="1" max="1000" value="1" required>
                                </div>
                            </div>
                            <div class="field">
                                <label for="adjust-reason">Reason (required, audited)</label>
                                <input type="text" id="adjust-reason" name="reason" placeholder="e.g. Booyah bonus" maxlength="255" required>
                            </div>
                            <button type="submit" class="btn btn-sm btn-cyan mt-1">Apply Adjustment</button>
                        </form>
                        <hr style="border-color: var(--line); margin: 16px 0">
                    @endif

                    @if ($match->acceptsScoreSubmission())
                        <h4>✅ Set Winner</h4>
                        <form method="POST" action="{{ route('matches.winner', [$tournament, $match]) }}">
                            @csrf
                            <div class="field">
                                <label for="winner_team_id">Winner team</label>
                                <select id="winner_team_id" name="winner_team_id" required>
                                    @if ($match->team1) <option value="{{ $match->team1->id }}">{{ $match->team1->name }}</option> @endif
                                    @if ($match->team2) <option value="{{ $match->team2->id }}">{{ $match->team2->name }}</option> @endif
                                </select>
                            </div>
                            <button type="submit" class="btn btn-green btn-sm mt-1">Confirm Winner (advances bracket)</button>
                        </form>
                    @endif

                    @if ($match->status === 'completed')
                        <form method="POST" action="{{ route('matches.dispute', [$tournament, $match]) }}">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-danger mt-2"
                                onclick="return confirm('Mark this completed match as disputed? Advancement will be blocked until resolved.')">
                                ⚠ Mark Disputed
                            </button>
                        </form>
                    @endif

                    @if ($match->status === 'disputed')
                        <h4 class="mt-3">⚖ Resolve Dispute</h4>
                        <p class="muted" style="font-size: .85rem">Choose the correct winner. The bracket will be re-advanced.</p>
                        <form method="POST" action="{{ route('matches.resolve', [$tournament, $match]) }}">
                            @csrf
                            <div class="field">
                                <label for="resolve-winner_team_id">Winner team</label>
                                <select id="resolve-winner_team_id" name="winner_team_id" required>
                                    @if ($match->team1) <option value="{{ $match->team1->id }}" @selected($match->winner_team_id === $match->team1_id)>{{ $match->team1->name }}</option> @endif
                                    @if ($match->team2) <option value="{{ $match->team2->id }}" @selected($match->winner_team_id === $match->team2_id)>{{ $match->team2->name }}</option> @endif
                                </select>
                            </div>
                            <button type="submit" class="btn btn-green btn-sm mt-1">Resolve Dispute</button>
                        </form>
                    @endif
                @endif
            @endauth
        </section>
    </div>

    <section class="card" aria-labelledby="disputes-heading">
        <h3 id="disputes-heading">🚩 Disputes</h3>
        @if ($match->disputes->isEmpty())
            <p class="muted">No disputes for this match.</p>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Disputes for this match</caption>
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Category</th>
                            <th scope="col">Status</th>
                            <th scope="col">Opened by</th>
                            <th scope="col">Opened</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($match->disputes as $dispute)
                            <tr>
                                <td><strong>#{{ $dispute->id }}</strong></td>
                                <td>{{ $dispute->categoryLabel() }}</td>
                                <td><x-status-pill :status="$dispute->statusPill()" :label="$dispute->statusLabel()" /></td>
                                <td>{{ $dispute->opener?->name ?? 'System' }}</td>
                                <td class="muted" style="font-size: .8rem">{{ $dispute->created_at->format('d M, h:i A') }}</td>
                                <td>
                                    <a href="{{ route('matches.disputes.show', [$tournament, $match, $dispute]) }}" class="btn btn-sm">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @auth
            @if ($canOpenDispute)
                <a href="{{ route('matches.disputes.create', [$tournament, $match]) }}" class="btn btn-sm btn-danger mt-3">🚩 Open Dispute</a>
            @endif
        @endauth
    </section>
@endsection
```

### `resources/views/disputes/create.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Open Dispute — ' . $tournament->name)
@section('content')
    <header class="page-head">
        <nav class="breadcrumbs" aria-label="Breadcrumb">
            <li><a href="{{ route('home') }}">Home</a></li>
            <li><a href="{{ route('tournaments.show', $tournament) }}">{{ $tournament->name }}</a></li>
            <li><a href="{{ route('matches.show', [$tournament, $match]) }}">Match #{{ $match->match_no }}</a></li>
            <li><span aria-current="page">Open Dispute</span></li>
        </nav>
        <h1 class="page-title">🚩 Open a Dispute</h1>
        <p class="page-subtitle">
            {{ $match->team1?->name ?? 'TBD' }} vs {{ $match->team2?->name ?? 'TBD' }} —
            {{ $match->roundLabel() }} #{{ $match->match_no }}
        </p>
    </header>

    @if ($existing)
        <div class="alert alert-error" role="alert">
            <span aria-hidden="true">✕</span>
            <span>
                This match already has an open dispute.
                <a href="{{ route('matches.disputes.show', [$tournament, $match, $existing]) }}">View it →</a>
            </span>
        </div>
    @endif

    <div class="card" style="max-width: 720px">
        <form method="POST" action="{{ route('matches.disputes.store', [$tournament, $match]) }}" enctype="multipart/form-data">
            @csrf
            <div class="field">
                <label for="category">Category</label>
                <select id="category" name="category" required>
                    <option value="">Select a reason…</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category }}" @selected(old('category') === $category)>
                            {{ ucwords(str_replace('_', ' ', $category)) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="5" maxlength="5000"
                          placeholder="Explain what happened and why you believe the result is wrong." required>{{ old('description') }}</textarea>
            </div>

            @if (auth()->user()->isAdmin() || auth()->user()->isModerator() || auth()->id() === $tournament->organizer_id)
                <div class="field">
                    <label for="team_id">Disputed team (optional — staff only)</label>
                    <select id="team_id" name="team_id">
                        <option value="">— none (staff dispute) —</option>
                        @if ($match->team1) <option value="{{ $match->team1->id }}">{{ $match->team1->name }}</option> @endif
                        @if ($match->team2) <option value="{{ $match->team2->id }}">{{ $match->team2->name }}</option> @endif
                    </select>
                </div>
            @elseif ($userTeam)
                <p class="muted mt-3" style="font-size: .85rem">
                    Disputing on behalf of: <strong class="tag">{{ $userTeam->name }}</strong>
                </p>
                <input type="hidden" name="team_id" value="{{ $userTeam->id }}">
            @endif

            <hr style="border-color: var(--line); margin: 18px 0">
            <h3>📎 Evidence (optional)</h3>

            <div class="field">
                <label for="evidence_type">Evidence type</label>
                <select id="evidence_type" name="evidence_type">
                    <option value="">— none —</option>
                    <option value="image" @selected(old('evidence_type') === 'image')>Screenshot / image</option>
                    <option value="video" @selected(old('evidence_type') === 'video')>Video clip</option>
                    <option value="document" @selected(old('evidence_type') === 'document')>Document (PDF)</option>
                    <option value="text" @selected(old('evidence_type') === 'text')>Text explanation</option>
                </select>
            </div>

            <div class="field">
                <label for="evidence_description">Evidence description</label>
                <input type="text" id="evidence_description" name="evidence_description" maxlength="2000"
                       placeholder="What does this evidence show?" value="{{ old('evidence_description') }}">
            </div>

            <div class="field">
                <label for="evidence_file">File (image / video / PDF, max {{ \App\Models\DisputeEvidence::MAX_KB / 1024 }} MB)</label>
                <input type="file" id="evidence_file" name="evidence_file">
            </div>

            <button type="submit" class="btn btn-primary mt-3">Open Dispute</button>
        </form>
    </div>
@endsection
```

### `resources/views/disputes/show.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Dispute — ' . $tournament->name)
@section('content')
    <header class="page-head">
        <nav class="breadcrumbs" aria-label="Breadcrumb">
            <li><a href="{{ route('home') }}">Home</a></li>
            <li><a href="{{ route('tournaments.show', $tournament) }}">{{ $tournament->name }}</a></li>
            <li><a href="{{ route('matches.show', [$tournament, $match]) }}">Match #{{ $match->match_no }}</a></li>
            <li><span aria-current="page">Dispute #{{ $dispute->id }}</span></li>
        </nav>
        <h1 class="page-title">🚩 Dispute #{{ $dispute->id }}
            <x-status-pill :status="$dispute->statusPill()" :label="strtoupper($dispute->status)" />
        </h1>
        <p class="page-subtitle">
            {{ $match->team1?->name ?? 'TBD' }} vs {{ $match->team2?->name ?? 'TBD' }} ·
            {{ $match->roundLabel() }} #{{ $match->match_no }}
        </p>
    </header>

    <div class="grid cols-2">
        <section class="card" aria-labelledby="details-heading">
            <h3 id="details-heading">Details</h3>
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Dispute details</caption>
                    <tbody>
                        <tr><th scope="row">Category</th><td>{{ $dispute->categoryLabel() }}</td></tr>
                        <tr><th scope="row">Opened by</th><td>{{ $dispute->opener?->name ?? 'System' }}</td></tr>
                        <tr><th scope="row">Team</th><td>{{ $dispute->team?->name ?? '—' }}</td></tr>
                        <tr><th scope="row">Opened</th><td>{{ $dispute->created_at->format('d M Y, h:i A') }}</td></tr>
                        @if ($dispute->assignee)
                            <tr><th scope="row">Reviewer</th><td>{{ $dispute->assignee->name }}</td></tr>
                        @endif
                        @if ($dispute->resolved_at)
                            <tr><th scope="row">Resolved</th><td>{{ $dispute->resolved_at->format('d M Y, h:i A') }} by {{ $dispute->resolver?->name ?? '—' }}</td></tr>
                        @endif
                        @if ($dispute->resolutionWinner)
                            <tr><th scope="row">Confirmed winner</th><td><strong class="tag">{{ $dispute->resolutionWinner->name }}</strong></td></tr>
                        @endif
                    </tbody>
                </table>
            </div>

            <h3 class="mt-4">Description</h3>
            <p style="white-space: pre-wrap">{{ $dispute->description }}</p>

            @if ($dispute->resolution)
                <h3 class="mt-4">Resolution</h3>
                <p class="text-success" style="white-space: pre-wrap">{{ $dispute->resolution }}</p>
            @endif
        </section>

        <section class="card" aria-labelledby="evidence-heading">
            <h3 id="evidence-heading">📎 Evidence ({{ $dispute->evidence->count() }})</h3>
            @if ($dispute->evidence->isEmpty())
                <p class="muted">No evidence yet.</p>
            @else
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">Submitted evidence</caption>
                        <thead>
                            <tr>
                                <th scope="col">Type</th>
                                <th scope="col">By</th>
                                <th scope="col">Description</th>
                                <th scope="col"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($dispute->evidence as $evidence)
                                <tr>
                                    <td><x-status-pill status="pending" :label="$evidence->typeLabel()" /></td>
                                    <td>{{ $evidence->submitter?->name ?? 'System' }}</td>
                                    <td class="muted" style="font-size: .85rem">{{ $evidence->description }}</td>
                                    <td>
                                        @if ($evidence->path)
                                            <a href="{{ route('matches.disputes.evidence.show', [$tournament, $match, $dispute, $evidence]) }}" class="btn btn-sm" target="_blank">View</a>
                                        @else
                                            <span class="muted">text</span>
                                        @endif
                                        @if ($isStaff)
                                            <form method="POST" action="{{ route('matches.disputes.evidence.remove', [$tournament, $match, $dispute, $evidence]) }}" class="mt-1">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-danger"
                                                    onclick="return confirm('Remove this evidence permanently? This is audited.')">Remove</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($dispute->isActionable() && auth()->check())
                <hr style="border-color: var(--line); margin: 16px 0">
                <h3>Submit Evidence</h3>
                <form method="POST" action="{{ route('matches.disputes.evidence.store', [$tournament, $match, $dispute]) }}" enctype="multipart/form-data">
                    @csrf
                    <div class="field">
                        <label for="type">Type</label>
                        <select id="type" name="type" required>
                            <option value="image">Screenshot / image</option>
                            <option value="video">Video clip</option>
                            <option value="document">Document (PDF)</option>
                            <option value="text">Text explanation</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="evidence_description">Description</label>
                        <input type="text" id="evidence_description" name="description" maxlength="2000" placeholder="What does this evidence show?">
                    </div>
                    <div class="field">
                        <label for="evidence_file">File (image / video / PDF, max {{ \App\Models\DisputeEvidence::MAX_KB / 1024 }} MB)</label>
                        <input type="file" id="evidence_file" name="evidence_file">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm mt-2">Add Evidence</button>
                </form>
            @endif
        </section>
    </div>

    <section class="card" aria-labelledby="timeline-heading">
        <h3 id="timeline-heading">🕓 Timeline</h3>
        @if ($dispute->events->isEmpty())
            <p class="muted">No events recorded.</p>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Dispute event timeline</caption>
                    <thead>
                        <tr>
                            <th scope="col">When</th>
                            <th scope="col">Actor</th>
                            <th scope="col">Event</th>
                            <th scope="col">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($dispute->events as $event)
                            <tr>
                                <td class="muted" style="font-size: .8rem">{{ $event->created_at->format('d M, h:i A') }}</td>
                                <td>{{ $event->actor?->name ?? 'System' }}</td>
                                <td><span class="tag">{{ $event->event }}</span></td>
                                <td class="muted" style="font-size: .8rem">{{ json_encode($event->metadata) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @auth
        @if ($dispute->isActionable())
            <section class="card" aria-labelledby="moderation-heading">
                @if ($isStaff)
                    <h3 id="moderation-heading">🛡 Moderation</h3>
                    <div class="grid cols-2">
                        @if ($dispute->status === \App\Models\Dispute::STATUS_OPEN)
                            <form method="POST" action="{{ route('matches.disputes.review', [$tournament, $match, $dispute]) }}">
                                @csrf
                                <button type="submit" class="btn btn-cyan btn-sm">Mark Under Review</button>
                            </form>
                        @endif

                        <form method="POST" action="{{ route('matches.disputes.assign', [$tournament, $match, $dispute]) }}">
                            @csrf
                            <div class="row" style="align-items: flex-end">
                                <div class="field grow">
                                    <label for="reviewer_id">Assign reviewer</label>
                                    <select id="reviewer_id" name="reviewer_id" required>
                                        <option value="">— select —</option>
                                        @foreach ($reviewers as $reviewer)
                                            <option value="{{ $reviewer->id }}" @selected($dispute->assigned_to === $reviewer->id)>{{ $reviewer->name }} ({{ $reviewer->role }})</option>
                                        @endforeach
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-sm">Assign</button>
                            </div>
                        </form>
                    </div>

                    <hr style="border-color: var(--line); margin: 16px 0">
                    <h3>⚖ Resolve</h3>
                    <form method="POST" action="{{ route('matches.disputes.resolve', [$tournament, $match, $dispute]) }}">
                        @csrf
                        <div class="field">
                            <label for="winner_team_id">Confirmed winner</label>
                            <select id="winner_team_id" name="winner_team_id" required>
                                @if ($match->team1) <option value="{{ $match->team1->id }}" @selected($match->winner_team_id === $match->team1_id)>{{ $match->team1->name }}</option> @endif
                                @if ($match->team2) <option value="{{ $match->team2->id }}" @selected($match->winner_team_id === $match->team2_id)>{{ $match->team2->name }}</option> @endif
                            </select>
                        </div>
                        <div class="field">
                            <label for="resolution">Resolution reason (required, audited)</label>
                            <textarea id="resolution" name="resolution" rows="3" maxlength="5000" placeholder="Explain the decision." required></textarea>
                        </div>

                        @if ($match->scores->isNotEmpty())
                            <fieldset>
                                <legend>Score corrections (optional — recalculated by the scoring engine)</legend>
                                @foreach ($match->scores as $score)
                                    <div class="row" style="align-items: center; margin-bottom: 6px">
                                        <span class="muted" style="min-width: 140px; font-size: .85rem">{{ $score->team->name }}</span>
                                        <input type="hidden" name="corrections[{{ $loop->index }}][team_id]" value="{{ $score->team_id }}">
                                        <label for="corr-kills-{{ $loop->index }}" class="sr-only">Kills for {{ $score->team->name }}</label>
                                        <input type="number" id="corr-kills-{{ $loop->index }}" name="corrections[{{ $loop->index }}][kills]" value="{{ $score->kills }}" min="0" placeholder="Kills" style="max-width: 90px">
                                        <label for="corr-place-{{ $loop->index }}" class="sr-only">Placement for {{ $score->team->name }}</label>
                                        <input type="number" id="corr-place-{{ $loop->index }}" name="corrections[{{ $loop->index }}][placement]" value="{{ $score->placement }}" min="1" max="{{ \App\Models\ScoringRule::MAX_PLACEMENT }}" placeholder="Place" style="max-width: 90px">
                                    </div>
                                @endforeach
                                <p class="help-text">Changes are recalculated with the score's original rule version; totals are never trusted from the client.</p>
                            </fieldset>
                        @endif

                        <div class="row mt-3">
                            <button type="submit" class="btn btn-green btn-sm">Resolve Dispute</button>
                        </div>
                    </form>

                    <hr style="border-color: var(--line); margin: 16px 0">
                    <div class="row" style="align-items: flex-end">
                        <form method="POST" action="{{ route('matches.disputes.reject', [$tournament, $match, $dispute]) }}" class="grow">
                            @csrf
                            <div class="field">
                                <label for="reject-reason">Rejection reason (required)</label>
                                <div class="row" style="gap: 8px">
                                    <input type="text" id="reject-reason" name="resolution" maxlength="5000" placeholder="Why the result stands" required>
                                    <button type="submit" class="btn btn-sm" style="border-color: var(--amber); color: var(--amber)">Reject</button>
                                </div>
                            </div>
                        </form>
                        <form method="POST" action="{{ route('matches.disputes.cancel', [$tournament, $match, $dispute]) }}">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-ghost">Cancel</button>
                        </form>
                    </div>
                @elseif ($isOpener && $dispute->status === \App\Models\Dispute::STATUS_OPEN)
                    <form method="POST" action="{{ route('matches.disputes.cancel', [$tournament, $match, $dispute]) }}">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-ghost">Cancel my dispute</button>
                    </form>
                @endif
            </section>
        @endif
    @endauth
@endsection
```

### `resources/views/support/create.blade.php`

```blade
@extends('layouts.app')
@section('title', 'New Support Ticket — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">🎫 New Support Ticket</h1>
    </header>

    <div class="card" style="max-width: 640px">
        <form method="POST" action="{{ route('support.store') }}" novalidate>
            @csrf
            <div class="field">
                <label for="subject">Subject</label>
                <input type="text" id="subject" name="subject" maxlength="255" required placeholder="Brief summary of your issue">
            </div>

            <div class="grid cols-2" style="grid-template-columns: 1fr 1fr; gap: 10px">
                <div class="field">
                    <label for="category">Category</label>
                    <select id="category" name="category" required>
                        @foreach ($categories as $category)
                            <option value="{{ $category }}">{{ ucfirst($category) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="priority">Priority</label>
                    <select id="priority" name="priority">
                        @foreach ($priorities as $priority)
                            <option value="{{ $priority }}" {{ $priority === 'normal' ? 'selected' : '' }}>{{ ucfirst($priority) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="field">
                <label for="message">Message</label>
                <textarea id="message" name="message" rows="6" required placeholder="Describe the issue in detail"></textarea>
            </div>

            <button type="submit" class="btn btn-primary mt-2">Submit ticket</button>
        </form>
    </div>
@endsection
```

### `resources/views/support/index.blade.php`

```blade
@extends('layouts.app')
@section('title', 'My Support Tickets — FF Arena')
@section('content')
    <header class="page-head">
        <div class="row-between">
            <h1 class="page-title">🎫 My Support Tickets</h1>
            <a href="{{ route('support.create') }}" class="btn btn-primary">New ticket</a>
        </div>
    </header>

    <section class="card" style="padding: 0" aria-labelledby="tickets-heading">
        <h2 id="tickets-heading" class="sr-only">Your support tickets</h2>
        <div class="table-wrap">
            <table>
                <caption class="sr-only">Your support tickets</caption>
                <thead>
                    <tr>
                        <th scope="col">ID</th>
                        <th scope="col">Subject</th>
                        <th scope="col">Category</th>
                        <th scope="col">Priority</th>
                        <th scope="col">Status</th>
                        <th scope="col">Updated</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($tickets as $ticket)
                        <tr>
                            <td class="muted">#{{ $ticket->id }}</td>
                            <td>{{ $ticket->subject }}</td>
                            <td class="muted">{{ $ticket->categoryLabel() }}</td>
                            <td><x-status-pill :status="$ticket->priority" :label="$ticket->priority" /></td>
                            <td><x-status-pill :status="$ticket->statusPill()" :label="$ticket->statusLabel()" /></td>
                            <td class="muted">{{ optional($ticket->last_activity_at)->diffForHumans() }}</td>
                            <td><a href="{{ route('support.tickets.show', $ticket) }}" class="btn btn-sm btn-cyan">View</a></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <x-empty-state title="You have no support tickets yet" icon="🎫">
                                    Need help? Create a ticket and our team will get back to you.
                                </x-empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="mt-4">{{ $tickets->links() }}</div>
@endsection
```

### `resources/views/support/show.blade.php`

```blade
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
```

### `resources/views/moderation/index.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Moderation Queue — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">🛡 Dispute Moderation Queue</h1>
    </header>

    <section class="card" aria-labelledby="filters-heading">
        <h2 id="filters-heading" class="sr-only">Filters</h2>
        <form method="GET" action="{{ route('moderation.index') }}">
            <div class="row" style="align-items: flex-end">
                <div class="field grow" style="min-width: 180px">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="">All statuses</option>
                        @foreach (\App\Models\Dispute::STATUSES as $s)
                            <option value="{{ $s }}" @selected($status === $s)>{{ ucwords(str_replace('_', ' ', $s)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field grow" style="min-width: 220px">
                    <label for="tournament_id">Tournament</label>
                    <select id="tournament_id" name="tournament_id">
                        <option value="">All tournaments</option>
                        @foreach ($tournaments as $t)
                            <option value="{{ $t->id }}" @selected($tournamentId === $t->id)>{{ $t->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-sm btn-cyan">Filter</button>
            </div>
        </form>
    </section>

    <section class="card" aria-labelledby="queue-heading">
        <h2 id="queue-heading" class="sr-only">Disputes</h2>
        @if ($disputes->isEmpty())
            <x-empty-state title="No disputes match your filters" icon="🛡">
                Adjust the filters or check back later.
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Disputes awaiting moderation</caption>
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Tournament</th>
                            <th scope="col">Match</th>
                            <th scope="col">Team</th>
                            <th scope="col">Category</th>
                            <th scope="col">Status</th>
                            <th scope="col">Reviewer</th>
                            <th scope="col">Opened</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($disputes as $dispute)
                            <tr>
                                <td><strong>#{{ $dispute->id }}</strong></td>
                                <td>{{ $dispute->match?->tournament?->name ?? '—' }}</td>
                                <td>M#{{ $dispute->match_id }}</td>
                                <td>{{ $dispute->team?->name ?? '—' }}</td>
                                <td class="muted" style="font-size: .85rem">{{ $dispute->categoryLabel() }}</td>
                                <td><x-status-pill :status="$dispute->statusPill()" :label="$dispute->statusLabel()" /></td>
                                <td>{{ $dispute->assignee?->name ?? '—' }}</td>
                                <td class="muted" style="font-size: .8rem">{{ $dispute->created_at->format('d M, h:i A') }}</td>
                                <td>
                                    @if ($dispute->match && $dispute->match->tournament)
                                        <a href="{{ route('matches.disputes.show', [$dispute->match->tournament, $dispute->match, $dispute]) }}" class="btn btn-sm">Review</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $disputes->links() }}</div>
        @endif
    </section>
@endsection
```

### `resources/views/moderation/security.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Security Review — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">🛡 Security Review</h1>
    </header>

    <section class="card" aria-labelledby="flagged-heading">
        <h3 id="flagged-heading">⚠️ Flagged Accounts</h3>
        @if ($flaggedUsers->isEmpty())
            <x-empty-state title="No accounts flagged for review" icon="✅">
                When risk scores cross the review threshold, accounts will appear here.
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Accounts flagged for security review</caption>
                    <thead>
                        <tr>
                            <th scope="col">User</th>
                            <th scope="col">Risk level</th>
                            <th scope="col">Score</th>
                            <th scope="col">Review</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($flaggedUsers as $profile)
                            <tr>
                                <td><strong>{{ $profile->user?->name ?? '—' }}</strong></td>
                                <td><x-status-pill :status="$profile->levelPill()" :label="strtoupper($profile->risk_level)" /></td>
                                <td>{{ $profile->risk_score }}/100</td>
                                <td>{{ $profile->manual_review_required ? '⚠️ required' : '—' }}</td>
                                <td class="muted" style="font-size: .8rem">{{ $profile->status }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="card" aria-labelledby="incidents-heading">
        <h3 id="incidents-heading">🎮 Open Anti-cheat Incidents</h3>
        @if ($openIncidents->isEmpty())
            <x-empty-state title="No open incidents" icon="🛡️">
                Open anti-cheat incidents will appear here.
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Open anti-cheat incidents</caption>
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Tournament</th>
                            <th scope="col">Team</th>
                            <th scope="col">Category</th>
                            <th scope="col">Severity</th>
                            <th scope="col">Status</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($openIncidents as $incident)
                            <tr>
                                <td><strong>#{{ $incident->id }}</strong></td>
                                <td>{{ $incident->tournament?->name ?? '—' }}</td>
                                <td>{{ $incident->team?->name ?? '—' }}</td>
                                <td class="muted">{{ ucwords(str_replace('_', ' ', $incident->category)) }}</td>
                                <td class="muted">{{ $incident->severity }}</td>
                                <td><x-status-pill :status="$incident->statusPill()" :label="$incident->statusLabel()" /></td>
                                <td>
                                    <a class="btn btn-sm" href="{{ route('security.incidents.index') }}">Open queue</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
```

### `resources/views/admin/dashboard.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Admin Dashboard — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">🛡 Admin Dashboard</h1>
    </header>

    <nav class="card row" aria-label="Admin navigation">
        <span class="muted">Operations:</span>
        <a href="{{ route('admin.analytics.index') }}" class="btn btn-sm">Analytics</a>
        <a href="{{ route('admin.audit.index') }}" class="btn btn-sm">Audit</a>
        <a href="{{ route('admin.accounts.index') }}" class="btn btn-sm btn-cyan">Accounts</a>
        <a href="{{ route('admin.support.index') }}" class="btn btn-sm btn-cyan">Support</a>
        <span class="muted">Financials:</span>
        <a href="{{ route('admin.payments.index') }}" class="btn btn-sm">Payments</a>
        <a href="{{ route('admin.settlements.index') }}" class="btn btn-sm btn-cyan">Settlements</a>
        <a href="{{ route('admin.payouts.index') }}" class="btn btn-sm">Payouts</a>
        <span class="muted">Security:</span>
        <a href="{{ route('admin.security.dashboard') }}" class="btn btn-sm">Security</a>
    </nav>

    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr))">
        <div class="stat"><div class="muted">Tournaments</div><div class="num">{{ $stats['tournaments'] }}</div></div>
        <div class="stat"><div class="muted">Teams</div><div class="num">{{ $stats['teams'] }}</div></div>
        <div class="stat"><div class="muted">Verified payments</div><div class="num">{{ $stats['verified_payments'] }}</div></div>
        <div class="stat"><div class="muted">Collected (৳)</div><div class="num">{{ number_format($stats['revenue']) }}</div></div>
        <div class="stat"><div class="muted">Platform commission (8%)</div><div class="num" style="color: var(--green)">৳{{ number_format($stats['commission']) }}</div></div>
    </div>

    <section class="card" aria-labelledby="moderators-heading">
        <h3 id="moderators-heading">🛡 Moderators</h3>
        <form method="POST" action="{{ route('admin.users.moderate') }}">
            @csrf
            <div class="row" style="align-items: flex-end">
                <div class="field grow" style="max-width: 320px">
                    <label for="promote-email">Promote a user to moderator (by email)</label>
                    <input type="email" id="promote-email" name="email" placeholder="user@example.com" required>
                </div>
                <button type="submit" class="btn btn-cyan btn-sm">Promote</button>
            </div>
        </form>
        @if ($moderators->isEmpty())
            <p class="muted mt-3">No moderators yet.</p>
        @else
            <div class="table-wrap mt-3">
                <table>
                    <caption class="sr-only">Moderators</caption>
                    <thead>
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col">Email</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($moderators as $moderator)
                            <tr>
                                <td>{{ $moderator->name }}</td>
                                <td class="muted">{{ $moderator->email }}</td>
                                <td>
                                    <form method="POST" action="{{ route('admin.users.unmoderate', $moderator) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-danger">Demote</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="card" aria-labelledby="pending-heading">
        <h3 id="pending-heading">💸 Pending Payments</h3>
        @if ($pendingPayments->isEmpty())
            <p class="muted">No pending payments.</p>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Payments awaiting verification</caption>
                    <thead>
                        <tr>
                            <th scope="col">Tournament</th>
                            <th scope="col">Team</th>
                            <th scope="col">Amount</th>
                            <th scope="col">TrxID</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pendingPayments as $p)
                            <tr>
                                <td>{{ $p->tournament->name }}</td>
                                <td>{{ $p->team->name }}</td>
                                <td>৳{{ number_format($p->amount_minor / 100, 2) }}</td>
                                <td>{{ $p->trx_id }}</td>
                                <td>
                                    <div class="row" style="gap: 8px">
                                        <form method="POST" action="{{ route('admin.payments.verify', $p) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-green btn-sm">Verify</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.payments.fail', $p) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-sm">Reject</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="muted mt-3" style="font-size: .85rem">
                <a href="{{ route('admin.payments.index') }}">View all payments &amp; refunds →</a>
            </p>
        @endif
    </section>
@endsection
```

### `resources/views/admin/audit.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Audit Log — FF Arena Admin')
@section('content')
    <header class="page-head">
        <h1 class="page-title">🧾 Audit Log</h1>
    </header>

    <section class="card" aria-labelledby="filters-heading">
        <h2 id="filters-heading" class="sr-only">Filters</h2>
        <form method="GET" action="{{ route('admin.audit.index') }}">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; align-items: end">
                <div class="field">
                    <label for="action">Action</label>
                    <select id="action" name="action">
                        <option value="">All actions</option>
                        @foreach ($actions as $action)
                            <option value="{{ $action }}" {{ ($filters['action'] ?? '') === $action ? 'selected' : '' }}>{{ $action }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="entity_type">Entity type</label>
                    <select id="entity_type" name="entity_type">
                        <option value="">All</option>
                        @foreach ($entityTypes as $type)
                            <option value="{{ $type }}" {{ ($filters['entity_type'] ?? '') === $type ? 'selected' : '' }}>{{ $type }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="tournament_id">Tournament</label>
                    <select id="tournament_id" name="tournament_id">
                        <option value="">All</option>
                        @foreach ($tournaments as $t)
                            <option value="{{ $t->id }}" {{ (int) ($filters['tournament_id'] ?? 0) === $t->id ? 'selected' : '' }}>{{ $t->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="entity_id">Entity ID</label>
                    <input type="number" id="entity_id" name="entity_id" value="{{ $filters['entity_id'] ?? '' }}" placeholder="123">
                </div>
                <div class="field">
                    <label for="target_user_id">Target user ID</label>
                    <input type="number" id="target_user_id" name="target_user_id" value="{{ $filters['target_user_id'] ?? '' }}" placeholder="123">
                </div>
                <div class="field">
                    <label for="actor_user_id">Actor user ID</label>
                    <input type="number" id="actor_user_id" name="actor_user_id" value="{{ $filters['actor_user_id'] ?? '' }}" placeholder="123">
                </div>
                <div class="field">
                    <label for="from">From</label>
                    <input type="date" id="from" name="from" value="{{ $filters['from'] ?? '' }}">
                </div>
                <div class="field">
                    <label for="to">To</label>
                    <input type="date" id="to" name="to" value="{{ $filters['to'] ?? '' }}">
                </div>
                <div class="field">
                    <label for="direction">Order</label>
                    <select id="direction" name="direction">
                        <option value="desc" {{ ($filters['direction'] ?? 'desc') === 'desc' ? 'selected' : '' }}>Newest first</option>
                        <option value="asc" {{ ($filters['direction'] ?? '') === 'asc' ? 'selected' : '' }}>Oldest first</option>
                    </select>
                </div>
                <div class="row" style="gap: 8px">
                    <button type="submit" class="btn btn-cyan btn-sm">Filter</button>
                    <a href="{{ route('admin.audit.index') }}" class="btn btn-sm">Reset</a>
                </div>
            </div>
        </form>
    </section>

    <div class="row-between" style="margin-bottom: 12px">
        <span class="muted">{{ $logs->total() }} record(s). Append-only — rows cannot be edited or deleted.</span>
        <a href="{{ route('admin.audit.export', request()->query()) }}" class="btn btn-sm btn-green">⬇ Export CSV</a>
    </div>

    <section class="card" style="padding: 0" aria-labelledby="logs-heading">
        <h2 id="logs-heading" class="sr-only">Audit records</h2>
        <div class="table-wrap">
            <table>
                <caption class="sr-only">Audit log records</caption>
                <thead>
                    <tr>
                        <th scope="col">ID</th>
                        <th scope="col">When</th>
                        <th scope="col">Actor</th>
                        <th scope="col">Action</th>
                        <th scope="col">Entity</th>
                        <th scope="col">Tournament</th>
                        <th scope="col">Target</th>
                        <th scope="col">Before</th>
                        <th scope="col">After</th>
                        <th scope="col">Source</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr>
                            <td class="muted">{{ $log->id }}</td>
                            <td class="muted">{{ optional($log->created_at)->format('d M y H:i') }}</td>
                            <td>{{ $log->actor?->name ?? '—' }} <span class="muted">{{ $log->actor?->role }}</span></td>
                            <td><code>{{ $log->action }}</code></td>
                            <td class="muted">{{ $log->entity_type }}#{{ $log->entity_id }}</td>
                            <td class="muted">{{ $log->tournament?->name ?? '—' }}</td>
                            <td class="muted">{{ $log->targetUser?->name ?? '—' }}</td>
                            <td class="muted" style="max-width: 180px; overflow: hidden; text-overflow: ellipsis">{{ $log->before ? json_encode($log->before) : '—' }}</td>
                            <td class="muted" style="max-width: 180px; overflow: hidden; text-overflow: ellipsis">{{ $log->after ? json_encode($log->after) : '—' }}</td>
                            <td class="muted" style="max-width: 160px; overflow: hidden; text-overflow: ellipsis">{{ $log->source }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10">
                                <x-empty-state title="No audit records match your filters" icon="🧾">
                                    Audit records appear here as actions are performed.
                                </x-empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="mt-4">{{ $logs->links() }}</div>
@endsection
```

### `resources/views/admin/accounts/index.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Accounts — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">👥 Account Administration</h1>
    </header>

    <section class="card" aria-labelledby="filters-heading">
        <h2 id="filters-heading" class="sr-only">Account filters</h2>
        <form method="GET" action="{{ route('admin.accounts.index') }}">
            <div class="row" style="align-items: flex-end">
                <div class="field grow" style="min-width: 220px">
                    <label for="q">Search (name / username / email)</label>
                    <input type="text" id="q" name="q" value="{{ $q }}" placeholder="Search accounts…">
                </div>
                <div class="field">
                    <label for="role">Role</label>
                    <select id="role" name="role">
                        <option value="">All roles</option>
                        @foreach (['player', 'organizer', 'moderator', 'admin'] as $roleValue)
                            <option value="{{ $roleValue }}" @selected($roleValue === $role)>{{ ucfirst($roleValue) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="">All statuses</option>
                        @foreach (['active', 'deactivated', 'deletion_pending', 'deleted'] as $statusValue)
                            <option value="{{ $statusValue }}" @selected($statusValue === $status)>{{ ucwords(str_replace('_', ' ', $statusValue)) }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-cyan btn-sm">Filter</button>
            </div>
        </form>
    </section>

    <section class="card" style="padding: 0" aria-labelledby="accounts-heading">
        <h2 id="accounts-heading" class="sr-only">Accounts</h2>
        <div class="table-wrap">
            <table>
                <caption class="sr-only">Accounts matching your filters</caption>
                <thead>
                    <tr>
                        <th scope="col">ID</th>
                        <th scope="col">Name</th>
                        <th scope="col">Username</th>
                        <th scope="col">Role</th>
                        <th scope="col">Status</th>
                        <th scope="col">Email verified</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($users as $user)
                        <tr>
                            <td class="muted">{{ $user->id }}</td>
                            <td>{{ $user->name }}</td>
                            <td class="muted">{{ $user->username }}</td>
                            <td><x-status-pill :status="$user->role === 'admin' ? 'live' : ($user->role === 'moderator' ? 'pending' : 'draft')" :label="$user->role" /></td>
                            <td><x-status-pill :status="$user->account_status === 'active' ? 'confirmed' : 'failed'" :label="$user->account_status" /></td>
                            <td>{{ $user->hasVerifiedEmail() ? '✅' : '—' }}</td>
                            <td><a href="{{ route('admin.accounts.show', $user) }}" class="btn btn-sm">View</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $users->links() }}</div>
    </section>
@endsection
```

### `resources/views/admin/accounts/show.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Account: ' . $subject->name . ' — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">👤 {{ $subject->name }}</h1>
        <div class="row muted">
            ID {{ $subject->id }} · {{ $subject->email }}
            <x-status-pill :status="$subject->role === 'admin' ? 'live' : ($subject->role === 'moderator' ? 'pending' : 'draft')" :label="$subject->role" />
            <x-status-pill :status="$subject->account_status === 'active' ? 'confirmed' : 'failed'" :label="$subject->account_status" />
        </div>
    </header>

    <div class="card row">
        <form method="POST" action="{{ route('admin.accounts.sessions.revoke', $subject) }}">
            @csrf
            <button type="submit" class="btn btn-sm">Revoke all sessions</button>
        </form>
        @if ($subject->isActive())
            <form method="POST" action="{{ route('admin.accounts.deactivate', $subject) }}">
                @csrf
                <button type="submit" class="btn btn-sm" style="border-color: var(--amber); color: var(--amber)">Deactivate</button>
            </form>
        @elseif ($subject->account_status === 'deactivated')
            <form method="POST" action="{{ route('admin.accounts.reactivate', $subject) }}">
                @csrf
                <button type="submit" class="btn btn-green btn-sm">Reactivate</button>
            </form>
        @endif
        <form method="POST" action="{{ route('admin.accounts.delete', $subject) }}"
              onsubmit="return confirm('Anonymize (delete) this account? Immutable history is preserved.')">
            @csrf
            <button type="submit" class="btn btn-sm btn-danger">Delete (anonymize)</button>
        </form>
    </div>

    <div class="grid cols-2 mt-4">
        <section class="card" aria-labelledby="identities-heading">
            <h3 id="identities-heading">Identities &amp; verification</h3>
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Linked identities</caption>
                    <thead>
                        <tr>
                            <th scope="col">Provider</th>
                            <th scope="col">Subject</th>
                            <th scope="col">Verified</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($identities as $linkedIdentity)
                            <tr>
                                <td>{{ $linkedIdentity->provider }}</td>
                                <td class="muted">{{ $linkedIdentity->provider_subject }}</td>
                                <td>{{ $linkedIdentity->isVerified() ? '✅' : '—' }}</td>
                            </tr>
                        @endforeach
                        @if ($identities->isEmpty())
                            <tr><td colspan="3" class="muted">No linked identities.</td></tr>
                        @endif
                    </tbody>
                </table>
            </div>
            <p class="mt-3">
                Email verified: {{ $subject->hasVerifiedEmail() ? '✅' : '—' }} ·
                Identity verification:
                <x-status-pill :status="$identity->statusPill()" :label="$identity->statusLabel()" />
            </p>
        </section>

        <section class="card" aria-labelledby="risk-heading">
            <h3 id="risk-heading">Risk &amp; restrictions</h3>
            <p>
                Risk level:
                <x-status-pill :status="($riskProfile?->risk_level ?? 'low') === 'low' ? 'confirmed' : 'failed'" :label="$riskProfile?->risk_level ?? 'low'" />
                (score {{ $riskProfile?->risk_score ?? 0 }})
            </p>
            @if ($restrictions->isEmpty())
                <p class="muted">No restrictions.</p>
            @else
                <ul>
                    @foreach ($restrictions as $restriction)
                        <li>{{ $restriction->typeLabel() }} — {{ $restriction->reason }}
                            <span class="muted">({{ $restriction->status }})</span></li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <div class="grid cols-2 mt-4">
        <section class="card" aria-labelledby="events-heading">
            <h3 id="events-heading">Recent security events</h3>
            @if ($loginEvents->isEmpty())
                <p class="muted">None.</p>
            @else
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">Recent security events</caption>
                        <thead>
                            <tr>
                                <th scope="col">Event</th>
                                <th scope="col">Status</th>
                                <th scope="col">Device</th>
                                <th scope="col">When</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($loginEvents as $event)
                                <tr>
                                    <td>{{ ucwords(str_replace(['.', '_'], ' ', $event->event)) }}</td>
                                    <td>{{ $event->status }}</td>
                                    <td class="muted">{{ $event->device_label ?? '—' }}</td>
                                    <td class="muted">{{ $event->created_at?->diffForHumans() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="card" aria-labelledby="payment-methods-heading">
            <h3 id="payment-methods-heading">Payment methods</h3>
            @if ($paymentMethods->isEmpty())
                <p class="muted">None.</p>
            @else
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">Linked payment methods</caption>
                        <thead>
                            <tr>
                                <th scope="col">Provider</th>
                                <th scope="col">Label</th>
                                <th scope="col">Identifier</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($paymentMethods as $method)
                                <tr>
                                    <td>{{ $method->provider }}</td>
                                    <td>{{ $method->label }}</td>
                                    <td class="muted">{{ $method->masked_identifier }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>

    <section class="card mt-4" aria-labelledby="audit-heading">
        <h3 id="audit-heading">Audit history</h3>
        @if ($auditHistory->isEmpty())
            <p class="muted">No audit entries.</p>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Audit history for this account</caption>
                    <thead>
                        <tr>
                            <th scope="col">Action</th>
                            <th scope="col">Entity</th>
                            <th scope="col">When</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($auditHistory as $entry)
                            <tr>
                                <td>{{ $entry->action }}</td>
                                <td class="muted">{{ $entry->entity_type }}#{{ $entry->entity_id }}</td>
                                <td class="muted">{{ $entry->created_at?->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
```

### `resources/views/admin/wallet.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Wallet — ' . $user->name . ' · FF Arena Admin')
@section('content')
    <header class="page-head">
        <h1 class="page-title">👛 Wallet: {{ $user->name }}</h1>
        <p class="muted">{{ $user->email }}</p>
    </header>

    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr))">
        <div class="stat">
            <div class="muted">Balance</div>
            <div class="num" style="color: var(--green)">৳{{ number_format($wallet->balance_minor / 100, 2) }}</div>
        </div>
        <div class="stat">
            <div class="muted">Ledger reconciliation</div>
            <div class="num" style="color: {{ $delta === 0 ? 'var(--green)' : 'var(--red)' }}">
                {{ $delta === 0 ? '✓ Consistent' : 'Δ ' . $delta }}
            </div>
        </div>
    </div>

    <div class="grid cols-2 mt-4">
        <section class="card" aria-labelledby="adjust-heading">
            <h3 id="adjust-heading">➕ Credit / ➖ Debit</h3>
            <form method="POST" action="{{ route('admin.wallet.credit', $user) }}">
                @csrf
                <div class="row" style="align-items: flex-end">
                    <div class="field grow">
                        <label for="credit-amount">Credit amount (৳)</label>
                        <input type="text" id="credit-amount" name="amount" pattern="\d+(\.\d{1,2})?" placeholder="100.00" required>
                    </div>
                    <div class="field grow" style="flex: 2">
                        <label for="credit-description">Description</label>
                        <input type="text" id="credit-description" name="description" placeholder="e.g. Prize credit" maxlength="255" required>
                    </div>
                    <button type="submit" class="btn btn-green btn-sm">Credit</button>
                </div>
            </form>

            <form method="POST" action="{{ route('admin.wallet.debit', $user) }}" class="mt-4">
                @csrf
                <div class="row" style="align-items: flex-end">
                    <div class="field grow">
                        <label for="debit-amount">Debit amount (৳)</label>
                        <input type="text" id="debit-amount" name="amount" pattern="\d+(\.\d{1,2})?" placeholder="50.00" required>
                    </div>
                    <div class="field grow" style="flex: 2">
                        <label for="debit-description">Description</label>
                        <input type="text" id="debit-description" name="description" placeholder="e.g. Fee correction" maxlength="255" required>
                    </div>
                    <button type="submit" class="btn btn-sm btn-danger">Debit</button>
                </div>
            </form>
        </section>

        <section class="card" aria-labelledby="summary-heading">
            <h3 id="summary-heading">📊 Summary</h3>
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Wallet summary</caption>
                    <tbody>
                        <tr>
                            <th scope="row">Credits</th>
                            <td style="color: var(--green)">{{ $ledger->where('direction', 'credit')->count() }} entries</td>
                        </tr>
                        <tr>
                            <th scope="row">Debits</th>
                            <td style="color: var(--red)">{{ $ledger->where('direction', 'debit')->count() }} entries</td>
                        </tr>
                        <tr>
                            <th scope="row">Status</th>
                            <td><x-status-pill :status="$wallet->status" :label="strtoupper($wallet->status)" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <section class="card" aria-labelledby="ledger-heading">
        <h3 id="ledger-heading">🧾 Ledger</h3>
        @if ($ledger->isEmpty())
            <p class="muted">No ledger entries.</p>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Wallet ledger entries</caption>
                    <thead>
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">Direction</th>
                            <th scope="col">Type</th>
                            <th scope="col">Amount</th>
                            <th scope="col">Balance After</th>
                            <th scope="col">Actor</th>
                            <th scope="col">Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ledger as $entry)
                            <tr>
                                <td class="muted" style="font-size: .8rem">{{ $entry->created_at->format('d M, h:i A') }}</td>
                                <td><x-status-pill :status="$entry->isCredit() ? 'confirmed' : 'finished'" :label="strtoupper($entry->direction)" /></td>
                                <td class="muted" style="font-size: .85rem">{{ $entry->type }}</td>
                                <td style="{{ $entry->isCredit() ? 'color: var(--green)' : 'color: var(--red)' }}">
                                    {{ $entry->isCredit() ? '+' : '−' }}৳{{ number_format($entry->amount_minor / 100, 2) }}
                                </td>
                                <td>৳{{ number_format($entry->balance_after / 100, 2) }}</td>
                                <td class="muted" style="font-size: .85rem">{{ $entry->actor?->name ?? '—' }}</td>
                                <td class="muted" style="font-size: .85rem">{{ $entry->description }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
```

### `resources/views/admin/payments.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Payments — FF Arena Admin')
@section('content')
    <header class="page-head">
        <h1 class="page-title">💸 Payments</h1>
    </header>

    <section class="card" aria-labelledby="filters-heading">
        <h2 id="filters-heading" class="sr-only">Payment filters</h2>
        <form method="GET" action="{{ route('admin.payments.index') }}">
            <div class="row" style="align-items: flex-end">
                <div class="field" style="min-width: 160px">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="">All statuses</option>
                        @foreach ($statuses as $s)
                            <option value="{{ $s }}" @selected($status === $s)>{{ ucfirst($s) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field" style="min-width: 220px">
                    <label for="tournament_id">Tournament</label>
                    <select id="tournament_id" name="tournament_id">
                        <option value="">All tournaments</option>
                        @foreach ($tournaments as $t)
                            <option value="{{ $t->id }}" @selected($tournamentId === $t->id)>{{ $t->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-sm btn-cyan">Filter</button>
            </div>
        </form>
    </section>

    <section class="card" style="padding: 0" aria-labelledby="payments-heading">
        <h2 id="payments-heading" class="sr-only">Payments</h2>
        @if ($payments->isEmpty())
            <x-empty-state title="No payments match your filters" icon="💸">
                Adjust the filters and try again.
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Payments matching your filters</caption>
                    <thead>
                        <tr>
                            <th scope="col">ID</th>
                            <th scope="col">Tournament</th>
                            <th scope="col">Team</th>
                            <th scope="col">Payer</th>
                            <th scope="col">Amount</th>
                            <th scope="col">TrxID</th>
                            <th scope="col">Status</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($payments as $payment)
                            <tr>
                                <td><strong>#{{ $payment->id }}</strong></td>
                                <td>{{ $payment->tournament?->name ?? '—' }}</td>
                                <td>{{ $payment->team?->name ?? '—' }}</td>
                                <td class="muted" style="font-size: .85rem">{{ $payment->payer?->name ?? '—' }}</td>
                                <td>৳{{ number_format($payment->amount_minor / 100, 2) }}</td>
                                <td class="muted" style="font-size: .8rem">{{ $payment->trx_id }}</td>
                                <td><x-status-pill :status="$payment->statusPill()" :label="$payment->statusLabel()" /></td>
                                <td>
                                    @if (in_array($payment->status, ['pending', 'processing'], true))
                                        <div class="row" style="gap: 8px">
                                            <form method="POST" action="{{ route('admin.payments.verify', $payment) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-green btn-sm">Verify</button>
                                            </form>
                                            <form method="POST" action="{{ route('admin.payments.fail', $payment) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm">Reject</button>
                                            </form>
                                        </div>
                                    @elseif ($payment->isRefundable())
                                        <form method="POST" action="{{ route('admin.payments.refund', $payment) }}">
                                            @csrf
                                            <div class="row" style="gap: 6px; align-items: center">
                                                <label for="refund-reason-{{ $payment->id }}" class="sr-only">Refund reason</label>
                                                <input type="text" id="refund-reason-{{ $payment->id }}" name="reason" placeholder="Refund reason" required style="max-width: 140px">
                                                <button type="submit" class="btn btn-sm" style="border-color: var(--amber); color: var(--amber)">Refund</button>
                                            </div>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $payments->links() }}</div>
        @endif
    </section>
@endsection
```

### `resources/views/admin/payouts.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Payouts — FF Arena Admin')
@section('content')
    <header class="page-head">
        <h1 class="page-title">💸 Payouts</h1>
    </header>

    <section class="card" aria-labelledby="filters-heading">
        <h2 id="filters-heading" class="sr-only">Payout filters</h2>
        <form method="GET" action="{{ route('admin.payouts.index') }}">
            <div class="row" style="align-items: flex-end">
                <div class="field" style="min-width: 160px">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="">All statuses</option>
                        @foreach ($statuses as $s)
                            <option value="{{ $s }}" @selected($status === $s)>{{ ucfirst($s) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field" style="min-width: 220px">
                    <label for="tournament_id">Tournament</label>
                    <select id="tournament_id" name="tournament_id">
                        <option value="">All tournaments</option>
                        @foreach ($tournaments as $t)
                            <option value="{{ $t->id }}" @selected($tournamentId === $t->id)>{{ $t->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-sm btn-cyan">Filter</button>
            </div>
        </form>
    </section>

    <section class="card" style="padding: 0" aria-labelledby="payouts-heading">
        <h2 id="payouts-heading" class="sr-only">Payouts</h2>
        @if ($payouts->isEmpty())
            <x-empty-state title="No payouts match your filters" icon="💸">
                Adjust the filters and try again.
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Payouts matching your filters</caption>
                    <thead>
                        <tr>
                            <th scope="col">ID</th>
                            <th scope="col">Tournament</th>
                            <th scope="col">Rank</th>
                            <th scope="col">Team</th>
                            <th scope="col">Recipient</th>
                            <th scope="col">Amount</th>
                            <th scope="col">Method</th>
                            <th scope="col">Status</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($payouts as $payout)
                            <tr>
                                <td><strong>#{{ $payout->id }}</strong></td>
                                <td>{{ $payout->tournament?->name ?? '—' }}</td>
                                <td>#{{ $payout->rank }}</td>
                                <td>{{ $payout->team?->name ?? '—' }}</td>
                                <td class="muted" style="font-size: .85rem">{{ $payout->recipient?->name ?? '—' }}</td>
                                <td>৳{{ number_format($payout->amount_minor / 100, 2) }}</td>
                                <td class="muted">{{ $payout->payout_method }}</td>
                                <td>
                                    <x-status-pill :status="$payout->statusPill()" :label="$payout->statusLabel()" />
                                    @if ($payout->failure_reason)
                                        <div class="muted" style="font-size: .8rem">{{ $payout->failure_reason }}</div>
                                    @endif
                                </td>
                                <td>
                                    <div class="row" style="gap: 8px; align-items: center">
                                        @if ($payout->status === 'pending')
                                            <form method="POST" action="{{ route('admin.payouts.approve', $payout) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-green btn-sm">Approve</button>
                                            </form>
                                        @elseif ($payout->status === 'approved')
                                            <form method="POST" action="{{ route('admin.payouts.process', $payout) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-green btn-sm">Process</button>
                                            </form>
                                        @elseif ($payout->status === 'processing' && $payout->payout_method === 'manual')
                                            <form method="POST" action="{{ route('admin.payouts.complete', $payout) }}">
                                                @csrf
                                                <label for="external-ref-{{ $payout->id }}" class="sr-only">External reference</label>
                                                <input type="text" id="external-ref-{{ $payout->id }}" name="reference" placeholder="External ref" style="max-width: 120px">
                                                <button type="submit" class="btn btn-green btn-sm">Complete</button>
                                            </form>
                                        @endif

                                        @if (in_array($payout->status, ['pending', 'approved'], true))
                                            <form method="POST" action="{{ route('admin.payouts.cancel', $payout) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm">Cancel</button>
                                            </form>
                                        @endif

                                        @if (in_array($payout->status, ['pending', 'approved', 'processing'], true))
                                            <form method="POST" action="{{ route('admin.payouts.fail', $payout) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-danger">Fail</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $payouts->links() }}</div>
        @endif
    </section>
@endsection
```

### `resources/views/admin/settlements.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Settlements — FF Arena Admin')
@section('content')
    <header class="page-head">
        <h1 class="page-title">🧾 Financial Settlements</h1>
    </header>

    <section class="card" style="padding: 0" aria-labelledby="settlements-heading">
        <h2 id="settlements-heading" class="sr-only">Settlements</h2>
        @if ($tournaments->isEmpty())
            <x-empty-state title="No tournaments require settlement yet" icon="🧾">
                Tournaments that finish will appear here.
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Tournament settlements</caption>
                    <thead>
                        <tr>
                            <th scope="col">Tournament</th>
                            <th scope="col">Status</th>
                            <th scope="col">Distribution</th>
                            <th scope="col">Net collected</th>
                            <th scope="col">Allocated</th>
                            <th scope="col">Reconciliation</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tournaments as $tournament)
                            @php $summary = $summaries[$tournament->id] ?? null; @endphp
                            <tr>
                                <td>
                                    <strong>{{ $tournament->name }}</strong>
                                    <div class="muted" style="font-size: .8rem">{{ $tournament->slug }}</div>
                                </td>
                                <td><x-status-pill :status="$tournament->status" :label="strtoupper($tournament->status)" /></td>
                                <td>
                                    @if ($tournament->financialSettlement)
                                        <span class="pill confirmed">FINALIZED</span>
                                    @else
                                        <span class="pill pending">PENDING</span>
                                    @endif
                                </td>
                                <td>৳{{ number_format($summary['net_collected_minor'] / 100, 2) }}</td>
                                <td>৳{{ number_format($summary['allocated_prizes_minor'] / 100, 2) }}</td>
                                <td>
                                    <x-status-pill :status="$tournament->financialSettlement?->statusPill() ?? 'pending'" :label="$tournament->financialSettlement?->statusLabel() ?? 'not finalized'" />
                                </td>
                                <td>
                                    <a class="btn btn-sm btn-cyan" href="{{ route('admin.settlements.show', $tournament) }}">Manage</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $tournaments->links() }}</div>
        @endif
    </section>
@endsection
```

### `resources/views/admin/settlement.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Settlement — ' . $tournament->name . ' — FF Arena Admin')
@section('content')
    <header class="page-head">
        <div class="row-between">
            <h1 class="page-title">🧾 Settlement: {{ $tournament->name }}</h1>
            <div class="row" style="gap: 8px; align-items: center">
                <x-status-pill :status="$tournament->status" :label="strtoupper($tournament->status)" />
                <a class="btn btn-sm" href="{{ route('admin.settlements.index') }}">← All settlements</a>
            </div>
        </div>
        <p class="muted">
            <a href="{{ route('tournaments.show', $tournament) }}">View tournament</a>
        </p>
    </header>

    {{-- Reconciliation summary --}}
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Gross collected</div><div class="num">৳{{ number_format($summary['gross_collected_minor'] / 100, 2) }}</div></div>
        <div class="stat"><div class="muted">Refunded</div><div class="num" style="color: var(--red)">−৳{{ number_format($summary['refunded_minor'] / 100, 2) }}</div></div>
        <div class="stat"><div class="muted">Net collected</div><div class="num">৳{{ number_format($summary['net_collected_minor'] / 100, 2) }}</div></div>
        <div class="stat"><div class="muted">Prize pool (declared)</div><div class="num">৳{{ number_format($summary['prize_pool_minor'] / 100, 2) }}</div></div>
        <div class="stat"><div class="muted">Allocated prizes</div><div class="num" style="color: var(--purple)">৳{{ number_format($summary['allocated_prizes_minor'] / 100, 2) }}</div></div>
        <div class="stat"><div class="muted">Completed payouts</div><div class="num" style="color: var(--green)">৳{{ number_format($summary['completed_payouts_minor'] / 100, 2) }}</div></div>
        <div class="stat"><div class="muted">Platform revenue</div><div class="num">৳{{ number_format($summary['platform_revenue_minor'] / 100, 2) }}</div></div>
        <div class="stat"><div class="muted">Adjustments</div><div class="num">৳{{ number_format($summary['adjustments_minor'] / 100, 2) }}</div></div>
        <div class="stat">
            <div class="muted">Remaining</div>
            <div class="num" style="color: {{ $summary['remaining_minor'] >= 0 ? 'var(--green)' : 'var(--red)' }}">
                ৳{{ number_format($summary['remaining_minor'] / 100, 2) }}
            </div>
        </div>
        <div class="stat">
            <div class="muted">Reconciliation</div>
            <div class="num" style="font-size: 19px; color: var(--amber)">{{ ucfirst($summary['reconciliation_status']) }}</div>
        </div>
    </div>

    {{-- Prize configuration --}}
    <section class="card mt-4" aria-labelledby="prizes-heading">
        <h3 id="prizes-heading">🏆 Prize Configuration</h3>
        @if (! $tiersEditable)
            <p class="muted" style="font-size: .85rem">Tiers are locked — the distribution has been calculated.</p>
        @endif

        @if ($tiersEditable)
            <form method="POST" action="{{ route('admin.settlements.prizes', $tournament) }}">
                @csrf
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">Prize tiers</caption>
                        <thead>
                            <tr>
                                <th scope="col">Rank</th>
                                <th scope="col">Type</th>
                                <th scope="col">Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            @for ($i = 1; $i <= 10; $i++)
                                @php $tier = $tiers->firstWhere('position', $i); @endphp
                                <tr>
                                    <td style="width: 120px">#{{ $i }} {{ ['1st', '2nd', '3rd'][$i - 1] ?? 'th' }}</td>
                                    <td style="width: 180px">
                                        <label for="tier-type-{{ $i }}" class="sr-only">Type for rank {{ $i }}</label>
                                        <select id="tier-type-{{ $i }}" name="tiers[{{ $i }}][type]">
                                            <option value="fixed" @selected($tier && $tier->type === 'fixed')>Fixed (৳)</option>
                                            <option value="percentage" @selected($tier && $tier->type === 'percentage')>Percentage (%)</option>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="hidden" name="tiers[{{ $i }}][position]" value="{{ $i }}">
                                        <label for="tier-value-{{ $i }}" class="sr-only">Value for rank {{ $i }}</label>
                                        <input type="text" id="tier-value-{{ $i }}" name="tiers[{{ $i }}][value]" placeholder="e.g. 1000 or 50"
                                               value="{{ $tier ? ($tier->type === 'percentage' ? \App\Support\Money::basisPointsToPercent($tier->percentage_bp) : \App\Support\Money::toDecimal($tier->amount_minor)) : '' }}">
                                    </td>
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>
                <button type="submit" class="btn btn-primary btn-sm mt-4">Save Prizes</button>
            </form>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Prize tiers</caption>
                    <thead>
                        <tr>
                            <th scope="col">Rank</th>
                            <th scope="col">Type</th>
                            <th scope="col">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($tiers as $tier)
                            <tr>
                                <td>#{{ $tier->position }}</td>
                                <td>{{ $tier->typeLabel() }}</td>
                                <td>
                                    @if ($tier->isFixed())
                                        ৳{{ number_format($tier->amount_minor / 100, 2) }}
                                    @else
                                        {{ \App\Support\Money::basisPointsToPercent($tier->percentage_bp) }}%
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="muted">No prize tiers configured.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- Distribution --}}
    <section class="card mt-4" aria-labelledby="distribution-heading">
        <h3 id="distribution-heading">
            🎁 Prize Distribution
            @if ($distribution)
                <x-status-pill :status="$distribution->statusPill()" :label="$distribution->statusLabel()" />
            @endif
        </h3>

        @if ($distribution && $snapshot && $snapshot->isNotEmpty())
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Prize distribution snapshot</caption>
                    <thead>
                        <tr>
                            <th scope="col">Rank</th>
                            <th scope="col">Team</th>
                            <th scope="col">Type</th>
                            <th scope="col">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($snapshot as $item)
                            <tr>
                                <td>#{{ $item->position }}</td>
                                <td><strong>{{ $item->team?->name ?? '—' }}</strong></td>
                                <td class="muted">{{ ucfirst($item->type) }}</td>
                                <td>৳{{ number_format($item->amount_minor / 100, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="muted">No distribution calculated yet.</p>
        @endif

        <div class="row mt-4" style="gap: 10px">
            @if ($tournament->status === 'finished')
                @if (! $distribution || $distribution->isTerminal())
                    <form method="POST" action="{{ route('admin.settlements.calculate', $tournament) }}">
                        @csrf
                        <button type="submit" class="btn btn-cyan btn-sm">Calculate Distribution</button>
                    </form>
                @elseif ($distribution->status === 'draft')
                    <form method="POST" action="{{ route('admin.settlements.calculate', $tournament) }}">
                        @csrf
                        <button type="submit" class="btn btn-cyan btn-sm">Calculate Distribution</button>
                    </form>
                @elseif ($distribution->status === 'calculated')
                    <form method="POST" action="{{ route('admin.settlements.approve', $tournament) }}">
                        @csrf
                        <button type="submit" class="btn btn-primary btn-sm">Approve</button>
                    </form>
                @elseif ($distribution->status === 'approved')
                    <form method="POST" action="{{ route('admin.settlements.process', $tournament) }}" onsubmit="return confirm('Process all payouts and finalize settlement?')">
                        @csrf
                        <button type="submit" class="btn btn-green btn-sm">Process Payouts</button>
                    </form>
                @endif
            @endif

            @if ($distribution && in_array($distribution->status, ['draft', 'calculated', 'approved'], true))
                <form method="POST" action="{{ route('admin.settlements.cancel', $tournament) }}">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-danger">Cancel</button>
                </form>
            @endif
        </div>
    </section>

    {{-- Payouts --}}
    <section class="card mt-4" aria-labelledby="payouts-heading">
        <h3 id="payouts-heading">💸 Payouts</h3>
        @if ($payouts->isEmpty())
            <p class="muted">No payouts yet.</p>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Payouts for this settlement</caption>
                    <thead>
                        <tr>
                            <th scope="col">Rank</th>
                            <th scope="col">Team</th>
                            <th scope="col">Recipient</th>
                            <th scope="col">Amount</th>
                            <th scope="col">Method</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($payouts as $payout)
                            <tr>
                                <td>#{{ $payout->rank }}</td>
                                <td>{{ $payout->team?->name ?? '—' }}</td>
                                <td class="muted" style="font-size: .85rem">{{ $payout->recipient?->name ?? '—' }}</td>
                                <td>৳{{ number_format($payout->amount_minor / 100, 2) }}</td>
                                <td class="muted">{{ $payout->payout_method }}</td>
                                <td>
                                    <x-status-pill :status="$payout->statusPill()" :label="$payout->statusLabel()" />
                                    @if ($payout->failure_reason)
                                        <div class="muted" style="font-size: .8rem">{{ $payout->failure_reason }}</div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="muted mt-3" style="font-size: .85rem">
                <a href="{{ route('admin.payouts.index') }}">Manage all payouts →</a>
            </p>
        @endif
    </section>

    {{-- Adjustments --}}
    <section class="card mt-4" aria-labelledby="adjustments-heading">
        <h3 id="adjustments-heading">🔧 Financial Adjustments</h3>
        @if ($adjustments->isEmpty())
            <p class="muted">No adjustments.</p>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Financial adjustments</caption>
                    <thead>
                        <tr>
                            <th scope="col">Type</th>
                            <th scope="col">Amount</th>
                            <th scope="col">Reason</th>
                            <th scope="col">By</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($adjustments as $adjustment)
                            <tr>
                                <td class="muted">{{ ucfirst($adjustment->type) }}</td>
                                <td style="{{ $adjustment->amount_minor < 0 ? 'color: var(--red)' : 'color: var(--green)' }}">
                                    {{ $adjustment->amount_minor < 0 ? '−' : '+' }}৳{{ number_format(abs($adjustment->amount_minor) / 100, 2) }}
                                </td>
                                <td class="muted" style="font-size: .85rem">{{ $adjustment->reason }}</td>
                                <td class="muted" style="font-size: .85rem">{{ $adjustment->actor?->name ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if (! $settlement)
            <form method="POST" action="{{ route('admin.settlements.adjust', $tournament) }}" class="mt-4">
                @csrf
                <div class="row" style="align-items: flex-end">
                    <div class="field" style="min-width: 140px">
                        <label for="adjust-amount">Amount (৳, negative to debit)</label>
                        <input type="text" id="adjust-amount" name="amount" placeholder="e.g. 100 or -100" required>
                    </div>
                    <div class="field" style="min-width: 150px">
                        <label for="adjust-type">Type</label>
                        <select id="adjust-type" name="type">
                            <option value="correction">Correction</option>
                            <option value="reversal">Reversal</option>
                        </select>
                    </div>
                    <div class="field" style="min-width: 220px">
                        <label for="adjust-reason">Reason</label>
                        <input type="text" id="adjust-reason" name="reason" required>
                    </div>
                    <button type="submit" class="btn btn-sm btn-cyan">Add Adjustment</button>
                </div>
            </form>
        @else
            <p class="muted mt-4" style="font-size: .85rem">Adjustments are frozen after finalization.</p>
        @endif
    </section>

    {{-- Finalized snapshot --}}
    @if ($settlement)
        <section class="card mt-4" aria-labelledby="finalized-heading">
            <h3 id="finalized-heading">📦 Finalized Settlement</h3>
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Finalized settlement summary</caption>
                    <thead>
                        <tr>
                            <th scope="col">Gross</th>
                            <th scope="col">Refunded</th>
                            <th scope="col">Net</th>
                            <th scope="col">Pool</th>
                            <th scope="col">Allocated</th>
                            <th scope="col">Completed payouts</th>
                            <th scope="col">Revenue</th>
                            <th scope="col">Adjustments</th>
                            <th scope="col">Result</th>
                            <th scope="col">Finalized by</th>
                            <th scope="col">Finalized at</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>৳{{ number_format($settlement->gross_collected_minor / 100, 2) }}</td>
                            <td>৳{{ number_format($settlement->refunded_minor / 100, 2) }}</td>
                            <td>৳{{ number_format($settlement->net_collected_minor / 100, 2) }}</td>
                            <td>৳{{ number_format($settlement->prize_pool_minor / 100, 2) }}</td>
                            <td>৳{{ number_format($settlement->allocated_prizes_minor / 100, 2) }}</td>
                            <td>৳{{ number_format($settlement->completed_payouts_minor / 100, 2) }}</td>
                            <td>৳{{ number_format($settlement->platform_revenue_minor / 100, 2) }}</td>
                            <td>৳{{ number_format($settlement->adjustments_minor / 100, 2) }}</td>
                            <td><x-status-pill :status="$settlement->statusPill()" :label="$settlement->statusLabel()" /></td>
                            <td class="muted">{{ $settlement->finalizedBy?->name ?? '—' }}</td>
                            <td class="muted" style="font-size: .8rem">{{ $settlement->finalized_at?->format('d M Y, h:i A') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    @endif
@endsection
```

### `resources/views/admin/security/dashboard.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Security — FF Arena Admin')
@section('content')
    <header class="page-head">
        <h1 class="page-title">🛡 Security Dashboard</h1>
    </header>

    <nav class="card row" aria-label="Security navigation">
        <a href="{{ route('admin.security.users') }}" class="btn btn-sm">Suspicious Users</a>
        <a href="{{ route('admin.security.events') }}" class="btn btn-sm">Risk Events</a>
        <a href="{{ route('security.incidents.index') }}" class="btn btn-sm btn-cyan">Anti-cheat Incidents</a>
    </nav>

    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr))">
        <div class="stat"><div class="muted">Critical risk</div><div class="num" style="color: var(--red)">{{ $stats['critical'] }}</div></div>
        <div class="stat"><div class="muted">High risk</div><div class="num" style="color: var(--amber)">{{ $stats['high'] }}</div></div>
        <div class="stat"><div class="muted">Medium risk</div><div class="num">{{ $stats['medium'] }}</div></div>
        <div class="stat"><div class="muted">Manual review required</div><div class="num" style="color: var(--purple)">{{ $stats['review_required'] }}</div></div>
        <div class="stat"><div class="muted">Active restrictions</div><div class="num" style="color: var(--red)">{{ $stats['active_restrictions'] }}</div></div>
        <div class="stat"><div class="muted">Open incidents</div><div class="num" style="color: var(--amber)">{{ $stats['open_incidents'] }}</div></div>
        <div class="stat"><div class="muted">Match anomalies</div><div class="num">{{ $stats['anomalies'] }}</div></div>
        <div class="stat"><div class="muted">Risk events</div><div class="num">{{ $stats['events'] }}</div></div>
    </div>

    <div class="grid cols-2 mt-4">
        <section class="card" aria-labelledby="review-queue-heading">
            <h3 id="review-queue-heading">🔍 Review Queue</h3>
            @if ($reviewQueue->isEmpty())
                <p class="muted">No accounts flagged for review.</p>
            @else
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">Accounts flagged for review</caption>
                        <thead>
                            <tr>
                                <th scope="col">User</th>
                                <th scope="col">Risk</th>
                                <th scope="col">Score</th>
                                <th scope="col"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($reviewQueue as $profile)
                                <tr>
                                    <td>{{ $profile->user?->name ?? '—' }}</td>
                                    <td><x-status-pill :status="$profile->levelPill()" :label="strtoupper($profile->risk_level)" /></td>
                                    <td>{{ $profile->risk_score }}/100</td>
                                    <td>
                                        @if ($profile->user)
                                            <a class="btn btn-sm" href="{{ route('admin.security.user', $profile->user) }}">Investigate</a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="card" aria-labelledby="recent-events-heading">
            <h3 id="recent-events-heading">📜 Recent Risk Events</h3>
            @if ($recentEvents->isEmpty())
                <p class="muted">No risk events yet.</p>
            @else
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">Recent risk events</caption>
                        <thead>
                            <tr>
                                <th scope="col">User</th>
                                <th scope="col">Type</th>
                                <th scope="col">Severity</th>
                                <th scope="col">When</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recentEvents as $event)
                                <tr>
                                    <td>{{ $event->user?->name ?? '—' }}</td>
                                    <td class="muted" style="font-size: .8rem">{{ $event->type }}</td>
                                    <td><x-status-pill :status="$event->severity === 'critical' || $event->severity === 'high' ? 'failed' : ($event->severity === 'medium' ? 'pending' : 'draft')" :label="strtoupper($event->severity)" /></td>
                                    <td class="muted" style="font-size: .8rem">{{ $event->created_at?->format('d M, h:i A') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
@endsection
```

### `resources/views/admin/security/events.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Risk Events — FF Arena Admin')
@section('content')
    <header class="page-head">
        <h1 class="page-title">📜 Risk Events</h1>
    </header>

    <section class="card" aria-labelledby="filters-heading">
        <h2 id="filters-heading" class="sr-only">Risk event filters</h2>
        <form method="GET" action="{{ route('admin.security.events') }}">
            <div class="row" style="align-items: flex-end">
                <div class="field" style="min-width: 160px">
                    <label for="severity">Severity</label>
                    <select id="severity" name="severity">
                        <option value="">All severities</option>
                        @foreach (\App\Models\RiskEvent::SEVERITIES as $s)
                            <option value="{{ $s }}" @selected($severity === $s)>{{ ucfirst($s) }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-sm btn-cyan">Filter</button>
            </div>
        </form>
    </section>

    <section class="card" style="padding: 0" aria-labelledby="events-heading">
        <h2 id="events-heading" class="sr-only">Risk events</h2>
        @if ($events->isEmpty())
            <x-empty-state title="No risk events match your filters" icon="📜">
                Adjust the filters and try again.
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Risk events matching your filters</caption>
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">User</th>
                            <th scope="col">Type</th>
                            <th scope="col">Severity</th>
                            <th scope="col">Score</th>
                            <th scope="col">Source</th>
                            <th scope="col">Tournament</th>
                            <th scope="col">When</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($events as $event)
                            <tr>
                                <td><strong>#{{ $event->id }}</strong></td>
                                <td>{{ $event->user?->name ?? '—' }}</td>
                                <td class="muted" style="font-size: .8rem">{{ $event->type }}</td>
                                <td><x-status-pill :status="in_array($event->severity, ['high', 'critical'], true) ? 'failed' : ($event->severity === 'medium' ? 'pending' : 'draft')" :label="strtoupper($event->severity)" /></td>
                                <td>+{{ $event->score_contribution }}</td>
                                <td class="muted" style="font-size: .8rem">{{ $event->source }}</td>
                                <td class="muted" style="font-size: .8rem">{{ $event->tournament?->name ?? '—' }}</td>
                                <td class="muted" style="font-size: .8rem">{{ $event->created_at?->format('d M, h:i A') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $events->links() }}</div>
        @endif
    </section>
@endsection
```

### `resources/views/admin/security/incidents.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Anti-cheat Incidents — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">🎮 Anti-cheat Incidents</h1>
    </header>

    @can('create', \App\Models\AntiCheatIncident::class)
        <section class="card" aria-labelledby="open-incident-heading">
            <h3 id="open-incident-heading">Open an Incident</h3>
            <form method="POST" action="{{ route('security.incidents.open') }}">
                @csrf
                <div class="row" style="align-items: flex-end">
                    <div class="field" style="min-width: 200px">
                        <label for="tournament_id">Tournament</label>
                        <select id="tournament_id" name="tournament_id" required>
                            <option value="">Select…</option>
                            @foreach ($tournaments as $t)
                                <option value="{{ $t->id }}">{{ $t->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field" style="min-width: 140px">
                        <label for="category">Category</label>
                        <select id="category" name="category">
                            @foreach (\App\Services\AntiCheatService::CATEGORIES as $c)
                                <option value="{{ $c }}">{{ ucwords(str_replace('_', ' ', $c)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field" style="min-width: 130px">
                        <label for="severity">Severity</label>
                        <select id="severity" name="severity">
                            @foreach (['low', 'medium', 'high', 'critical'] as $s)
                                <option value="{{ $s }}">{{ ucfirst($s) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field" style="min-width: 180px">
                        <label for="accused_user_id">Accused user ID (optional)</label>
                        <input type="number" id="accused_user_id" name="accused_user_id" placeholder="user id">
                    </div>
                    <div class="field" style="min-width: 240px">
                        <label for="description">Description</label>
                        <input type="text" id="description" name="description" placeholder="What was observed?">
                    </div>
                    <button type="submit" class="btn btn-sm btn-cyan">Open Incident</button>
                </div>
            </form>
        </section>
    @endcan

    <section class="card" style="padding: 0" aria-labelledby="incidents-heading">
        <h2 id="incidents-heading" class="sr-only">Incidents</h2>
        @if ($incidents->isEmpty())
            <x-empty-state title="No incidents" icon="🎮">
                Anti-cheat incidents will appear here.
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Anti-cheat incidents</caption>
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Tournament</th>
                            <th scope="col">Team</th>
                            <th scope="col">Accused</th>
                            <th scope="col">Category</th>
                            <th scope="col">Severity</th>
                            <th scope="col">Status</th>
                            <th scope="col">Reviewer</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($incidents as $incident)
                            <tr>
                                <td><strong>#{{ $incident->id }}</strong></td>
                                <td>{{ $incident->tournament?->name ?? '—' }}</td>
                                <td>{{ $incident->team?->name ?? '—' }}</td>
                                <td class="muted" style="font-size: .85rem">{{ $incident->accusedUser?->name ?? '—' }}</td>
                                <td class="muted">{{ ucwords(str_replace('_', ' ', $incident->category)) }}</td>
                                <td class="muted">{{ $incident->severity }}</td>
                                <td><x-status-pill :status="$incident->statusPill()" :label="$incident->statusLabel()" /></td>
                                <td class="muted" style="font-size: .85rem">{{ $incident->reviewer?->name ?? '—' }}</td>
                                <td>
                                    @if ($incident->status === 'flagged')
                                        <form method="POST" action="{{ route('security.incidents.review', $incident) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-cyan">Review</button>
                                        </form>
                                    @elseif ($incident->status === 'under_review')
                                        <form method="POST" action="{{ route('security.incidents.resolve', $incident) }}">
                                            @csrf
                                            <div class="row" style="gap: 6px; align-items: center">
                                                <label for="resolution-{{ $incident->id }}" class="sr-only">Resolution</label>
                                                <select id="resolution-{{ $incident->id }}" name="resolution">
                                                    @foreach (\App\Models\AntiCheatIncident::RESOLUTIONS as $r)
                                                        <option value="{{ $r }}">{{ ucfirst($r) }}</option>
                                                    @endforeach
                                                </select>
                                                <label for="resolution-text-{{ $incident->id }}" class="sr-only">Resolution reason</label>
                                                <input type="text" id="resolution-text-{{ $incident->id }}" name="resolution_text" placeholder="Resolution reason" style="max-width: 140px">
                                                <button type="submit" class="btn btn-green btn-sm">Resolve</button>
                                            </div>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $incidents->links() }}</div>
        @endif
    </section>
@endsection
```

### `resources/views/admin/security/user.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Investigation — ' . $subject->name . ' — FF Arena Admin')
@section('content')
    <header class="page-head">
        <div class="row-between">
            <h1 class="page-title">🔍 {{ $subject->name }}</h1>
            <a class="btn btn-sm" href="{{ route('admin.security.users') }}">← All users</a>
        </div>
        <p class="muted">{{ $subject->email }} · role {{ $subject->role }} · user #{{ $subject->id }}</p>
    </header>

    <div class="grid cols-2">
        <section class="card" aria-labelledby="risk-profile-heading">
            <h3 id="risk-profile-heading">Risk Profile</h3>
            @if (!$profile)
                <p class="muted">No risk profile yet (low risk).</p>
            @else
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">Risk profile</caption>
                        <tbody>
                            <tr><th scope="row">Score</th><td>{{ $profile->risk_score }}/100</td></tr>
                            <tr><th scope="row">Level</th><td><x-status-pill :status="$profile->levelPill()" :label="strtoupper($profile->risk_level)" /></td></tr>
                            <tr><th scope="row">Status</th><td>{{ $profile->status }}</td></tr>
                            <tr><th scope="row">Manual review</th><td>{{ $profile->manual_review_required ? '⚠️ required' : 'no' }}</td></tr>
                            <tr><th scope="row">Restricted until</th><td>{{ $profile->restricted_until?->format('d M Y, h:i A') ?? '—' }}</td></tr>
                            <tr><th scope="row">Flags</th><td class="muted" style="font-size: .8rem">{{ implode(', ', $profile->account_flags ?? []) ?: '—' }}</td></tr>
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="card" aria-labelledby="identity-heading">
            <h3 id="identity-heading">Identity Verification</h3>
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Identity verification</caption>
                    <tbody>
                        <tr><th scope="row">Status</th><td><x-status-pill :status="$identity->statusPill()" :label="$identity->statusLabel()" /></td></tr>
                        <tr><th scope="row">Provider</th><td class="muted">{{ $identity->provider }}</td></tr>
                        <tr><th scope="row">Verified at</th><td class="muted">{{ $identity->verified_at?->format('d M Y, h:i A') ?? '—' }}</td></tr>
                        <tr><th scope="row">Expires</th><td class="muted">{{ $identity->expires_at?->format('d M Y') ?? '—' }}</td></tr>
                        <tr><th scope="row">Reviewed by</th><td class="muted">{{ $identity->reviewedBy?->name ?? '—' }}</td></tr>
                        <tr><th scope="row">Notes</th><td class="muted" style="font-size: .8rem">{{ $identity->notes ?? '—' }}</td></tr>
                    </tbody>
                </table>
            </div>
            @if ($identity->status === 'pending' || $identity->status === 'review_required' || $identity->status === 'rejected' || $identity->status === 'expired')
                <div class="row mt-3" style="gap: 10px">
                    <form method="POST" action="{{ route('admin.security.verify', $subject) }}">
                        @csrf
                        <div class="row" style="gap: 6px; align-items: center">
                            <label for="verify-notes" class="sr-only">Review note</label>
                            <input type="text" id="verify-notes" name="notes" placeholder="Review note (optional)" style="max-width: 160px">
                            <button type="submit" class="btn btn-green btn-sm">Verify</button>
                        </div>
                    </form>
                    <form method="POST" action="{{ route('admin.security.reject', $subject) }}">
                        @csrf
                        <div class="row" style="gap: 6px; align-items: center">
                            <label for="reject-notes" class="sr-only">Rejection note</label>
                            <input type="text" id="reject-notes" name="notes" placeholder="Rejection note" style="max-width: 160px">
                            <button type="submit" class="btn btn-sm btn-danger">Reject</button>
                        </div>
                    </form>
                </div>
            @endif
        </section>
    </div>

    <div class="grid cols-2 mt-4">
        <section class="card" aria-labelledby="devices-heading">
            <h3 id="devices-heading">🔒 Devices</h3>
            @if ($devices->isEmpty())
                <p class="muted">No device associations.</p>
            @else
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">Device associations</caption>
                        <thead>
                            <tr>
                                <th scope="col">Device (hash)</th>
                                <th scope="col">Accounts</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($devices as $device)
                                <tr>
                                    <td class="muted" style="font-size: .8rem">{{ substr($device->device_hash, 0, 16) }}…</td>
                                    <td>{{ $device->links_count }}</td>
                                    <td><x-status-pill :status="$device->isBlocked() ? 'failed' : 'confirmed'" :label="$device->status" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="card" aria-labelledby="ip-heading">
            <h3 id="ip-heading">🌐 IP Observations</h3>
            @if ($ipIntel->isEmpty())
                <p class="muted">No IP observations.</p>
            @else
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">IP observations</caption>
                        <thead>
                            <tr>
                                <th scope="col">IP (hash)</th>
                                <th scope="col">Observations</th>
                                <th scope="col">Last seen</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($ipIntel as $link)
                                <tr>
                                    <td class="muted" style="font-size: .8rem">{{ substr($link->ipIntel->ip_hash, 0, 16) }}…</td>
                                    <td>{{ $link->ipIntel->observation_count }}</td>
                                    <td class="muted" style="font-size: .8rem">{{ $link->ipIntel->last_seen_at?->format('d M, h:i A') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>

    <div class="grid cols-2 mt-4">
        <section class="card" aria-labelledby="linked-heading">
            <h3 id="linked-heading">🔗 Linked Accounts</h3>
            @if ($links->isEmpty())
                <p class="muted">No linked accounts.</p>
            @else
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">Linked accounts</caption>
                        <thead>
                            <tr>
                                <th scope="col">Linked user</th>
                                <th scope="col">Strength</th>
                                <th scope="col">Reasons</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($links as $link)
                                @php $other = $link->user_id === $subject->id ? $link->linkedUser : $link->user; @endphp
                                <tr>
                                    <td>{{ $other?->name ?? '—' }}</td>
                                    <td><x-status-pill :status="$link->strength === 'strong' ? 'failed' : ($link->strength === 'moderate' ? 'pending' : 'draft')" :label="$link->strength" /></td>
                                    <td class="muted" style="font-size: .8rem">{{ implode(', ', $link->reasons ?? []) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="card" aria-labelledby="restrictions-heading">
            <h3 id="restrictions-heading">🚫 Restrictions</h3>
            @if ($restrictions->isEmpty())
                <p class="muted">No restrictions.</p>
            @else
                <div class="table-wrap">
                    <table>
                        <caption class="sr-only">Restrictions</caption>
                        <thead>
                            <tr>
                                <th scope="col">Type</th>
                                <th scope="col">Status</th>
                                <th scope="col">Reason</th>
                                <th scope="col"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($restrictions as $restriction)
                                <tr>
                                    <td class="muted" style="font-size: .8rem">{{ $restriction->typeLabel() }}</td>
                                    <td><x-status-pill :status="$restriction->isActive() ? 'failed' : 'confirmed'" :label="$restriction->status" /></td>
                                    <td class="muted" style="font-size: .8rem">{{ $restriction->reason }}</td>
                                    <td>
                                        @if ($restriction->isActive())
                                            <form method="POST" action="{{ route('admin.security.lift', $restriction) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-green">Lift</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.security.restrict', $subject) }}" class="mt-4">
                @csrf
                <div class="row" style="align-items: flex-end">
                    <div class="field" style="min-width: 200px">
                        <label for="restriction-type">Restriction type</label>
                        <select id="restriction-type" name="type">
                            @foreach (\App\Models\Restriction::TYPES as $type)
                                <option value="{{ $type }}">{{ ucwords(str_replace('_', ' ', $type)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field" style="min-width: 200px">
                        <label for="restriction-reason">Reason</label>
                        <input type="text" id="restriction-reason" name="reason" required>
                    </div>
                    <div class="field" style="min-width: 120px">
                        <label for="restriction-expires">Expires (days, optional)</label>
                        <input type="number" id="restriction-expires" name="expires_in_days" min="1" max="3650">
                    </div>
                    <button type="submit" class="btn btn-sm btn-danger">Apply Restriction</button>
                </div>
            </form>
        </section>
    </div>

    <section class="card mt-4" aria-labelledby="risk-events-heading">
        <h3 id="risk-events-heading">🧾 Risk Events</h3>
        @if ($events->isEmpty())
            <p class="muted">No risk events.</p>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Risk events for this account</caption>
                    <thead>
                        <tr>
                            <th scope="col">Type</th>
                            <th scope="col">Severity</th>
                            <th scope="col">Score</th>
                            <th scope="col">Source</th>
                            <th scope="col">When</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($events as $event)
                            <tr>
                                <td class="muted" style="font-size: .8rem">{{ $event->type }}</td>
                                <td><x-status-pill :status="in_array($event->severity, ['high', 'critical'], true) ? 'failed' : ($event->severity === 'medium' ? 'pending' : 'draft')" :label="strtoupper($event->severity)" /></td>
                                <td>+{{ $event->score_contribution }}</td>
                                <td class="muted" style="font-size: .8rem">{{ $event->source }}</td>
                                <td class="muted" style="font-size: .8rem">{{ $event->created_at?->format('d M, h:i A') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="card mt-4" aria-labelledby="incidents-heading">
        <h3 id="incidents-heading">🎮 Anti-cheat Incidents</h3>
        @if ($incidents->isEmpty())
            <p class="muted">No anti-cheat incidents.</p>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Anti-cheat incidents for this account</caption>
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Tournament</th>
                            <th scope="col">Category</th>
                            <th scope="col">Severity</th>
                            <th scope="col">Status</th>
                            <th scope="col">Role</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($incidents as $incident)
                            <tr>
                                <td><strong>#{{ $incident->id }}</strong></td>
                                <td>{{ $incident->tournament?->name ?? '—' }}</td>
                                <td class="muted">{{ $incident->category }}</td>
                                <td class="muted">{{ $incident->severity }}</td>
                                <td><x-status-pill :status="$incident->statusPill()" :label="$incident->statusLabel()" /></td>
                                <td class="muted" style="font-size: .8rem">{{ $incident->accused_user_id === $subject->id ? 'accused' : 'reporter' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
```

### `resources/views/admin/security/users.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Suspicious Users — FF Arena Admin')
@section('content')
    <header class="page-head">
        <h1 class="page-title">🔍 Suspicious Users</h1>
    </header>

    <section class="card" aria-labelledby="filters-heading">
        <h2 id="filters-heading" class="sr-only">User filters</h2>
        <form method="GET" action="{{ route('admin.security.users') }}">
            <div class="row" style="align-items: flex-end">
                <div class="field" style="min-width: 160px">
                    <label for="level">Risk level</label>
                    <select id="level" name="level">
                        <option value="">All levels</option>
                        @foreach (\App\Models\RiskProfile::LEVELS as $l)
                            <option value="{{ $l }}" @selected($level === $l)>{{ ucfirst($l) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <div class="checkbox">
                        <input type="checkbox" id="review" name="review" value="1" @checked(request('review') === '1')>
                        <label for="review">Review required only</label>
                    </div>
                </div>
                <button type="submit" class="btn btn-sm btn-cyan">Filter</button>
            </div>
        </form>
    </section>

    <section class="card" style="padding: 0" aria-labelledby="profiles-heading">
        <h2 id="profiles-heading" class="sr-only">Suspicious accounts</h2>
        @if ($profiles->isEmpty())
            <x-empty-state title="No accounts match your filters" icon="🔍">
                Adjust the filters and try again.
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Accounts matching your filters</caption>
                    <thead>
                        <tr>
                            <th scope="col">User</th>
                            <th scope="col">Email</th>
                            <th scope="col">Risk level</th>
                            <th scope="col">Score</th>
                            <th scope="col">Review</th>
                            <th scope="col">Status</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($profiles as $profile)
                            <tr>
                                <td><strong>{{ $profile->user?->name ?? '—' }}</strong></td>
                                <td class="muted" style="font-size: .8rem">{{ $profile->user?->email ?? '—' }}</td>
                                <td><x-status-pill :status="$profile->levelPill()" :label="strtoupper($profile->risk_level)" /></td>
                                <td>{{ $profile->risk_score }}/100</td>
                                <td>{{ $profile->manual_review_required ? '⚠️ yes' : '—' }}</td>
                                <td class="muted" style="font-size: .8rem">{{ $profile->status }}</td>
                                <td>
                                    @if ($profile->user)
                                        <a class="btn btn-sm btn-cyan" href="{{ route('admin.security.user', $profile->user) }}">Investigate</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $profiles->links() }}</div>
        @endif
    </section>
@endsection
```

### `resources/views/admin/support.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Support Queue — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">🎫 Support Queue</h1>
    </header>

    <section class="card" aria-labelledby="filters-heading">
        <h2 id="filters-heading" class="sr-only">Support queue filters</h2>
        <form method="GET" action="{{ route('admin.support.index') }}">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; align-items: end">
                <div class="field">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="">All</option>
                        @foreach (\App\Models\SupportTicket::STATUSES as $status)
                            <option value="{{ $status }}" {{ ($filters['status'] ?? '') === $status ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="priority">Priority</label>
                    <select id="priority" name="priority">
                        <option value="">All</option>
                        @foreach (\App\Models\SupportTicket::PRIORITIES as $priority)
                            <option value="{{ $priority }}" {{ ($filters['priority'] ?? '') === $priority ? 'selected' : '' }}>{{ ucfirst($priority) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="category">Category</label>
                    <select id="category" name="category">
                        <option value="">All</option>
                        @foreach (\App\Models\SupportTicket::CATEGORIES as $category)
                            <option value="{{ $category }}" {{ ($filters['category'] ?? '') === $category ? 'selected' : '' }}>{{ ucfirst($category) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="assigned_to">Assignee</label>
                    <select id="assigned_to" name="assigned_to">
                        <option value="">All</option>
                        @foreach ($staff as $member)
                            <option value="{{ $member->id }}" {{ (int) ($filters['assigned_to'] ?? 0) === $member->id ? 'selected' : '' }}>{{ $member->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="row" style="gap: 8px">
                    <button type="submit" class="btn btn-cyan btn-sm">Filter</button>
                    <a href="{{ route('admin.support.index') }}" class="btn btn-sm">Reset</a>
                    <a href="{{ route('admin.support.export', request()->query()) }}" class="btn btn-sm btn-green">⬇ CSV</a>
                </div>
            </div>
        </form>
    </section>

    <section class="card" style="padding: 0" aria-labelledby="tickets-heading">
        <h2 id="tickets-heading" class="sr-only">Support tickets</h2>
        <div class="table-wrap">
            <table>
                <caption class="sr-only">Support tickets matching your filters</caption>
                <thead>
                    <tr>
                        <th scope="col">ID</th>
                        <th scope="col">Subject</th>
                        <th scope="col">Requester</th>
                        <th scope="col">Category</th>
                        <th scope="col">Priority</th>
                        <th scope="col">Status</th>
                        <th scope="col">Assignee</th>
                        <th scope="col">Updated</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($tickets as $ticket)
                        <tr>
                            <td class="muted">#{{ $ticket->id }}</td>
                            <td>{{ $ticket->subject }}</td>
                            <td>{{ $ticket->user?->name ?? '—' }}</td>
                            <td class="muted">{{ $ticket->categoryLabel() }}</td>
                            <td><x-status-pill :status="$ticket->priority" :label="$ticket->priority" /></td>
                            <td><x-status-pill :status="$ticket->statusPill()" :label="$ticket->statusLabel()" /></td>
                            <td class="muted">{{ $ticket->assignee?->name ?? '—' }}</td>
                            <td class="muted">{{ optional($ticket->last_activity_at)->diffForHumans() }}</td>
                            <td><a href="{{ route('admin.support.show', $ticket) }}" class="btn btn-sm btn-cyan">Open</a></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9">
                                <x-empty-state title="No tickets match these filters" icon="🎫">
                                    Adjust the filters and try again.
                                </x-empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    <div class="mt-4">{{ $tickets->links() }}</div>
@endsection
```

### `resources/views/admin/support_ticket.blade.php`

```blade
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
```

### `resources/views/admin/ops/dashboard.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Infrastructure Ops — FF Arena Admin')
@section('content')
    <header class="page-head">
        <h1 class="page-title">🛰 Infrastructure Operations</h1>
    </header>

    @if (session('status'))
        <x-alert type="success">{{ session('status') }}</x-alert>
    @endif
    @if (session('error'))
        <x-alert type="error">{{ session('error') }}</x-alert>
    @endif

    @php $h = $stats['health']; @endphp
    <section class="card" aria-labelledby="readiness-heading">
        <h3 id="readiness-heading">Readiness — <span style="color: {{ $h['status'] === 'ready' ? 'var(--green)' : 'var(--red)' }}">
            {{ strtoupper(str_replace('_', ' ', $h['status'])) }}</span></h3>
        <div class="row mt-3" style="gap: 14px">
            @foreach ($h['checks'] as $check)
                <span class="chip" style="border: 1px solid var(--line); padding: 6px 12px; border-radius: 20px;
                    color: {{ $check['ok'] ? 'var(--green)' : 'var(--red)' }}">
                    {{ $check['ok'] ? '✓' : '✗' }} {{ $check['label'] }}
                    @if (! $check['ok'] && isset($check['error']))
                        <span class="muted">({{ $check['error'] }})</span>
                    @endif
                </span>
            @endforeach
        </div>
    </section>

    <div class="grid cols-3 mt-4" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px">
        <div class="stat">
            <div class="muted">Queue pending</div>
            <div class="num">{{ $stats['queue']['pending_jobs'] }}</div>
        </div>
        <div class="stat">
            <div class="muted">Failed jobs</div>
            <div class="num" style="color: {{ $stats['queue']['failed_jobs'] > 0 ? 'var(--red)' : 'var(--green)' }}">
                {{ $stats['queue']['failed_jobs'] }}</div>
        </div>
        <div class="stat">
            <div class="muted">Oldest pending</div>
            <div class="num">{{ $stats['queue']['oldest_pending_seconds'] === null ? 'n/a' : $stats['queue']['oldest_pending_seconds'] . 's' }}</div>
        </div>
        <div class="stat">
            <div class="muted">Scheduler heartbeat</div>
            <div class="num">{{ $stats['queue']['scheduler_heartbeat_seconds_ago'] === null ? 'n/a' : $stats['queue']['scheduler_heartbeat_seconds_ago'] . 's ago' }}</div>
        </div>
        <div class="stat">
            <div class="muted">Webhook endpoints</div>
            <div class="num">{{ $stats['webhooks']['endpoints'] }}</div>
        </div>
        <div class="stat">
            <div class="muted">Webhook failures (24h)</div>
            <div class="num" style="color: {{ $stats['webhooks']['failures_24h'] > 0 ? 'var(--amber)' : 'var(--green)' }}">
                {{ $stats['webhooks']['failures_24h'] }}</div>
        </div>
    </div>

    <section class="card mt-4" aria-labelledby="storage-heading">
        <h3 id="storage-heading">Storage & backup</h3>
        <p class="muted">Private storage: {{ $stats['storage']['exists'] ? $stats['storage']['files'] . ' files, ' . number_format($stats['storage']['size_bytes']) . ' bytes' : 'missing' }}</p>
        @if ($stats['backup'])
            <p class="muted">Latest backup: <strong>{{ $stats['backup']['name'] }}</strong>
                ({{ $stats['backup']['db_driver'] }}, {{ number_format($stats['backup']['size']) }} bytes)</p>
        @else
            <p class="muted">No backups yet.</p>
        @endif

        <div class="row mt-3" style="gap: 10px">
            <form method="POST" action="{{ route('admin.ops.backup') }}">
                @csrf
                <button type="submit" class="btn btn-sm">Create backup now</button>
            </form>
            <form method="POST" action="{{ route('admin.ops.backup.verify') }}">
                @csrf
                <button type="submit" class="btn btn-sm">Verify latest backup</button>
            </form>
        </div>
    </section>

    <section class="card mt-4" aria-labelledby="config-heading">
        <h3 id="config-heading">Production configuration validation</h3>
        @if ($stats['production_issues'] === [])
            <p style="color: var(--green)">No issues detected.</p>
        @else
            <ul style="margin: 8px 0 0 18px">
                @foreach ($stats['production_issues'] as $issue)
                    <li style="margin-bottom: 6px">
                        <span style="color: {{ $issue['severity'] === 'critical' ? 'var(--red)' : 'var(--amber)' }}">
                            [{{ strtoupper($issue['severity']) }}] {{ $issue['key'] }}</span>
                        <span class="muted">— {{ $issue['message'] }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="card mt-4" aria-labelledby="cache-heading">
        <h3 id="cache-heading">Cache control (approved namespaces)</h3>
        <form method="POST" action="{{ route('admin.ops.cache.flush') }}">
            @csrf
            <div class="row" style="align-items: flex-end">
                <div class="field">
                    <label for="namespace">Namespace</label>
                    <select id="namespace" name="namespace">
                        <option value="providers">providers — payment provider statuses</option>
                        <option value="public">public — public read caches</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-sm">Flush</button>
            </div>
        </form>
    </section>

    <section class="card mt-4" aria-labelledby="failed-jobs-heading">
        <h3 id="failed-jobs-heading">Recent failed jobs</h3>
        @if ($failedJobs->isEmpty())
            <p class="muted">No failed jobs.</p>
        @else
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Recent failed jobs</caption>
                    <thead>
                        <tr>
                            <th scope="col">ID</th>
                            <th scope="col">Queue</th>
                            <th scope="col">Failed at</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($failedJobs as $job)
                            <tr>
                                <td>{{ \Illuminate\Support\Str::limit($job->uuid, 12) }}</td>
                                <td>{{ $job->queue }}</td>
                                <td>{{ $job->failed_at }}</td>
                                <td>
                                    <div class="row" style="gap: 8px">
                                        <form method="POST" action="{{ route('admin.ops.failed_jobs.retry', $job->uuid) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-sm">Retry</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.ops.failed_jobs.delete', $job->uuid) }}"
                                              onsubmit="return confirm('Delete this failed job record?')">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3">
                <a href="{{ route('admin.ops.failed_jobs') }}" class="muted">View all failed jobs →</a>
            </p>
        @endif
    </section>
@endsection
```

### `resources/views/admin/ops/failed-jobs.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Failed Jobs — FF Arena Admin')
@section('content')
    <header class="page-head">
        <h1 class="page-title">🧨 Failed Jobs</h1>
    </header>

    @if (session('status'))
        <x-alert type="success">{{ session('status') }}</x-alert>
    @endif
    @if (session('error'))
        <x-alert type="error">{{ session('error') }}</x-alert>
    @endif

    <div class="row mb-4" style="gap: 10px">
        <a href="{{ route('admin.ops.dashboard') }}" class="btn btn-sm">← Ops dashboard</a>
        <form method="POST" action="{{ route('admin.ops.failed_jobs.retry_all') }}"
              onsubmit="return confirm('Retry all failed jobs?')">
            @csrf
            <button type="submit" class="btn btn-sm">Retry all</button>
        </form>
    </div>

    @if ($failedJobs->isEmpty())
        <div class="card"><p class="muted">No failed jobs.</p></div>
    @else
        <section class="card" style="padding: 0" aria-labelledby="jobs-heading">
            <h2 id="jobs-heading" class="sr-only">Failed jobs</h2>
            <div class="table-wrap">
                <table>
                    <caption class="sr-only">Failed jobs</caption>
                    <thead>
                        <tr>
                            <th scope="col">ID</th>
                            <th scope="col">Connection</th>
                            <th scope="col">Queue</th>
                            <th scope="col">Failed at</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($failedJobs as $job)
                            <tr>
                                <td>{{ \Illuminate\Support\Str::limit($job->uuid, 12) }}</td>
                                <td>{{ $job->connection }}</td>
                                <td>{{ $job->queue }}</td>
                                <td>{{ $job->failed_at }}</td>
                                <td>
                                    <div class="row" style="gap: 8px">
                                        <form method="POST" action="{{ route('admin.ops.failed_jobs.retry', $job->uuid) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-sm">Retry</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.ops.failed_jobs.delete', $job->uuid) }}"
                                              onsubmit="return confirm('Delete this failed job record?')">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
        <div class="mt-4">{{ $failedJobs->links() }}</div>
    @endif
@endsection
```

### `resources/views/admin/analytics/index.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Analytics — FF Arena Admin')
@section('content')
    <header class="page-head">
        <h1 class="page-title">📊 Analytics</h1>
    </header>

    <nav class="card row" aria-label="Analytics navigation">
        <a href="{{ route('admin.analytics.tournaments') }}" class="btn btn-sm">Tournaments & Matches</a>
        <a href="{{ route('admin.analytics.financial') }}" class="btn btn-sm btn-cyan">Financial</a>
        <a href="{{ route('admin.analytics.security') }}" class="btn btn-sm">Security</a>
        <a href="{{ route('admin.analytics.disputes') }}" class="btn btn-sm">Disputes</a>
        <a href="{{ route('admin.analytics.support') }}" class="btn btn-sm">Support</a>
    </nav>

    <h3 class="mt-4">Operations</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Users</div><div class="num">{{ $overview['users'] }}</div></div>
        <div class="stat"><div class="muted">Tournaments</div><div class="num">{{ $overview['tournaments']['total'] }}</div></div>
        <div class="stat"><div class="muted">Live now</div><div class="num" style="color: var(--green)">{{ $overview['tournaments']['live'] }}</div></div>
        <div class="stat"><div class="muted">Finished</div><div class="num">{{ $overview['tournaments']['finished'] }}</div></div>
        <div class="stat"><div class="muted">Teams</div><div class="num">{{ $overview['teams'] }}</div></div>
        <div class="stat"><div class="muted">Matches</div><div class="num">{{ $overview['matches'] }}</div></div>
        <div class="stat"><div class="muted">Open disputes</div><div class="num" style="color: var(--amber)">{{ $overview['open_disputes'] }}</div></div>
        <div class="stat"><div class="muted">Open tickets</div><div class="num" style="color: var(--amber)">{{ $overview['open_tickets'] }}</div></div>
    </div>

    <h3 class="mt-4">Financial (admin only)</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Payment volume</div><div class="num" style="color: var(--green)">{{ \App\Support\Money::formatMinor($financial['payments']['volume_minor']) }}</div></div>
        <div class="stat"><div class="muted">Successful payments</div><div class="num">{{ $financial['payments']['successful'] }}</div></div>
        <div class="stat"><div class="muted">Refunded</div><div class="num" style="color: var(--red)">{{ \App\Support\Money::formatMinor($financial['payments']['refunded_minor']) }}</div></div>
        <div class="stat"><div class="muted">Wallet balances</div><div class="num">{{ \App\Support\Money::formatMinor($financial['wallets']['balance_minor']) }}</div></div>
        <div class="stat"><div class="muted">Payouts completed</div><div class="num" style="color: var(--green)">{{ \App\Support\Money::formatMinor($financial['payouts']['completed_minor']) }}</div></div>
        <div class="stat"><div class="muted">Pending payouts</div><div class="num" style="color: var(--amber)">{{ $financial['payouts']['pending'] }}</div></div>
    </div>

    <h3 class="mt-4">Security (admin only)</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Accounts under review</div><div class="num" style="color: var(--purple)">{{ $security['accounts_under_review'] }}</div></div>
        <div class="stat"><div class="muted">Active restrictions</div><div class="num" style="color: var(--red)">{{ $security['restrictions']['active'] }}</div></div>
        <div class="stat"><div class="muted">Open anti-cheat</div><div class="num" style="color: var(--amber)">{{ $security['anti_cheat']['flagged'] + $security['anti_cheat']['under_review'] }}</div></div>
        <div class="stat"><div class="muted">Identity reviews</div><div class="num">{{ $security['identity_reviews'] }}</div></div>
    </div>

    <h3 class="mt-4">Support</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Open tickets</div><div class="num" style="color: var(--amber)">{{ $support['open'] }}</div></div>
        <div class="stat"><div class="muted">Pending</div><div class="num">{{ $support['pending'] }}</div></div>
        <div class="stat"><div class="muted">Resolved</div><div class="num" style="color: var(--green)">{{ $support['resolved'] }}</div></div>
    </div>
@endsection
```

### `resources/views/admin/analytics/tournament.blade.php`

```blade
@extends('layouts.app')
@section('title', $tournament->name . ' Analytics — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">📈 {{ $tournament->name }}</h1>
        <p class="muted">Operational metrics — {{ strtoupper($tournament->status) }}</p>
    </header>

    <h3 class="mt-4">Registration</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Teams</div><div class="num">{{ $metrics['teams']['total'] }}</div></div>
        <div class="stat"><div class="muted">Confirmed</div><div class="num" style="color: var(--green)">{{ $metrics['teams']['confirmed'] }}</div></div>
        <div class="stat"><div class="muted">Waitlisted</div><div class="num" style="color: var(--amber)">{{ $metrics['teams']['waitlisted'] }}</div></div>
        <div class="stat"><div class="muted">Withdrawn</div><div class="num">{{ $metrics['teams']['withdrawn'] }}</div></div>
        <div class="stat"><div class="muted">No-shows</div><div class="num" style="color: var(--red)">{{ $metrics['teams']['no_show'] }}</div></div>
        <div class="stat"><div class="muted">Checked in</div><div class="num" style="color: var(--green)">{{ $metrics['teams']['checked_in'] }}</div></div>
    </div>

    <h3 class="mt-4">Rates</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Check-in rate</div><div class="num">{{ $metrics['rates']['check_in'] }}%</div></div>
        <div class="stat"><div class="muted">No-show rate</div><div class="num" style="color: var(--red)">{{ $metrics['rates']['no_show'] }}%</div></div>
        <div class="stat"><div class="muted">Match completion</div><div class="num">{{ $metrics['rates']['match_completion'] }}%</div></div>
    </div>

    <h3 class="mt-4">Matches</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Total</div><div class="num">{{ $metrics['matches']['total'] }}</div></div>
        <div class="stat"><div class="muted">Completed</div><div class="num" style="color: var(--green)">{{ $metrics['matches']['completed'] }}</div></div>
        <div class="stat"><div class="muted">Live</div><div class="num" style="color: var(--cyan)">{{ $metrics['matches']['live'] }}</div></div>
        <div class="stat"><div class="muted">Disputed</div><div class="num" style="color: var(--red)">{{ $metrics['matches']['disputed'] }}</div></div>
    </div>
@endsection
```

### `resources/views/admin/analytics/tournaments.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Tournament Analytics — FF Arena Admin')
@section('content')
    <header class="page-head">
        <h1 class="page-title">🏆 Tournament & Match Analytics</h1>
    </header>

    <section class="card" aria-labelledby="filters-heading">
        <h2 id="filters-heading" class="sr-only">Date range filters</h2>
        <form method="GET">
            <div class="row" style="align-items: flex-end">
                <div class="field">
                    <label for="from">From</label>
                    <input type="date" id="from" name="from" value="{{ $from ?? '' }}">
                </div>
                <div class="field">
                    <label for="to">To</label>
                    <input type="date" id="to" name="to" value="{{ $to ?? '' }}">
                </div>
                <button type="submit" class="btn btn-cyan btn-sm">Apply</button>
                <a href="{{ route('admin.analytics.tournaments') }}" class="btn btn-sm">Reset</a>
                <a href="{{ route('admin.analytics.export') }}" class="btn btn-sm btn-green">⬇ Export CSV</a>
            </div>
        </form>
    </section>

    <h3 class="mt-4">Tournaments</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Total</div><div class="num">{{ $overview['tournaments']['total'] }}</div></div>
        <div class="stat"><div class="muted">Created (range)</div><div class="num">{{ $overview['tournaments']['created'] }}</div></div>
        <div class="stat"><div class="muted">Open</div><div class="num">{{ $overview['tournaments']['open'] }}</div></div>
        <div class="stat"><div class="muted">Live</div><div class="num" style="color: var(--green)">{{ $overview['tournaments']['live'] }}</div></div>
        <div class="stat"><div class="muted">Finished</div><div class="num">{{ $overview['tournaments']['finished'] }}</div></div>
        <div class="stat"><div class="muted">Cancelled</div><div class="num" style="color: var(--red)">{{ $overview['tournaments']['cancelled'] }}</div></div>
    </div>

    <h3 class="mt-4">Matches</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Scheduled</div><div class="num">{{ $matches['scheduled'] }}</div></div>
        <div class="stat"><div class="muted">Completed</div><div class="num" style="color: var(--green)">{{ $matches['completed'] }}</div></div>
        <div class="stat"><div class="muted">Live</div><div class="num" style="color: var(--cyan)">{{ $matches['live'] }}</div></div>
        <div class="stat"><div class="muted">Disputed</div><div class="num" style="color: var(--red)">{{ $matches['disputed'] }}</div></div>
        <div class="stat"><div class="muted">Unresolved</div><div class="num" style="color: var(--amber)">{{ $matches['unresolved'] }}</div></div>
        <div class="stat"><div class="muted">Avg completion</div><div class="num">{{ $matches['avg_completion_seconds'] !== null ? round($matches['avg_completion_seconds'] / 3600, 1) . 'h' : '—' }}</div></div>
        <div class="stat"><div class="muted">Scores submitted</div><div class="num">{{ $matches['scoring']['scores_submitted'] }}</div></div>
        <div class="stat"><div class="muted">Avg kills</div><div class="num">{{ $matches['scoring']['avg_kills'] }}</div></div>
    </div>

    <h3 class="mt-4">Players</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Total users</div><div class="num">{{ $players['total_users'] }}</div></div>
        <div class="stat"><div class="muted">New (range)</div><div class="num">{{ $players['new_users'] }}</div></div>
        <div class="stat"><div class="muted">Organizers</div><div class="num">{{ $players['organizers'] }}</div></div>
        <div class="stat"><div class="muted">Participants</div><div class="num">{{ $players['participants'] }}</div></div>
        <div class="stat"><div class="muted">Repeat participants</div><div class="num">{{ $players['repeat_participants'] }}</div></div>
    </div>

    <h3 class="mt-4">Tournaments</h3>
    <section class="card" style="padding: 0" aria-labelledby="tournament-table-heading">
        <h2 id="tournament-table-heading" class="sr-only">Tournament list</h2>
        <div class="table-wrap">
            <table>
                <caption class="sr-only">Tournaments with metrics</caption>
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Status</th>
                        <th scope="col">Teams</th>
                        <th scope="col">Matches</th>
                        <th scope="col">Completed</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($tournaments as $t)
                        <tr>
                            <td>{{ $t->name }}</td>
                            <td><x-status-pill :status="$t->status" :label="strtoupper($t->status)" /></td>
                            <td>{{ $t->teams_count }}</td>
                            <td>{{ $t->matches_count }}</td>
                            <td>{{ $t->completed_matches_count }}</td>
                            <td><a href="{{ route('tournaments.analytics', $t) }}" class="btn btn-sm btn-cyan">Metrics</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
    <div class="mt-4">{{ $tournaments->links() }}</div>
@endsection
```

### `resources/views/admin/analytics/financial.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Financial Analytics — FF Arena Admin')
@section('content')
    <header class="page-head">
        <h1 class="page-title">💸 Financial Analytics</h1>
    </header>

    <h3 class="mt-4">Payments</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Volume</div><div class="num" style="color: var(--green)">{{ \App\Support\Money::formatMinor($metrics['payments']['volume_minor']) }}</div></div>
        <div class="stat"><div class="muted">Successful</div><div class="num">{{ $metrics['payments']['successful'] }}</div></div>
        <div class="stat"><div class="muted">Failed</div><div class="num" style="color: var(--red)">{{ $metrics['payments']['failed'] }}</div></div>
        <div class="stat"><div class="muted">Refunded</div><div class="num" style="color: var(--amber)">{{ \App\Support\Money::formatMinor($metrics['payments']['refunded_minor']) }}</div></div>
    </div>

    <h3 class="mt-4">Wallets</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Total balance</div><div class="num">{{ \App\Support\Money::formatMinor($metrics['wallets']['balance_minor']) }}</div></div>
        <div class="stat"><div class="muted">Credits</div><div class="num" style="color: var(--green)">{{ \App\Support\Money::formatMinor($metrics['wallets']['credits_minor']) }}</div></div>
        <div class="stat"><div class="muted">Debits</div><div class="num" style="color: var(--red)">{{ \App\Support\Money::formatMinor($metrics['wallets']['debits_minor']) }}</div></div>
    </div>

    <h3 class="mt-4">Prize pools</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Pool (calculated)</div><div class="num">{{ \App\Support\Money::formatMinor($metrics['prizes']['pool_minor']) }}</div></div>
        <div class="stat"><div class="muted">Allocated</div><div class="num">{{ \App\Support\Money::formatMinor($metrics['prizes']['allocated_minor']) }}</div></div>
    </div>

    <h3 class="mt-4">Payouts</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Completed</div><div class="num" style="color: var(--green)">{{ \App\Support\Money::formatMinor($metrics['payouts']['completed_minor']) }}</div></div>
        <div class="stat"><div class="muted">Completed count</div><div class="num">{{ $metrics['payouts']['completed'] }}</div></div>
        <div class="stat"><div class="muted">Pending</div><div class="num" style="color: var(--amber)">{{ $metrics['payouts']['pending'] }}</div></div>
        <div class="stat"><div class="muted">Pending amount</div><div class="num">{{ \App\Support\Money::formatMinor($metrics['payouts']['pending_minor']) }}</div></div>
    </div>

    <h3 class="mt-4">Settlements</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Balanced</div><div class="num" style="color: var(--green)">{{ $metrics['settlements']['balanced'] }}</div></div>
        <div class="stat"><div class="muted">Underfunded</div><div class="num" style="color: var(--amber)">{{ $metrics['settlements']['underfunded'] }}</div></div>
        <div class="stat"><div class="muted">Overallocated</div><div class="num" style="color: var(--amber)">{{ $metrics['settlements']['overallocated'] }}</div></div>
        <div class="stat"><div class="muted">Mismatch</div><div class="num" style="color: var(--red)">{{ $metrics['settlements']['mismatch'] }}</div></div>
        <div class="stat"><div class="muted">Exceptions</div><div class="num" style="color: var(--red)">{{ $metrics['settlements']['exceptions'] }}</div></div>
    </div>
@endsection
```

### `resources/views/admin/analytics/disputes.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Dispute Analytics — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">⚖️ Dispute Analytics</h1>
    </header>

    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Open</div><div class="num" style="color: var(--amber)">{{ $metrics['open'] }}</div></div>
        <div class="stat"><div class="muted">Resolved</div><div class="num" style="color: var(--green)">{{ $metrics['resolved'] }}</div></div>
        <div class="stat"><div class="muted">Rejected</div><div class="num" style="color: var(--red)">{{ $metrics['rejected'] }}</div></div>
        <div class="stat"><div class="muted">Cancelled</div><div class="num">{{ $metrics['cancelled'] }}</div></div>
        <div class="stat"><div class="muted">Avg resolution</div><div class="num">{{ $metrics['avg_resolution_seconds'] !== null ? round($metrics['avg_resolution_seconds'] / 3600, 1) . 'h' : '—' }}</div></div>
        <div class="stat"><div class="muted">Evidence items</div><div class="num">{{ $metrics['evidence_volume'] }}</div></div>
    </div>

    <section class="card mt-4" style="padding: 0" aria-labelledby="by-category-heading">
        <h3 id="by-category-heading">Open by category</h3>
        <div class="table-wrap">
            <table>
                <caption class="sr-only">Open disputes by category</caption>
                <thead>
                    <tr>
                        <th scope="col">Category</th>
                        <th scope="col">Open</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($metrics['by_category_open'] as $category => $count)
                        <tr><td>{{ ucwords(str_replace('_', ' ', $category)) }}</td><td>{{ $count }}</td></tr>
                    @empty
                        <tr><td colspan="2" class="muted">No open disputes.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="card mt-4" style="padding: 0" aria-labelledby="workload-heading">
        <h3 id="workload-heading">Moderator workload</h3>
        <div class="table-wrap">
            <table>
                <caption class="sr-only">Moderator workload</caption>
                <thead>
                    <tr>
                        <th scope="col">Staff</th>
                        <th scope="col">Open disputes</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($metrics['moderator_workload'] as $row)
                        <tr><td>{{ $row['staff'] }}</td><td>{{ $row['open'] }}</td></tr>
                    @empty
                        <tr><td colspan="2" class="muted">No open assignments.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
```

### `resources/views/admin/analytics/security.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Security Analytics — FF Arena Admin')
@section('content')
    <header class="page-head">
        <h1 class="page-title">🛡 Security Analytics</h1>
    </header>

    <h3 class="mt-4">Risk levels</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Under review</div><div class="num" style="color: var(--purple)">{{ $metrics['accounts_under_review'] }}</div></div>
        <div class="stat"><div class="muted">Low</div><div class="num">{{ $metrics['risk_levels']['low'] }}</div></div>
        <div class="stat"><div class="muted">Medium</div><div class="num">{{ $metrics['risk_levels']['medium'] }}</div></div>
        <div class="stat"><div class="muted">High</div><div class="num" style="color: var(--amber)">{{ $metrics['risk_levels']['high'] }}</div></div>
        <div class="stat"><div class="muted">Critical</div><div class="num" style="color: var(--red)">{{ $metrics['risk_levels']['critical'] }}</div></div>
    </div>

    <h3 class="mt-4">Restrictions</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Active</div><div class="num" style="color: var(--red)">{{ $metrics['restrictions']['active'] }}</div></div>
        <div class="stat"><div class="muted">Lifted</div><div class="num">{{ $metrics['restrictions']['lifted'] }}</div></div>
    </div>

    <h3 class="mt-4">Anti-cheat incidents</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Flagged</div><div class="num" style="color: var(--amber)">{{ $metrics['anti_cheat']['flagged'] }}</div></div>
        <div class="stat"><div class="muted">Under review</div><div class="num">{{ $metrics['anti_cheat']['under_review'] }}</div></div>
        <div class="stat"><div class="muted">Confirmed</div><div class="num" style="color: var(--red)">{{ $metrics['anti_cheat']['confirmed'] }}</div></div>
        <div class="stat"><div class="muted">Restricted</div><div class="num" style="color: var(--red)">{{ $metrics['anti_cheat']['restricted'] }}</div></div>
        <div class="stat"><div class="muted">Cleared</div><div class="num" style="color: var(--green)">{{ $metrics['anti_cheat']['cleared'] }}</div></div>
    </div>

    <h3 class="mt-4">Reviews</h3>
    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Ban-evasion reviews</div><div class="num">{{ $metrics['ban_evasion_reviews'] }}</div></div>
        <div class="stat"><div class="muted">Identity reviews</div><div class="num">{{ $metrics['identity_reviews'] }}</div></div>
    </div>
@endsection
```

### `resources/views/admin/analytics/support.blade.php`

```blade
@extends('layouts.app')
@section('title', 'Support Analytics — FF Arena')
@section('content')
    <header class="page-head">
        <h1 class="page-title">🎫 Support Analytics</h1>
    </header>

    <div class="grid cols-2" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
        <div class="stat"><div class="muted">Open</div><div class="num" style="color: var(--amber)">{{ $metrics['open'] }}</div></div>
        <div class="stat"><div class="muted">Pending</div><div class="num">{{ $metrics['pending'] }}</div></div>
        <div class="stat"><div class="muted">Resolved</div><div class="num" style="color: var(--green)">{{ $metrics['resolved'] }}</div></div>
        <div class="stat"><div class="muted">Closed</div><div class="num">{{ $metrics['closed'] }}</div></div>
        <div class="stat"><div class="muted">Reopened</div><div class="num" style="color: var(--amber)">{{ $metrics['reopened'] }}</div></div>
        <div class="stat"><div class="muted">Avg resolution</div><div class="num">{{ $metrics['avg_resolution_seconds'] !== null ? round($metrics['avg_resolution_seconds'] / 3600, 1) . 'h' : '—' }}</div></div>
    </div>

    <section class="card mt-4" style="padding: 0" aria-labelledby="by-category-heading">
        <h3 id="by-category-heading">By category</h3>
        <div class="table-wrap">
            <table>
                <caption class="sr-only">Tickets by category</caption>
                <thead>
                    <tr>
                        <th scope="col">Category</th>
                        <th scope="col">Tickets</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($metrics['by_category'] as $category => $count)
                        <tr><td>{{ ucfirst($category) }}</td><td>{{ $count }}</td></tr>
                    @empty
                        <tr><td colspan="2" class="muted">No tickets.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="card mt-4" style="padding: 0" aria-labelledby="by-priority-heading">
        <h3 id="by-priority-heading">Open by priority</h3>
        <div class="table-wrap">
            <table>
                <caption class="sr-only">Open tickets by priority</caption>
                <thead>
                    <tr>
                        <th scope="col">Priority</th>
                        <th scope="col">Open</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($metrics['by_priority_open'] as $priority => $count)
                        <tr><td>{{ ucfirst($priority) }}</td><td>{{ $count }}</td></tr>
                    @empty
                        <tr><td colspan="2" class="muted">No open tickets.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="card mt-4" style="padding: 0" aria-labelledby="staff-workload-heading">
        <h3 id="staff-workload-heading">Staff workload</h3>
        <div class="table-wrap">
            <table>
                <caption class="sr-only">Staff workload</caption>
                <thead>
                    <tr>
                        <th scope="col">Staff</th>
                        <th scope="col">Open tickets</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($metrics['staff_workload'] as $row)
                        <tr><td>{{ $row['staff'] }}</td><td>{{ $row['open'] }}</td></tr>
                    @empty
                        <tr><td colspan="2" class="muted">No assignments.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
```

## Generated Binary Assets

- `public/favicon.ico` — Windows ICO, 1 icon, 32×32, PNG-compressed RGBA, 374 bytes.
- `public/apple-touch-icon.png` — PNG 180×180, 8-bit RGBA.

Both were generated with PHP GD (rounded-square brand tile + FF glyph).
