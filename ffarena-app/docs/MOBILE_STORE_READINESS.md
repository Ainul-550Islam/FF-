# Mobile Store Readiness (Phase 19)

This document prepares store listings and metadata for the FF Arena mobile
app. **Nothing here is submitted automatically** — it is a readiness pack for
the person who owns the store accounts. Publication is not claimed.

## 1. Brand & identity

| Field | Value |
| --- | --- |
| App name | FF Arena |
| Android applicationId | `com.ffarena.ffarena_mobile` (+ `.dev` / `.staging` suffixes for flavors) |
| iOS bundle id | `com.ffarena.ffarenaMobile` |
| Deep-link scheme | `ffarena` |
| Privacy policy URL | from `/api/v1/app/meta` → `urls.privacy` (configure `MOBILE_PRIVACY_URL`) |
| Support URL | `urls.support` (configure `MOBILE_SUPPORT_URL`) |

## 2. Store listing copy

Ready-to-use listing drafts (short/full descriptions, keywords, categories)
live in:

* `docs/store/ANDROID_STORE_LISTING.md`
* `docs/store/IOS_STORE_LISTING.md`

## 3. Assets required

| Asset | Android | iOS |
| --- | --- | --- |
| App icon | 512×512 PNG, adaptive icon layers (foreground/background) | 1024×1024 PNG (no alpha) |
| Feature graphic | 1024×500 | — |
| Screenshots | 2–8, min 320px; portrait recommended | 6.7" and 6.5" display sets |
| Splash / launch | Android 12+ splash via `values-v31`; adaptive icon reused | LaunchScreen storyboard |

The current icon/launch assets are the default Flutter placeholders and MUST
be replaced with branded FF Arena assets before submission (see
`docs/MOBILE_DEVICE_QA.md` §"Branding").

## 4. Content rating

* Android: Play Console content rating questionnaire. Category: Games →
  Multiplayer/Battle Royale. Disclose in-app purchases/entry fees.
* iOS: App Store age rating — likely 12+/17+ given competition + payments;
  final decision belongs to the compliance owner.

## 5. Privacy & data

The app ships a privacy-safe telemetry design (see
`docs/MOBILE_PRIVACY.md`). Fill the store data-safety forms from that
document: the app does **not** collect passwords, OTPs, raw IPs, device
fingerprints, risk scores, private messages or dispute evidence.

## 6. Pre-submission checklist

* [ ] Branded icons + feature graphic + screenshots.
* [ ] `MOBILE_PRIVACY_URL` / `MOBILE_SUPPORT_URL` set.
* [ ] Release keystore created; fingerprint in `assetlinks.json`.
* [ ] Apple team id + bundle id in `apple-app-site-association`.
* [ ] `MOBILE_WEB_BASE_URL` set to the production origin.
* [ ] Push credentials provisioned (optional; app works without).
* [ ] Store listing copy reviewed by marketing/compliance.
