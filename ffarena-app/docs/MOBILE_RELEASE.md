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

## 4. Android release signing

Signing reads credentials from the environment or Gradle properties — never
from committed files:

```bash
export FFARENA_KEYSTORE_PATH=/secure/release.keystore
export FFARENA_KEYSTORE_PASSWORD=...
export FFARENA_KEY_ALIAS=upload
export FFARENA_KEY_PASSWORD=...
flutter build apk --release --flavor prod ...
```

When the credentials are absent, the release build falls back to debug
signing **for local verification only** — never for store uploads.

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

## 8. Limitations of this sandbox

This sandbox has no Android SDK, Xcode, Chrome or GTK toolchains
(`flutter doctor` reports them missing), so no device/desktop binary was
built here. `flutter analyze` and `flutter test` are green, and the Gradle /
Xcode configuration is validated statically. Run the build commands on a
developer machine with the SDKs installed.
