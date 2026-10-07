# Mobile App — Build & Release Guide (Phase 18/19)

This document covers building the release channels (dev / staging /
production), release signing, the Firebase dart-defines, and App Link /
Universal Link configuration. It does **not** claim App Store / Play Store
publication — only the build configuration and commands are provided.

## 1. Release channels

| Flavor | `FFARENA_ENV` | Application id | API base URL |
| --- | --- | --- | --- |
| `dev` | `development` | `com.ffarena.ffarena_mobile.dev` | localhost / dev host |
| `staging` | `staging` | `com.ffarena.ffarena_mobile.staging` | `https://staging.<host>/api/v1` |
| `prod` | `production` | `com.ffarena.ffarena_mobile` | `https://api.<host>/api/v1` |

`production` builds abort at startup if the API base URL is not HTTPS
(enforced in `lib/main.dart`) — a production build can never accidentally
point at a development API.

## 2. Dart-defines

```bash
FFARENA_API_BASE_URL=https://api.example.com/api/v1
FFARENA_ENV=production
FFARENA_GOOGLE_CLIENT_ID=xxx.apps.googleusercontent.com   # optional
FFARENA_PUSH_ENABLED=true                                  # master push switch
FFARENA_CRASH_REPORTING_ENABLED=true                       # optional
FFARENA_FIREBASE_API_KEY=...                               # FCM (public web ids)
FFARENA_FIREBASE_APP_ID=...
FFARENA_FIREBASE_MESSAGING_SENDER_ID=...
FFARENA_FIREBASE_PROJECT_ID=...
```

When the four `FFARENA_FIREBASE_*` ids are absent (or `FFARENA_PUSH_ENABLED`
is false), the app uses the honest no-op push provider — push is disabled,
the app keeps working.

## 3. Commands

```bash
export PATH=/opt/flutter/bin:$PATH
cd mobile
flutter pub get
```

### Dev

```bash
flutter run \
  --flavor dev \
  --dart-define=FFARENA_API_BASE_URL=http://10.0.2.2:8000/api/v1 \
  --dart-define=FFARENA_ENV=development
```

### Staging

```bash
flutter build apk --release --flavor staging \
  --dart-define=FFARENA_API_BASE_URL=https://staging.example.com/api/v1 \
  --dart-define=FFARENA_ENV=staging
```

### Production

```bash
flutter build apk --release --flavor prod \
  --dart-define=FFARENA_API_BASE_URL=https://api.example.com/api/v1 \
  --dart-define=FFARENA_ENV=production \
  --dart-define=FFARENA_FIREBASE_API_KEY=... \
  --dart-define=FFARENA_FIREBASE_APP_ID=... \
  --dart-define=FFARENA_FIREBASE_MESSAGING_SENDER_ID=... \
  --dart-define=FFARENA_FIREBASE_PROJECT_ID=...
```

```bash
flutter build ios --release --flavor prod \
  --dart-define=FFARENA_API_BASE_URL=https://api.example.com/api/v1 \
  --dart-define=FFARENA_ENV=production
```

## 4. Android release signing (fail-closed since GAP-10 D)

Signing reads credentials from the environment or Gradle properties — never
from committed files:

```bash
export FFARENA_KEYSTORE_PATH=/secure/release.keystore
export FFARENA_KEYSTORE_PASSWORD=...
export FFARENA_KEY_ALIAS=upload
export FFARENA_KEY_PASSWORD=...
export FFARENA_APP_LINK_HOST=ffarena.example.com    # a real host, not a placeholder
flutter build appbundle --release --flavor prod     # store artifact
```

### The gate

`android/app/build.gradle.kts` **refuses** a release build it cannot sign.
The previous behaviour — silently falling back to the debug key — produced an
artifact that looks shippable, is rejected by Play, and permanently breaks the
upgrade path of anyone who installed it. That failure mode is now impossible:

| Situation | Result |
| --- | --- |
| All four signing secrets present, real App Link host | Signed release build |
| Any signing secret missing | **Build fails** with the list of missing variables |
| `FFARENA_APP_LINK_HOST` missing or still `*.example.com` | **Build fails** — deep links must resolve to a host you own |
| `applicationId` / `namespace` still `com.example.*` | **Build fails** at configuration time |
| `FFARENA_ALLOW_DEBUG_SIGNED_RELEASE=true` | Debug-signed artifact is produced and the build logs `NOT FOR DISTRIBUTION` |
| Unset (the default) | Fail closed |

Local verification of a release build is therefore an explicit, deliberate act:

```bash
FFARENA_ALLOW_DEBUG_SIGNED_RELEASE=true flutter build apk --release --flavor dev
```

**Never upload an artifact built that way.** The upload key must live in an
HSM/KMS with an offline-escrowed backup (E19 in
`docs/GAP-09-EXTERNAL-VERIFICATION-REGISTER.md`), and until those credentials
exist and a signed upload has been accepted, store publication is not verified.

## 5. iOS signing & capabilities

On a Mac with Xcode:

1. Open `ios/Runner.xcworkspace`, select the Runner target → Signing &
   Capabilities, and select the distribution team.
2. Enable **Push Notifications** and **Background Modes → Remote
   notifications**.
3. Set the associated domain in `Runner/Runner.entitlements` (see
   `docs/MOBILE_DEEP_LINKS.md`).
4. Never commit `.p8` keys, certificates or provisioning profiles.

## 6. App Links / Universal Links

See `docs/MOBILE_DEEP_LINKS.md`. In short:

* Android: set `FFARENA_APP_LINK_HOST={domain}` at build time and publish
  `/.well-known/assetlinks.json` with the release fingerprint.
* iOS: update `Runner.entitlements` and publish
  `/.well-known/apple-app-site-association`.
* Server: set `MOBILE_WEB_BASE_URL` so the app parses web links.

## 7. Release notes

See `docs/mobile/releases/CHANGELOG.md` and `RELEASE_TEMPLATE.md`.

## 8. Release runbooks (step by step)

This guide is the reference for *what* a release needs. The two runbooks are the
operational procedure for actually cutting one, and they are the documents to
follow in order:

* `docs/MOBILE-ANDROID-RELEASE-RUNBOOK.md` — Play Store: the Gradle gate and
  the five names it requires, keystore custody, staged rollout, rollback.
* `docs/MOBILE-IOS-RELEASE-RUNBOOK.md` — App Store: the signing model, the Mac
  checklist (the Android gate is automated; the iOS gate is a process), phased
  release, rollback.

Both end with a **"what this runbook does NOT verify"** section. Read it before
promising a date: it names the things this repository cannot prove — E19
(signing-key custody in an HSM/KMS), E25 (a live Prometheus target), E26 (a load
test), E27 (the incident rota), a real device matrix, and the store review
outcome — all of which are still PENDING in
`docs/GAP-09-EXTERNAL-VERIFICATION-REGISTER.md`.

## 9. Limitations of this sandbox

This sandbox has no Android SDK, Xcode, Chrome or GTK toolchains
(`flutter doctor` reports them missing), so no device/desktop binary was
built here. `flutter analyze` and `flutter test` are green, and the Gradle /
Xcode configuration is validated statically. Run the build commands on a
developer machine with the SDKs installed.
