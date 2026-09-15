# FF Arena — Native Mobile App (Phase 18)

Flutter client for the FF Arena tournament platform. The mobile app is a
**consumer of the existing Laravel `/api/v1`** — the backend stays
authoritative for all business logic (ranks, points, wallet, payments,
verification, team ownership, tournament and restriction state).

## Layout

| Path | Purpose |
| --- | --- |
| `lib/config/` | Build-time environment (dart-define), identity |
| `lib/core/api/` | ApiClient, typed errors, idempotency, generated models/endpoints |
| `lib/core/cache/` | Read-only offline cache |
| `lib/core/format/` | Money / dates / phone presentation |
| `lib/core/l10n/` | English + Bangla strings (WCAG 2.2 AA) |
| `lib/core/network/` | Retry policy (reads only) |
| `lib/core/push/` | Push provider abstraction (honest disabled default) |
| `lib/core/session/` | SessionManager, secure token storage |
| `lib/core/storage/` | Secure storage (Keystore/Keychain) |
| `lib/core/telemetry/` | Crash reporting + minimal product metrics |
| `lib/data/repositories/` | Thin clients over `/api/v1` |
| `lib/features/deep_links/` | `ffarena://` deep-link router |
| `lib/screens/` | All UI screens |
| `lib/widgets/` | Shared UI widgets |

## Commands

```bash
export PATH=/opt/flutter/bin:$PATH

# Fetch dependencies
flutter pub get

# Static analysis
flutter analyze

# Tests
flutter test

# Run (development) — see docs/MOBILE_APP_SETUP.md for dart-define flags
flutter run --dart-define=FFARENA_API_BASE_URL=http://localhost/api/v1

# Release builds (dev/test/release configs — see docs/MOBILE_RELEASE.md)
flutter build apk --release --dart-define=FFARENA_API_BASE_URL=https://api.example.com/api/v1
flutter build appbundle --release --dart-define=FFARENA_API_BASE_URL=https://api.example.com/api/v1
```

Generated code (`lib/core/api/generated/`) is produced from the committed
OpenAPI contract by `python3 ../../tools/gen_mobile_models.py`.

Full documentation lives in `docs/` at the repository root.
