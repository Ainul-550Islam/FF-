# Mobile Push Notifications — Architecture & Operations (Phase 19)

This document describes how push notifications work end-to-end for the FF
Arena mobile app: the server-side transports, the client provider
abstraction, token lifecycle, preferences, payload security and
deduplication.

The backend remains authoritative for every fact. Push is a **delivery
channel only** — it never carries authoritative data.

---

## 1. Decision: FCM is the production path

FF Arena standardizes on **Firebase Cloud Messaging (FCM)** as the single
coherent production delivery path for both Android and iOS:

* One credential set (a Google service account) fans out to Android and iOS
  devices.
* One client plugin (`firebase_messaging`) handles token acquisition, token
  rotation and tap routing on both platforms.
* Direct **APNs** is implemented as a separate, optional transport
  (`app/Services/Push/ApnsTransport.php`, token-based ES256) for deployments
  that must talk to Apple directly. It is disabled unless explicitly
  configured.

No push provider is required for the app to work: with credentials absent,
both transports report `isConfigured() === false` and push is disabled
honestly. The in-app notification center and email keep working.

---

## 2. Server-side transports

| File | Role |
| --- | --- |
| `app/Services/Push/PushTransport.php` | Transport interface (`isConfigured`, `send`) |
| `app/Services/Push/FcmTransport.php` | FCM HTTP v1 (OAuth2 service-account JWT) |
| `app/Services/Push/ApnsTransport.php` | Direct APNs (ES256 provider token) |
| `app/Services/Push/NullPushTransport.php` | Honest "not configured" transport |
| `app/Services/Push/PushMessage.php` | Redacted, safe message value object |
| `app/Services/Push/PushResult.php` | `ok` / `invalidToken` / `retryable` |
| `app/Services/Push/PushPayloadBuilder.php` | Type→category/priority mapping + redaction + deep link |
| `app/Services/Push/PushDispatcher.php` | Fan-out + preference gate + invalid-token cleanup |
| `app/Services/PushPreferenceService.php` | Per-category toggle rules (security always-on) |

`NotificationService::send()` persists the in-app row, then emails, then
dispatches push — the last two are best-effort and can never fail the
originating action.

### Configuration (server-side only)

```dotenv
PUSH_FCM_ENABLED=false
FCM_PROJECT_ID=
FCM_CLIENT_EMAIL=
FCM_PRIVATE_KEY=           # escaped \n, or
FCM_PRIVATE_KEY_PATH=      # path to the service-account JSON

PUSH_APNS_ENABLED=false
APNS_KEY_ID=
APNS_TEAM_ID=
APNS_BUNDLE_ID=
APNS_PRIVATE_KEY=          # escaped \n, or
APNS_PRIVATE_KEY_PATH=     # path to the .p8
APNS_SANDBOX=false
```

Credentials are **server-side only**. The Flutter app never receives a
Firebase secret; it only receives the public web identifiers needed to build
a `FirebaseOptions` (apiKey/appId/messagingSenderId/projectId) via
`--dart-define`.

---

## 3. Device tokens

* Registration: `POST /api/v1/me/devices` (authenticated, owner-only).
* The raw token is stored **encrypted at rest** (Laravel `encrypted` cast,
  APP_KEY-based) because server-side delivery requires it; the SHA-256 hash
  remains the dedup/identity key.
* The raw token, its hash and its ciphertext are **never serialized** in any
  API response and never logged.
* Devices carry `platform`, `provider`, `device_label`, `app_version`,
  `environment` (`development|staging|production`) and `last_seen_at`.
* The per-user cap is **25 active devices** (`config/mobile.php`). When the
  cap is exceeded, the least-recently-seen extras are deactivated — never
  silently deleted and never a different user's device.

### Rotation

The client subscribes to the provider's `onTokenRefresh` stream and
re-registers whenever the OS rotates the token. Re-registration with the
same hash refreshes the existing row (unique `(user_id, token_hash)`), so
reinstalls, restores and account switches never create duplicates.

### Invalid tokens

When FCM/APNs report a token as `UNREGISTERED` / `NOT_FOUND` /
`BadDeviceToken` / `410`, the dispatcher deactivates that device row so dead
tokens are never retried forever.

---

## 4. Preferences

`GET/PATCH /api/v1/me/notification-preferences` governs the **push channel
only**. Categories: `tournament`, `match`, `team`, `payment`, `payout`,
`dispute`, `security`, `support`.

* `security` is **always delivered** and can never be disabled (enforced
  server-side; a `security: false` payload is ignored).
* Unknown categories are rejected with `422 validation_error`.
* The mobile UI is `NotificationPreferencesScreen` (Settings → Notification
  preferences).

---

## 5. Payload & security

The server sends a safe payload; sensitive bodies are redacted:

```json
{
  "notification_id": "123",
  "type": "match.completed",
  "category": "match",
  "entity_type": "match",
  "entity_id": "9",
  "deep_link": "ffarena://match/9"
}
```

Rules enforced by `PushPayloadBuilder`:

* **Never** put wallet balances, amounts, dispute evidence, OTPs, tokens or
  risk reasoning in a push body. Sensitive categories (payment, payout,
  dispute, security) get a generic body ("Your payment status was updated…").
* Unknown/future notification types default to **redacted** (fail-safe).
* `security`-critical types (password change, session revoked, suspicious
  login, account deactivated, restriction applied) are sent with **high**
  priority; everything else is normal.
* The `deep_link` is derived **only** from server-authored entity hints in
  the notification's data — never guessed by the client.
* The `notification_id` lets the client deduplicate a push against the
  in-app row and against repeated deliveries.

---

## 6. Client behavior

* Provider abstraction: `PushProvider` (`NoopPushProvider`,
  `FirebasePushProvider`) in `mobile/lib/core/push/`.
* `PushService` initializes the provider, subscribes to token-refresh and
  message streams, registers the token with release metadata, deduplicates
  foreground messages by `notification_id`, routes background taps through
  the deep-link router, and unregisters the device on logout.
* Foreground messages are rendered **in-app** (via
  `PushService.foregroundMessages`); they are not double-rendered with the OS
  tray.
* Tapping a notification routes to the deep link; the target screen always
  re-fetches the authoritative server resource before rendering.
* Permission is requested at an appropriate UX point; denial never blocks
  any feature.

---

## 7. Verification in this environment

No Firebase/APNs credentials exist in this sandbox, so:

* transport **configuration detection** is tested (unconfigured → no HTTP),
* FCM delivery, invalid-token cleanup, preference gating and payload
  redaction are tested with `Http::fake()` in
  `tests/Unit/Push/PushDispatcherTest.php`,
* JWT signing (RS256/ES256) is tested in `tests/Unit/Push/PushJwtTest.php`,
* **no live delivery was fabricated**.

See `docs/MOBILE_RELEASE.md` for the build commands that enable push.
