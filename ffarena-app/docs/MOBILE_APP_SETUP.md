# Mobile App — Setup Guide (Phase 18)

How to get the FF Arena mobile client running locally and wired to the
Laravel `/api/v1` platform.

## 1. Prerequisites

- Flutter SDK **3.47.3 stable** (Dart 3.13.3). The workspace SDK lives at
  `/opt/flutter`; put it on `PATH`:

  ```bash
  export PATH=/opt/flutter/bin:$PATH
  flutter --version   # Flutter 3.47.3 • stable • Dart 3.13.3
  ```

- A running backend. From the repository root:

  ```bash
  php artisan migrate:fresh --seed
  php artisan serve --host=0.0.0.0 --port=8000
  ```

  The API base URL is then `http://localhost:8000/api/v1` (emulator) or
  `http://<your-machine-ip>:8000/api/v1` (physical device).

- Android device/emulator and/or iOS device + Xcode (only needed to RUN on
  device; analysis and tests work without them).

## 2. Install dependencies

```bash
cd mobile
flutter pub get
```

## 3. Configuration (dart-define)

The app reads ALL environment-sensitive values from `--dart-define` and
defaults to safe placeholders. **Nothing is hardcoded; nothing secret is
committed.**

| Define | Purpose | Default |
| --- | --- | --- |
| `FFARENA_API_BASE_URL` | API origin **including** `/api/v1` | `http://localhost/api/v1` |
| `FFARENA_ENV` | `development` / `staging` / `production` | `development` |
| `FFARENA_GOOGLE_CLIENT_ID` | Google Sign-In iOS/Android client id | *(empty → button hidden)* |
| `FFARENA_GOOGLE_SERVER_CLIENT_ID` | Web server client id (serverClientId) | *(empty)* |
| `FFARENA_PUSH_ENABLED` | Whether a push provider is wired | `false` |
| `FFARENA_CRASH_REPORTING_ENABLED` | Bind a crash reporter | `false` |

```bash
flutter run \
  --dart-define=FFARENA_API_BASE_URL=http://10.0.2.2:8000/api/v1 \
  --dart-define=FFARENA_ENV=development
```

> `10.0.2.2` is the Android emulator alias for the host machine's
> `localhost`.

Release builds refuse to run against a non-HTTPS API (`main.dart` throws in
`production` when the base URL does not start with `https://`).

## 4. Deep links

The router understands:

```
ffarena://tournament/{id}
ffarena://match/{id}
ffarena://profile/{id}
```

The pure-Dart router (`lib/features/deep_links/deep_link_router.dart`) is the
single source of truth and is unit tested. The OS boundary is a thin
MethodChannel (`ffarena.deeplink/channel`):

- **cold start** → the native host calls `initialLink`;
- **warm start** → the native host calls `onDeepLink` with the URI.

The `ffarena` scheme is already registered:

- Android — an `android.intent.action.VIEW` intent-filter for scheme
  `ffarena` in `android/app/src/main/AndroidManifest.xml`;
- iOS — `CFBundleURLTypes` in `ios/Runner/Info.plist`.

The native host forwards URIs via `AppDelegate`/`SceneDelegate` (Android:
override `onNewIntent`; iOS: `application(_:open:options:)` / scene
`openURLContexts`) onto the same channel.

Every deep-link target performs its own authenticated, authorized server
fetch before rendering; the link carries no secrets, room passwords or
tokens, and query/fragment components are stripped by the parser.

## 5. Google Sign-In

1. Create an OAuth client (iOS + Android + a "web" server client) in Google
   Cloud Console.
2. Pass the client ids via dart-define (see table above).
3. Ensure `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET` are set in the backend
   `.env` so `/api/v1/auth/google` can verify the id token.

When no client id is configured the "Continue with Google" button is hidden
and the provider is never called.

## 6. Push notifications

Push is **honest**: the default provider is `NoopPushProvider` (not
configured), so the app shows push as unavailable and never calls delivery
code paths. To enable push you must:

1. Bind a real `PushProvider` (FCM or APNs) in `AppServices.create`.
2. Set `FFARENA_PUSH_ENABLED=true`.
3. Configure the backend `.env` (`PUSH_FCM_ENABLED` / `PUSH_APNS_ENABLED`,
   server keys) — the client registers its token via `POST /api/v1/me/devices`
   (server stores only the sha256 hash).

No production credentials ever ship in the client.

## 7. Offline cache

`lib/core/cache/offline_cache.dart` is a read-only cache of the last
successful GET responses, stored under the app documents directory
(`path_provider`). It is initialized once in `AppServices.boot()`. Cached
data is rendered with an honest "stale data" banner and is never used as a
source of truth for ranks, wallet or payments.

## 8. Generated code

`lib/core/api/generated/` is produced from the committed OpenAPI contract
(`storage/api-docs/openapi.json`):

```bash
python3 tools/gen_mobile_models.py
```

This guarantees the mobile client only references documented `/api/v1`
endpoints. The generated models tolerate additive/unknown JSON fields and
missing optional fields.

## 9. CI

`scripts/ci/check-flutter.sh` regenerates the models, runs
`flutter analyze` and `flutter test`. The backend's existing
`scripts/ci/check-pint.sh` already lints the Phase 18 PHP files.
