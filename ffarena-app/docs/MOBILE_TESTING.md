# Mobile App — Testing Guide (Phase 18/19)

## 1. Running the suite

```bash
export PATH=/opt/flutter/bin:$PATH
cd mobile
flutter pub get
flutter analyze    # static analysis (must be clean)
flutter test       # full unit/widget suite
```

`scripts/ci/check-flutter.sh` runs the same steps (plus generated-code
consistency + dependency resolution) for CI.

## 2. Layout

```
mobile/test/
├── core/api/
│   ├── api_client_test.dart        # envelope decode, error mapping, headers, no-retry on mutations
│   ├── api_exception_test.dart     # typed error parsing + session-terminating codes
│   └── idempotency_test.dart       # key uniqueness/format
├── core/cache/
│   └── offline_cache_test.dart     # round-trip, miss, corrupt, remove, clear
├── core/deep_links/
│   └── deep_link_router_test.dart  # scheme + web parse, route, secret stripping, queueing
├── core/format/
│   └── phone_test.dart             # BD phone normalization
├── core/push/
│   ├── notification_dedup_test.dart  # server notification/event id dedup
│   ├── push_message_test.dart        # safe payload parsing
│   └── push_service_test.dart        # token registration, no-op, rotation, taps, logout
├── core/session/
│   ├── session_manager_test.dart   # establish/restore/logout/termination
│   └── session_store_test.dart     # save/load/clear round-trip
├── core/version/
│   └── version_gate_test.dart      # version comparison + maintenance precedence
├── data/repositories/
│   ├── app_meta_repository_test.dart            # anonymous fetch + caching
│   ├── notification_preference_repository_test.dart  # fetch/update flags
│   └── wallet_repository_test.dart              # payment intent + poll-until-terminal
└── widgets/
    └── localization_test.dart      # en/bn strings, money, AsyncView states
```

## 3. What is covered

- **API client**: envelope unwrapping, `{error:{...}}` mapping, bearer-token
  attachment, `/api/v1` prefix de-duplication, `Idempotency-Key` header,
  offline/timeout mapping, and the guarantee that POST mutations are never
  auto-retried.
- **Session lifecycle**: secure persistence, restore-with-validation,
  offline restore, forced logout on `token_expired`/`token_revoked`/
  `account_inactive`, and manual logout not being a security event.
- **Push**: token registration with release metadata, honest no-op when the
  provider is unconfigured, token rotation, foreground deduplication by
  `notification_id`, background-tap routing, and logout unregister (never all
  devices).
- **Version gate**: numeric (not lexicographic) version comparison,
  update-available vs update-required, server-forced update, and maintenance
  precedence.
- **Notification preferences**: partial PATCH of flags, security category
  can never be turned off.
- **Deep links**: valid/invalid parsing, wrong scheme, non-numeric id,
  query-string secret stripping, web-link host verification, pre-handler
  queueing (cold start / logged-out), and stale-handler clearing.
- **Payments**: `POST /payments` carries an `Idempotency-Key`, the client
  never fabricates a success from the provider redirect, and `awaitTerminal`
  polls `GET /payments/{id}` until the SERVER reports a terminal status.
- **Localization**: English and Bangla resolution, missing-key fallback, and
  BDT money formatting.
- **Widgets**: loading / error / empty / content states of the shared
  `AsyncView`.

## 4. Conventions

- Repository tests use `package:http/testing.dart` (`MockClient`) so no real
  network is ever hit.
- Session/storage tests use `InMemorySecureStorage` — the platform keystore
  is never touched in unit tests.
- Push tests use a `FakePushProvider` implementing the `PushProvider`
  interface; its `StreamController`s are closed via `addTearDown(dispose)`.
- Widget tests rely on the synchronous localization delegate
  (`SynchronousFuture`), so no `pumpAndSettle` is needed for string
  resolution.

## 5. Memory & lifecycle audit

Phase 19 audited the long-lived pieces for listener/timer leaks:

| Area | Finding | Mitigation |
| --- | --- | --- |
| LiveEvent feed | Stateless rows rendered through `FutureBuilder`; no retained subscriptions or pollers | No change needed |
| Notifications | No `Timer`; `FutureBuilder`; async handlers guard `mounted` | No change needed |
| Push | `PushService.dispose()` cancels token/message/open subscriptions and closes the broadcast controller; logout unregisters the device | `dispose()` (tested) |
| Deep-link handler | Previously the shell never cleared the router handler, so a link arriving while logged out hit a disposed `State` | `AppShell.dispose()` now calls `unregisterHandler()`; links queue and are delivered post-login (tested) |
| Timers | No `Timer(...)` in `lib/`; polling uses bounded loops with a deadline + `Future.delayed` | `awaitTerminal` bounded by a 2-minute deadline (tested) |
| Payment screen | `setState` after awaits was not always guarded by `mounted` | Every post-await `setState` is now `mounted`-guarded |

## 6. Backend (PHPUnit)

Phase 19 backend support is covered in the existing PHPUnit suite:

```bash
php artisan test tests/Feature/Api/ApiDeviceTokensTest.php \
                tests/Feature/Api/ApiDeviceReleaseMetadataTest.php \
                tests/Feature/Api/ApiAppMetaTest.php \
                tests/Feature/Api/ApiNotificationPreferencesTest.php \
                tests/Unit/Push
```

Full regression: `php artisan test` (855 tests / 2728 assertions, all green).
