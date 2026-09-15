# Mobile release notes

Each release gets a dated entry. Keep one file per release under
`docs/mobile/releases/` following `RELEASE_TEMPLATE.md` for larger releases,
or add a line here for routine bumps.

## 1.1.0 — 2026-09-11 (Phase 19)

* Added production push architecture: FCM (HTTP v1) + direct APNs transports,
  encrypted-at-rest device tokens, per-category push preferences with an
  always-on security category, and safe/redacted payloads.
* Added device management (list/remove) and notification-preferences screens.
* Extended deep links: leaderboard, support, dispute, payment, payout,
  security; Android App Links and iOS Universal Links scaffolding with web
  fallback.
* Added server-driven version gating (update available / update required /
  maintenance) with a release-gate screen.
* Added Android build flavors (dev/staging/prod), environment-driven release
  signing, cleartext disabled in release, and the Android 13+ notification
  permission.
* Added iOS entitlements (push + associated domains), background remote
  notifications, and native deep-link forwarding (Swift/Kotlin).

## 1.0.0 — 2026-09-10 (Phase 18)

* Initial mobile foundation: auth, secure token lifecycle, offline read-only
  cache, EN/BN localization, BDT formatting, tournament/team/match/wallet/
  notification/support screens, and the `ffarena://` scheme.
