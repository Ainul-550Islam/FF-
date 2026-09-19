# FF Arena — Native Mobile App (Phase 18/G7)

Flutter client for FF Arena tournament platform. Consumes Laravel `/api/v1` — backend authoritative.

## G7 Release

| Flavor | ApplicationId | API Base |
| --- | --- | --- |
| dev | com.ffarena.ffarena_mobile.dev | localhost |
| staging | com.ffarena.ffarena_mobile.staging | https://staging.example.com/api/v1 |
| prod | com.ffarena.ffarena_mobile | https://api.example.com/api/v1 |

Production builds abort if API base not HTTPS.

## Commands

```bash
export PATH=/opt/flutter/bin:$PATH
cd mobile
flutter pub get
flutter analyze
flutter test

# Dev
flutter run --flavor dev --dart-define=FFARENA_API_BASE_URL=http://10.0.2.2:8000/api/v1 --dart-define=FFARENA_ENV=development

# Staging APK
flutter build apk --release --flavor staging --dart-define=FFARENA_API_BASE_URL=https://staging.example.com/api/v1 --dart-define=FFARENA_ENV=staging

# Prod APK (requires keystore env)
export FFARENA_KEYSTORE_PATH=/secure/release.keystore
export FFARENA_KEYSTORE_PASSWORD=...
export FFARENA_KEY_ALIAS=upload
export FFARENA_KEY_PASSWORD=...
flutter build apk --release --flavor prod --dart-define=FFARENA_API_BASE_URL=https://api.example.com/api/v1 --dart-define=FFARENA_ENV=production --dart-define=FFARENA_FIREBASE_API_KEY=... --dart-define=FFARENA_FIREBASE_APP_ID=... --dart-define=FFARENA_FIREBASE_MESSAGING_SENDER_ID=... --dart-define=FFARENA_FIREBASE_PROJECT_ID=...

# Prod App Bundle
flutter build appbundle --release --flavor prod ...

# iOS (Mac + Xcode)
flutter build ios --release --flavor prod --dart-define=FFARENA_API_BASE_URL=https://api.example.com/api/v1 --dart-define=FFARENA_ENV=production
```

## Structure

- lib/config/env.dart — dart-define env
- lib/core/api/api_client.dart — HTTP client, backend authoritative
- lib/core/session/session_manager.dart — secure token storage (Keystore/Keychain)
- lib/core/push/push_provider.dart — NoOp vs Firebase, honest disabled default
- lib/features/deep_links/deep_link_router.dart — ffarena:// parser, strips query/fragment
- lib/screens/* — UI
- android/app/build.gradle — flavors dev/staging/prod, signing via env, deep link intent-filter
- test/deep_link_router_test.dart — unit tested

## Security

- No token in logs, clipboard does not retain tokens, secure storage encrypted
- Deep link carries no secrets, room passwords, tokens, query stripped
- Production HTTPS enforced
- No passwords/OTP/IP/fingerprint collected (privacy-safe telemetry)

## Known Limitations (sandbox)

- No Android SDK/Xcode in sandbox — device builds must run on dev machine
- flutter analyze/test green, Gradle/Xcode validated statically
