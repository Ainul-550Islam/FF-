#!/usr/bin/env python3
"""Generate PHASE17_UI_UX_ACCESSIBILITY_SEO_PERFORMANCE_REPORT.md"""
import os

ROOT = "/home/user/ffarena-app"
OUT = os.path.join(ROOT, "PHASE17_UI_UX_ACCESSIBILITY_SEO_PERFORMANCE_REPORT.md")

NEW_TEXT_FILES = [
    "app/Support/Seo.php",
    "app/Http/Controllers/SitemapController.php",
    "resources/views/seo/sitemap.blade.php",
    "resources/views/components/alert.blade.php",
    "resources/views/components/status-pill.blade.php",
    "resources/views/components/empty-state.blade.php",
    "resources/views/components/button.blade.php",
    "resources/views/vendor/pagination/tailwind.blade.php",
    "resources/views/vendor/pagination/simple-tailwind.blade.php",
    "public/css/app.css",
    "public/js/app.js",
    "public/favicon.svg",
    "lang/en/ui.php",
    "tests/Feature/Phase17/Phase17TestCase.php",
    "tests/Feature/Phase17/SeoTest.php",
    "tests/Feature/Phase17/SitemapRobotsTest.php",
    "tests/Feature/Phase17/AccessibilityTest.php",
    "tests/Feature/Phase17/ResponsiveTest.php",
    "tests/Feature/Phase17/PerformanceTest.php",
    "tests/Feature/Phase17/DiscoveryTest.php",
    "tests/Feature/Phase17/UiComponentsTest.php",
    "tests/Feature/Phase17/SmokeMatrixTest.php",
    "package-lock.json",
]

NEW_BINARY_FILES = [
    "public/favicon.ico (Windows ICO, 32x32, PNG-compressed, 374 bytes)",
    "public/apple-touch-icon.png (PNG 180x180 RGBA, generated via PHP GD)",
]

MODIFIED_FILES = [
    "resources/views/layouts/app.blade.php",
    "resources/views/home.blade.php",
    "resources/views/tournaments/index.blade.php",
    "resources/views/tournaments/show.blade.php",
    "resources/views/leaderboard/show.blade.php",
    "resources/views/profile/show.blade.php",
    "resources/views/auth/login.blade.php",
    "resources/views/auth/register.blade.php",
    "resources/views/notifications/index.blade.php",
    "resources/views/live/poll.blade.php",
    "resources/views/matches/_bracket_card.blade.php",
    "resources/css/app.css",
    "resources/js/bootstrap.js",
    "app/Http/Controllers/HomeController.php",
    "app/Http/Controllers/TournamentController.php",
    "app/Http/Controllers/LeaderboardController.php",
    "app/Http/Controllers/ProfileController.php",
    "app/Providers/AppServiceProvider.php",
    "routes/web.php",
    "config/app.php",
    ".env.example",
    "scripts/ci/check-pint.sh",
    # Second pass — long-tail views hand-rewritten against the design system
    "resources/views/auth/forgot-password.blade.php",
    "resources/views/auth/phone-login.blade.php",
    "resources/views/auth/phone-verify.blade.php",
    "resources/views/auth/reset-password.blade.php",
    "resources/views/auth/verify-email.blade.php",
    "resources/views/profile/edit.blade.php",
    "resources/views/settings/security.blade.php",
    "resources/views/settings/sessions.blade.php",
    "resources/views/settings/login-history.blade.php",
    "resources/views/settings/connected-accounts.blade.php",
    "resources/views/settings/payment-methods.blade.php",
    "resources/views/wallet/index.blade.php",
    "resources/views/payment/methods.blade.php",
    "resources/views/payment/pending.blade.php",
    "resources/views/payment/show.blade.php",
    "resources/views/teams/register.blade.php",
    "resources/views/teams/show.blade.php",
    "resources/views/tournaments/create.blade.php",
    "resources/views/tournaments/edit.blade.php",
    "resources/views/tournaments/scoring.blade.php",
    "resources/views/matches/show.blade.php",
    "resources/views/disputes/create.blade.php",
    "resources/views/disputes/show.blade.php",
    "resources/views/support/create.blade.php",
    "resources/views/support/index.blade.php",
    "resources/views/support/show.blade.php",
    "resources/views/moderation/index.blade.php",
    "resources/views/moderation/security.blade.php",
    "resources/views/admin/dashboard.blade.php",
    "resources/views/admin/audit.blade.php",
    "resources/views/admin/accounts/index.blade.php",
    "resources/views/admin/accounts/show.blade.php",
    "resources/views/admin/wallet.blade.php",
    "resources/views/admin/payments.blade.php",
    "resources/views/admin/payouts.blade.php",
    "resources/views/admin/settlements.blade.php",
    "resources/views/admin/settlement.blade.php",
    "resources/views/admin/security/dashboard.blade.php",
    "resources/views/admin/security/events.blade.php",
    "resources/views/admin/security/incidents.blade.php",
    "resources/views/admin/security/user.blade.php",
    "resources/views/admin/security/users.blade.php",
    "resources/views/admin/support.blade.php",
    "resources/views/admin/support_ticket.blade.php",
    "resources/views/admin/ops/dashboard.blade.php",
    "resources/views/admin/ops/failed-jobs.blade.php",
    "resources/views/admin/analytics/index.blade.php",
    "resources/views/admin/analytics/tournament.blade.php",
    "resources/views/admin/analytics/tournaments.blade.php",
    "resources/views/admin/analytics/financial.blade.php",
    "resources/views/admin/analytics/disputes.blade.php",
    "resources/views/admin/analytics/security.blade.php",
    "resources/views/admin/analytics/support.blade.php",
]

NARRATIVE = r"""**Project:** FF Arena (Laravel 12.69.1, PHP 8.4.24, SQLite, Tailwind CSS v4 + Vite 7)
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
"""


def render_blocks(files, heading):
    out = [f"\n## {heading}\n"]
    for rel in files:
        path = os.path.join(ROOT, rel)
        with open(path, encoding="utf-8", errors="replace") as fh:
            content = fh.read()
        ext = rel.rsplit(".", 1)[-1] if "." in rel else "txt"
        if rel.endswith(".blade.php"):
            lang = "blade"
        else:
            lang = {
                "php": "php", "json": "json", "py": "python", "md": "text",
                "sh": "bash", "yml": "yaml", "css": "css", "js": "js",
                "svg": "xml", "example": "text", "conf": "text",
            }.get(ext, "text")
        out.append(f"\n### `{rel}`\n\n```{lang}\n{content.rstrip()}\n```\n")
    return "".join(out)


def main():
    parts = ["# PHASE17_UI_UX_ACCESSIBILITY_SEO_PERFORMANCE_REPORT.md\n\n", NARRATIVE]
    parts.append(render_blocks(NEW_TEXT_FILES, "New Files (complete content)"))
    parts.append(render_blocks(MODIFIED_FILES, "Modified Files (complete final content)"))
    parts.append("\n## Generated Binary Assets\n\n- `public/favicon.ico` — Windows ICO, 1 icon, 32×32, PNG-compressed RGBA, 374 bytes.\n- `public/apple-touch-icon.png` — PNG 180×180, 8-bit RGBA.\n\nBoth were generated with PHP GD (rounded-square brand tile + FF glyph).\n")
    with open(OUT, "w", encoding="utf-8") as fh:
        fh.write("".join(parts))
    print(f"Wrote {OUT} ({sum(len(p) for p in parts)} chars)")


if __name__ == "__main__":
    main()
