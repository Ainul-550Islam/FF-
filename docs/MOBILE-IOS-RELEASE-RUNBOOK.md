# iOS release runbook — fail-closed, end to end

**Audience:** the engineer cutting an App Store / TestFlight release of
`ffarena_mobile`.
**Companion documents:** `docs/MOBILE_RELEASE.md` (channels, dart-defines, the
shared build commands), `docs/MOBILE_STORE_READINESS.md` (listing material),
`docs/SECRETS.md` (credential inventory), `docs/GAP-09-EXTERNAL-VERIFICATION-REGISTER.md`
(rows **E19**, **E25**, **E26**, **E27** — all still PENDING).

iOS is the platform where "it builds on my machine" is least likely to mean
anything: signing is expressed as provisioning profiles and certificates issued
by Apple, the build only runs on macOS with Xcode, and the store will not accept
an artifact whose entitlements do not match its profile. This runbook therefore
tells you **what the repository enforces**, **what you must do on a Mac**, and —
in §8 — **what has not been verified in this environment at all**. The last
section is not boilerplate: none of the iOS build, signing, archive or upload
steps were executed here, because that requires macOS and an Apple Developer
account.

---

## 1. What the repository enforces (runs anywhere)

| Enforcement | Where | What it catches |
| --- | --- | --- |
| Dart/Flutter analysis and tests | `dart analyze .`, `flutter test --no-pub`, `scripts/ci/check-flutter.sh` | broken code, and a generated Dart client that has drifted from the committed OpenAPI contract (F-24) |
| Release-critical configuration | `mobile/android/app/build.gradle.kts` for Android; the iOS equivalent is the Xcode target configuration a reviewer must check **on the Mac** (§4) | the Android gate is automated; the iOS gate is a checklist item, and is recorded as such |
| Generated-code gate in CI | `mobile` job in `.github/workflows/ci.yml` | a client generated from a stale `storage/api-docs` |
| Placeholder hosts | Android refuses `*.example.com`; iOS must be checked by hand | an app whose Universal Links never resolve |
| No committed secrets | repository secret scan + `deploy/validate-env.py` | certificates, `.p12`, provisioning profiles, API keys in the tree |

There is **no iOS signing gate in this repository**, and there cannot be a
meaningful one: the artifacts that make a build signable (certificate, private
key, provisioning profile, an App Store Connect API key) must never be
committed, and the build itself requires macOS. The honest statement is: Android
fails closed automatically; iOS fails closed by *process*, and this runbook is
that process.

---

## 2. Prerequisites (on the release Mac)

| Requirement | Notes |
| --- | --- |
| macOS with Xcode (current stable) and command line tools | `xcodebuild -version` must succeed |
| CocoaPods | `pod --version`; `mobile/ios/Podfile` drives the pods |
| Flutter **3.47.x** stable (GAP-10 verification ran 3.47.6) | UNPINNED in CI until the first green run (GAP-R4); the drift gate canonicalises to the pubspec's language version, so patch drift is safe — never reformat the generated client to silence a failure |
| Apple Developer Program membership, **App Manager** role | issuing certificates and submitting builds |
| An App Store Connect API key (issuer id, key id, `.p8`) | for `altool`/`notarytool`-style uploads from CI; the `.p8` is a secret and is never committed |
| Bundle identifier reserved: `com.ffarena.ffarenaMobile` | matches `PRODUCT_BUNDLE_IDENTIFIER` in `mobile/ios/Runner.xcodeproj` |
| Provisioning profiles for that bundle id (distribution) | plus push entitlement if notifications are enabled |

---

## 3. Signing model (what must exist, and who owns it)

| Item | Purpose | Custody |
| --- | --- | --- |
| Apple **distribution certificate** (`.cer` + private key) | signs the archive | exported as `.p12`, stored in the key manager; never in the repository |
| **Provisioning profile(s)** | bind bundle id + certificate + entitlements | regenerated from App Store Connect; profiles expire, certificates do not auto-renew |
| **App Store Connect API key** (`.p8`) | machine upload | secret store, rotated on personnel change |
| **Push key** (`.p8`, APNs) | notification delivery | secret store; the *same* key must be configured server-side |
| **Universal Links association** (`apple-app-site-association`) | link handling | deployed at the production host, not in the app |

Rules that are not negotiable:

1. **Nothing above is ever committed.** A `.p12`, a profile, or an API key in
   the repository is an incident, not a mistake to fix in the next commit.
2. **The same Apple team must own the certificate and the profile.** A profile
   from a different team produces an archive that uploads and then fails
   processing.
3. **The push key is shared with the server.** A mismatch shows up as
   "notifications silently do nothing" — the mobile release is not the only
   change that has to land for push to work.
4. **Certificate expiry is a scheduled event.** Record the expiry date in the
   release calendar; an expired distribution certificate blocks *every* future
   release until it is replaced and the profiles regenerated.

---

## 4. Procedure

### 4.1 Prepare (in the repository, on any machine)

```bash
cd mobile

# Version: iOS uses CFBundleShortVersionString (marketing) + CFBundleVersion (build).
# Flutter derives both from the pubspec; bump the build number for every upload.
$EDITOR pubspec.yaml            # version: 1.0.0+1  ->  1.0.1+2

$EDITOR ../docs/mobile/releases/CHANGELOG.md
```

`CFBundleVersion` must increase for every upload to App Store Connect,
including TestFlight builds. A reused build number is rejected.

### 4.2 Verify before opening Xcode (runs anywhere)

```bash
cd mobile
flutter pub get
dart analyze .                                   # No issues found
flutter test --no-pub                            # 86 tests, all passed
bash ../scripts/ci/check-flutter.sh              # generated client matches OpenAPI + analyze + test
```

These are the same checks the `mobile` CI job runs; passing them in CI is the
precondition for spending time on a Mac.

### 4.3 Build and archive (on the Mac)

```bash
cd mobile
flutter clean
flutter pub get
cd ios && pod install && cd ..

flutter build ipa --release --flavor prod \
  --export-options-plist=ios/ExportOptions.plist
```

`ExportOptions.plist` must name the distribution method and the team; it
contains no secrets and is safe to commit if it contains none — **verify that
before committing it**, because a `teamID` plus a profile name is enough to
describe the signing setup to an attacker.

Then, before uploading:

```bash
# The archive must carry the intended bundle id and version.
/usr/libexec/PlistBuddy -c 'Print :CFBundleIdentifier' \
  build/ios/archive/Runner.xcarchive/Info.plist
/usr/libexec/PlistBuddy -c 'Print :CFBundleShortVersionString' \
  build/ios/archive/Runner.xcarchive/Info.plist
/usr/libexec/PlistBuddy -c 'Print :CFBundleVersion' \
  build/ios/archive/Runner.xcarchive/Info.plist
```

### 4.4 Upload to TestFlight, then the App Store

```bash
xcrun altool --upload-app --type ios \
  --file build/ios/ipa/*.ipa \
  --apiKey "$ASC_KEY_ID" --apiIssuer "$ASC_ISSUER_ID"
```

(or Xcode Organizer → *Distribute App*; either is acceptable as long as the
resulting build is the one whose checks passed).

1. Wait for **processing** to finish in App Store Connect.
2. TestFlight → internal testers first. Install on a real device. Deep links,
   push, login and one payment flow are the smoke tests that matter.
3. External TestFlight only after internal testers sign off.
4. **Submit for review** with the release notes from
   `docs/mobile/releases/CHANGELOG.md`.
5. Choose **phased release** (7-day) unless the release is a security fix that
   must reach everyone at once.

### 4.5 Universal Links

```bash
# Must serve the association JSON with the bundle id in appID.
curl -s https://<production-host>/.well-known/apple-app-site-association
```

An association file that does not list `com.ffarena.ffarenaMobile` produces an
app whose links silently do nothing — the same failure Android has, with a
different JSON file.

---

## 5. Rollback

| Situation | Action | Notes |
| --- | --- | --- |
| Phased release shows a regression | App Store Connect → **Pause phased release** | users who updated keep the build; ship a fix with a higher `CFBundleVersion` |
| Crash loop | Pause, then expedite a fix | Apple's review makes a same-day rollback unrealistic; keep the client tolerant of the previous API version |
| Expired certificate blocks everything | Reissue certificate, regenerate profiles, rebuild | this is a scheduled maintenance event, not an emergency — the expiry date belongs in the release calendar |
| Bad push key | Rotate the key **server-side first**, then ship an app build that carries it | a mismatched key looks like "push is broken" and is often misdiagnosed as a client bug |
| Store rejection (metadata, privacy) | fix the material in `docs/MOBILE_STORE_READINESS.md`, resubmit | no code change may be needed; do not ship a new binary to fix a listing problem |

---

## 6. Post-release monitoring

* **TestFlight/App Store crash reports** and the App Store Connect
  "Crashes" organiser view.
* **Server-side** payment success rate and API error rate — an iOS release is
  not healthy if the API is not.
* **Push delivery** (APNs) — the client cannot tell you it never received a
  notification; the server's delivery metrics can.
* Alert routing depends on **E27** (incident escalation rota), which is
  PENDING; until it exists, "monitoring" means somebody is watching.

---

## 7. Versioning and compatibility rules

* `CFBundleShortVersionString` is marketing; `CFBundleVersion` is monotonic and
  per-upload.
* The client must tolerate the **N-1 server API** because an app update is
  never atomic: some users run the old build for weeks after a release.
* Retire an old API only when the version-adoption data says nobody is on it.
* A forced-upgrade screen is a product decision, not a release workaround.

---

## 8. What this runbook does NOT verify

Stated plainly, because every one of these was *not* performed in this
environment and none of them is claimed anywhere:

* **No iOS build was executed.** There is no macOS host here: no Xcode, no
  CocoaPods, no `flutter build ipa`, no archive, no `.ipa`. The commands in §4
  are the documented procedure, not a recorded run.
* **No signing material exists.** No certificate, provisioning profile, App
  Store Connect key or APNs key was created, so the iOS side has no equivalent
  of the Android Gradle gate: the enforcement is this checklist.
* **E19 — signing key in an HSM/KMS, backed up, fingerprint recorded.**
  **PENDING.** Evidence requires a real key manager and a real upload.
* **E25 — Prometheus scrape target live and alert rules loaded.** **PENDING.**
* **E26 — load test against production-like infrastructure.** **PENDING.**
* **E27 — incident response contact tree and escalation rota.** **PENDING.**
* **A real device matrix.** No iPhone/iPad was available; `flutter test` ran on
  the host VM. iOS-specific behaviour (background push, App Tracking
  Transparency prompts, keychain groups, Universal Link handoff, Dynamic Island
  and notification permissions) was **not** executed.
* **App Store review outcomes**, including the privacy questionnaire and data
  safety answers. The material is prepared; the verdict is not.
* **TestFlight distribution** — no build was uploaded, so no tester has
  installed the app through the store channel.
* **Notarisation/processing time and App Store Connect rejection modes** are
  described from the platform's documented behaviour, not observed here.

---

## 9. Failure modes seen in practice

| Symptom | Cause | Fix |
| --- | --- | --- |
| `No signing certificate "iOS Distribution" found` | certificate absent from the Mac's keychain | import the `.p12` from the key manager; never regenerate blindly |
| `Provisioning profile doesn't match …` | profile bound to a different bundle id or team | regenerate the profile on App Store Connect for `com.ffarena.ffarenaMobile` |
| Upload accepted, then "Invalid Binary" | missing 64-bit slice, bad `Info.plist` key, or an entitlement the profile does not grant | read the App Store Connect email; it names the exact key |
| Reused `CFBundleVersion` | build number not bumped | bump the pubspec build number and re-archive |
| `pod install` fails after a Flutter upgrade | CocoaPods repo stale or a plugin needs a higher platform target | `pod repo update`, align the iOS deployment target, re-run |
| Universal Links do nothing | `apple-app-site-association` missing, wrong bundle id, or served as `text/html` | fix the file, its content type, and the hosting path |
| Push silently not delivered | APNs key mismatch between app and server, or the token was never registered | verify the server's key, then re-register the device |
| Archive builds but "app is not available in your country" | store-side availability or compliance settings | listing problem, not a build problem |
