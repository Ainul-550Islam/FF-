# Mobile Deep Links, App Links & Universal Links (Phase 19)

This document describes the deep-link architecture for the FF Arena mobile
app, including the custom `ffarena://` scheme, Android App Links, iOS
Universal Links, the web fallback, and the security rules that govern every
target.

The backend stays authoritative: no deep link grants access. Every target
screen fetches an authorized server resource before it renders.

---

## 1. Scheme links (`ffarena://`)

| Link | Target screen |
| --- | --- |
| `ffarena://tournament/{id}` | Tournament detail |
| `ffarena://match/{id}` | Match center |
| `ffarena://profile/{id}` | Public profile |
| `ffarena://leaderboard/{id}` | Standings for the tournament |
| `ffarena://support/{id}` | Support ticket chat |
| `ffarena://dispute/{id}` | Dispute detail |
| `ffarena://payment/{id}` | Wallet ledger (payment status) |
| `ffarena://payout/{id}` | Payouts |
| `ffarena://security` | Security settings |

The router lives in `mobile/lib/features/deep_links/deep_link_router.dart`
and is pure Dart (unit tested). The OS boundary is a `MethodChannel`
(`ffarena.deeplink/channel`) implemented in `MainActivity.kt` (Android) and
`AppDelegate.swift` / `SceneDelegate.swift` (iOS).

### Security rules

* The parser strips query strings and fragments before routing — an attacker
  cannot smuggle tokens/secrets into a link.
* Unrecognized or malformed links are ignored (never crash).
* Every entity target requires authentication; an unauthenticated user is
  routed to login and the target is re-checked after login.
* No secret, room password, payment secret or token is ever embedded in a
  deep link.

---

## 2. Android App Links

App Links let `https://{domain}/…` URLs open the app directly when
installed, or the website otherwise.

* The manifest declares an `intent-filter` with `android:autoVerify="true"`
  and `android:host="${appLinkHost}"` (injected at build time, default
  placeholder `ffarena.example.com`).
* The verification file is committed at
  `public/.well-known/assetlinks.json` and must be served at
  `https://{domain}/.well-known/assetlinks.json`.
* The `sha256_cert_fingerprints` in that file is a **placeholder** (all
  zeros). Replace it with the release keystore fingerprint before release:

  ```bash
  keytool -list -v -keystore release.keystore -alias upload \
    | grep -A1 "SHA256:" | tail -1 | tr -d ' :' | tr 'A-F' 'a-f'
  ```

* To add a staging build, add its (debug) fingerprint as a second entry in
  the fingerprints array.

---

## 3. iOS Universal Links

* The app declares associated domains in `Runner/Runner.entitlements`
  (`applinks:{domain}`, placeholder `applinks:ffarena.example.com`).
* The verification file is committed at
  `public/.well-known/apple-app-site-association` (no file extension, served
  as `application/json`) and must be reachable at
  `https://{domain}/.well-known/apple-app-site-association`.
* Replace `TEAMID00000` with the Apple Developer team id and the placeholder
  domain with the production origin.

### Static web server notes

Both files live under `public/.well-known/` so any static web server can
serve them. Two Laravel routes (`/well-known/assetlinks.json` and
`/well-known/apple-app-site-association`) serve the same files with the
correct `Content-Type: application/json` where the request reaches the app.

Example nginx snippet for the AASA (extension-less file):

```nginx
location = /.well-known/apple-app-site-association {
    default_type application/json;
    add_header Cache-Control "no-cache";
}
```

---

## 4. Web fallback

When the app is **not installed**, verified links open the corresponding
public web page in the browser (OS-level behavior). Private pages redirect to
login; unauthorized pages stay protected. The app never exposes hidden data
through a fallback page.

On the app side, web links are only parsed when the host matches the
server-configured web base origin (`/api/v1/app/meta` → `urls.web_base`).
Supported web paths map to targets: `/tournaments/{id}`, `/matches/{id}`,
`/players/{id}`, `/leaderboards/{id}`. A non-matching host is ignored.

---

## 5. Payment return links

A payment return link must **never** mark success. The flow is:

1. hosted provider redirects back to the app (App Link / scheme link);
2. the app fetches `GET /api/v1/payments/{id}`;
3. the server terminal status (`PAID` / `FAILED` / `CANCELLED` / `EXPIRED`)
   decides what the UI shows.

The client never trusts the redirect alone (Phase 18 rule, unchanged).

---

## 6. Enabling web links

1. Deploy `public/.well-known/*` at the production origin.
2. Replace the placeholder domains/fingerprints.
3. Build with `FFARENA_APP_LINK_HOST={domain}` (Android).
4. Set `MOBILE_WEB_BASE_URL={origin}` so the app parses web links against the
   right origin.
