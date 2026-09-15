# Mobile Device QA Checklist (Phase 19)

Run this checklist on real devices before any release. This sandbox has no
Android SDK or Xcode, so **no real device build was executed** — every step
below is documented for a developer machine.

## 0. Environment

```bash
export PATH=/opt/flutter/bin:$PATH
cd mobile
flutter doctor            # Android SDK / Xcode must be green on the dev machine
```

## 1. Builds

| Build | Command |
| --- | --- |
| Android debug | `flutter build apk --debug --flavor dev` |
| Android release | `flutter build apk --release --flavor prod --dart-define=FFARENA_API_BASE_URL=https://api.<host>/api/v1 --dart-define=FFARENA_ENV=production` |
| iOS debug | `flutter build ios --debug --flavor dev` |
| iOS release | `flutter build ios --release --flavor prod --dart-define=…` |

## 2. Functional

* [ ] Register → login → home renders live feed, teams, wallet.
* [ ] Tournament register → check-in → payment → status poll.
* [ ] Match center, bracket, leaderboard, wallet ledger, payouts.
* [ ] Notifications list, mark read / mark all.
* [ ] Support create + chat; disputes read-only.
* [ ] Profile edit, privacy presets, sessions, security screen.

## 3. Push

* [ ] First launch → permission prompt at an appropriate UX point.
* [ ] Device appears under Settings → Devices after login.
* [ ] Foreground push renders in-app (no double tray notification).
* [ ] Background push appears in tray; tap deep-links to the target.
* [ ] Notification in tray never contains a wallet balance/amount.
* [ ] Security notification arrives even with every other toggle off.
* [ ] Toggling a category off stops that category.
* [ ] Logout removes the device (others remain on multi-device).

## 4. Deep links

* [ ] `ffarena://tournament/{id}`, `match`, `profile`, `leaderboard`,
  `support`, `dispute`, `payment`, `payout`, `security` each open the right
  screen when authenticated.
* [ ] Unauthenticated tap → login → target re-opened after login.
* [ ] Malformed / unknown link → ignored, no crash.
* [ ] A link with a query string never exposes the query to navigation.

## 5. Version / maintenance

* [ ] `MOBILE_MAINTENANCE_MODE=true` → maintenance screen with message;
  logout available.
* [ ] `MOBILE_UPDATE_REQUIRED=true` → update screen with store link.
* [ ] `MOBILE_MIN_APP_VERSION` above the installed build → update-required.
* [ ] `MOBILE_LATEST_APP_VERSION` above installed build → non-blocking banner.

## 6. Network / offline

* [ ] Airplane mode → offline banner, cached content readable, no crash.
* [ ] Offline mutation attempts → safe error (never re-submitted blindly).
* [ ] 429 → rate-limit message. 500 → server error message. 401 → session
  security screen.

## 7. Security

* [ ] No bearer token in any log (`adb logcat` / Xcode console).
* [ ] Clipboard does not retain tokens.
* [ ] `--release` build has no debug banner/logging.

## 8. Branding

* [ ] App icon replaced (no Flutter default).
* [ ] Launch/splash screen shows FF Arena branding (no debug splash).

## 9. Known limitations in this sandbox

* No Android SDK, no Xcode, no Chrome/GTK: `flutter doctor` cannot produce
  device builds here. Static config, `flutter analyze` and `flutter test`
  are green; device builds must run on a developer machine.
