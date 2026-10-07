# Android release runbook — fail-closed, end to end

**Audience:** the engineer cutting a Play Store release of `ffarena_mobile`.
**Companion documents:** `docs/MOBILE_RELEASE.md` (channels, dart-defines, the
shared build commands), `docs/MOBILE_STORE_READINESS.md` (listing material),
`docs/SECRETS.md` (where every credential lives), `docs/GAP-09-EXTERNAL-VERIFICATION-REGISTER.md`
(rows **E19**, **E25**, **E26**, **E27** — all still PENDING, and this runbook
does not pretend otherwise).

This runbook is the operational procedure behind the GAP-10 D rule: **a release
artifact is either signed with the real upload key and pointed at a real host,
or the build fails.** There is no third option, and there is no "we'll fix the
signing later" path — a broken upload key costs an app's upgrade path
permanently, because Play refuses a build whose signature differs from the one
already published.

---

## 1. What "fail-closed" means here, mechanically

`mobile/android/app/build.gradle.kts` refuses to produce a release artifact
unless every release-critical input is present. In order:

| Check | Refuses when | Message |
| --- | --- | --- |
| Signing secrets | any of the four signing values is blank | names each missing variable, and says the build is refused rather than silently falling back to the debug key |
| Placeholder host | `FFARENA_APP_LINK_HOST` ends in `.example.com` or is blank | "Refusing a release build with the placeholder App Link host …" and points at `/.well-known/assetlinks.json` |
| Application id | `applicationId` is blank or the placeholder | throws before configuration completes |
| Cleartext | never — release builds set `usesCleartextTraffic=false` unconditionally | (no exit; the value is forced) |

The CI job (`mobile` in `.github/workflows/ci.yml`) re-checks the same five
variables at tag time so the failure arrives with a readable message instead of
deep inside Gradle:

```
FFARENA_KEYSTORE_PATH        FFARENA_KEYSTORE_PASSWORD
FFARENA_KEY_ALIAS            FFARENA_KEY_PASSWORD
FFARENA_APP_LINK_HOST        (a repository *variable*, not a secret)
```

Verified locally: with the five unset, the tag step prints
`::error::A release tag needs a signed, hosted build, but these are unset: …`
and exits 1, listing **all five names** — not just the first.

### The one escape hatch, and why it cannot ship

`FFARENA_ALLOW_DEBUG_SIGNED_RELEASE=true` builds a release artifact signed with
the **debug** key, prints a warning, and is documented as local verification
only. Play rejects such an upload, and publishing it would break upgrades
forever. It exists so a developer can smoke-test release-only behaviour (minified
code, `usesCleartextTraffic=false`, real App Links) without holding the upload
key. **Never use it in a release pipeline, and never upload its output.**

---

## 2. Prerequisites

| Requirement | Notes |
| --- | --- |
| Flutter **3.47.6**, `channel: stable` | pinned in CI (F-24): the committed Dart client is stored in the formatter's canonical layout for the pubspec's language version, so a floating SDK can rewrite artifacts and turn the build red with no code change |
| JDK 17 | Gradle 8.x toolchain; `flutter doctor` reports a mismatch as a warning, the build as an error |
| Android SDK with the API level the Flutter version requires | `flutter doctor --android-licenses` once per machine |
| Play Console access with **release manager** rights | needed for the upload and the staged rollout controls |
| The upload keystore + its password/alias | held by the key custodian; see §3 |
| A deployed production origin with `/.well-known/assetlinks.json` | App Links do not resolve without it |

---

## 3. Secrets: what, where, and who may touch them

| Name | Kind | Where it comes from | Custody |
| --- | --- | --- | --- |
| `FFARENA_KEYSTORE_PATH` | path | CI: written from the `FFARENA_KEYSTORE_BASE64` secret into the runner's temp dir; locally: a path outside the repository | the keystore itself is escrowed (E19) |
| `FFARENA_KEYSTORE_PASSWORD` | secret | secret store | key custodian |
| `FFARENA_KEY_ALIAS` | secret | secret store | key custodian |
| `FFARENA_KEY_PASSWORD` | secret | secret store | key custodian |
| `FFARENA_APP_LINK_HOST` | variable | the production origin, e.g. the host that serves the API | release manager |

Rules that are not negotiable:

1. **No credential is ever committed**, and no placeholder value is accepted in
   its place. `deploy/validate-env.py` and the repository's secret scan reject
   committed secrets; the Gradle gate rejects blanks.
2. **The keystore is generated once**, stored in the organisation's key
   manager/HSM, backed up offline, and its fingerprint recorded as the evidence
   for **E19**. This runbook cannot create that evidence — see §8.
3. **The keystore file never lives inside the repository working tree**, not even
   in `mobile/android/`, because that directory is packaged and reviewed.
4. Play App Signing is assumed ON: the upload key above is the *upload* key; the
   app signing key is Google's. Rotation is therefore a Play-side operation, not
   a repository change.

---

## 4. Procedure

### 4.1 Prepare the release (in the repository)

```bash
cd mobile

# 1. The version is the single source of truth for Android versionName.
#    versionCode must be monotonically increasing; Play rejects a duplicate.
$EDITOR pubspec.yaml          # version: 1.0.0+1  ->  1.0.1+2

# 2. Release notes live next to the app so they are reviewed with the change.
$EDITOR ../docs/mobile/releases/CHANGELOG.md
```

The App Bundle's `versionCode` maps from the pubspec build number (`+N`).
Bumping it is part of the release, not an afterthought: a duplicate
`versionCode` is an upload failure, and a *lower* one is not fixable after the
fact.

### 4.2 Verify before tagging (everything here runs without credentials)

```bash
cd mobile
flutter pub get
dart analyze .                                   # No issues found
flutter test --no-pub                            # 86 tests, all passed
bash ../scripts/ci/check-flutter.sh              # generated client matches OpenAPI + analyze + test
```

`check-flutter.sh` regenerates the Dart client from `storage/api-docs` and fails
if the committed artifacts drift. A drifted client is not a cosmetic problem: it
means the app was built against a contract the server no longer serves.

### 4.3 Build the artifact

```bash
export FFARENA_KEYSTORE_PATH=/secure/path/ffarena-upload.jks      # outside the repo
export FFARENA_KEYSTORE_PASSWORD='…'                              # from the secret store
export FFARENA_KEY_ALIAS='…'
export FFARENA_KEY_PASSWORD='…'
export FFARENA_APP_LINK_HOST=api.<real-production-host>           # never *.example.com

cd mobile
flutter build appbundle --release --flavor prod
```

Expected: `✓ Built build/app/outputs/bundle/prodRelease/app-prod-release.aab`.
If any variable is unset or the host is a placeholder, Gradle refuses the build
with the names it is missing — that refusal is the feature, not an obstacle.

**Verify what was actually signed, before uploading:**

```bash
# The bundle must be signed with the upload key, not the debug key.
keytool -printcert -jarfile build/app/outputs/bundle/prodRelease/app-prod-release.aab

# The manifest must carry the real host and cleartext disabled.
unzip -p build/app/outputs/bundle/prodRelease/app-prod-release.aab \
  base/manifest/AndroidManifest.xml | strings | grep -i -E 'useCleartextTraffic|appLinkHost'
```

Compare the certificate fingerprint against the one recorded for E19. If it
differs, **stop**: you are about to publish under a key the store does not
expect.

### 4.4 Tag and let CI produce the canonical artifact

```bash
git tag v1.0.1 && git push origin v1.0.1
```

The `mobile` job re-runs the checks on the tag, requires the five names, builds
`appbundle --release --flavor prod`, and uploads the bundle as a build artifact.
Prefer the CI artifact over a local build: it is the one whose provenance is
recorded.

### 4.5 Upload and roll out

1. Play Console → **Production → Create release** → upload the `.aab`.
2. Confirm the **versionCode** is the one you intended (Play shows it).
3. Attach the release notes from `docs/mobile/releases/CHANGELOG.md`.
4. **Staged rollout first**: 10% → watch crash-free rate and the API error rate
   for at least one full traffic cycle → 50% → 100%. Never jump to 100% on a
   release that changes money paths or auth.
5. Watch the server side at the same time: `/metrics` is the scrape target for
   **E25** (Prometheus target live, alert rules loaded) and the load profile
   from **E26** is what tells you whether the rollout's traffic is inside the
   capacity you measured.

---

## 5. Rollback

| Situation | Action | Notes |
| --- | --- | --- |
| Staged rollout shows a regression | Play Console → **Halt rollout** | users who already updated are not rolled back; publish a fixed build with the *next* versionCode |
| Crash loop in production | Halt, then ship a hotfix | the hotfix must increase `versionCode`; downgrades are impossible |
| Bad signing/upload key | **Do not** generate a new key and re-upload | rotate through Play App Signing support; a new upload key with no store-side rotation breaks upgrades |
| Server-side break | Roll back the server, not the app | the mobile client cannot be recalled; keep the client tolerant of the N-1 API |

---

## 6. App Links

Release builds refuse a placeholder host, but the host being real is not the
same as the links working. After the first deploy:

```bash
# Must return JSON whose package_name matches the applicationId and whose
# sha256_cert_fingerprints contains the Play app-signing certificate.
curl -s https://<production-host>/.well-known/assetlinks.json
```

A mismatch here is silent in the app (deep links simply do nothing) and shows up
as "the link in our marketing mail does nothing" reports. Verify it as part of
the release, not after the campaign.

---

## 7. Post-release monitoring

* **Crash-free sessions / ANR rate** in Play Console — a staged rollout is
  halted on these, not on intuition.
* **API error rate and payment success rate** in the server's metrics; the
  mobile release is only safe if the server it talks to is healthy.
* **App version adoption** — needed to know when a server-side compatibility
  shim can be removed.
* Alert routing depends on **E27** (incident contact tree / escalation rota),
  which is PENDING. Until it exists, a release is only monitored while somebody
  is watching a dashboard.

---

## 8. What this runbook does NOT verify

Recorded rather than implied, because a runbook that overstates its coverage is
worse than no runbook:

* **E19 — release signing key generated, stored in an HSM/KMS and backed up.**
  This environment has no key manager and no Play account. The repository
  *enforces the use* of the key; it cannot produce the custody evidence (key
  fingerprint, keeper confirmation, successful signed upload). **PENDING.**
* **E25 — Prometheus scrape target live and rules loaded.** The alert rules
  exist in `deploy/prometheus-alerts.yml` and are generated from exported
  metrics only, but nothing here can show `up == 1` against a production
  target. **PENDING.**
* **E26 — load test against production-like infrastructure.** Not executed; no
  capacity number is claimed anywhere in this repository. **PENDING.**
* **E27 — incident contact tree and escalation rota.** Not published here.
  **PENDING.**
* **A real device/emulator matrix.** `flutter test` runs on the host VM; no
  Android device or emulator was available. The app was never installed on a
  physical phone in this environment.
* **Play Store policy review outcomes** (data-safety declarations, target API
  level exceptions, content rating). `docs/MOBILE_STORE_READINESS.md` holds the
  material; only a real submission produces the verdict.
* **Android 14+ foreground-service and notification-permission behaviour on a
  real device**, and anything else that needs the platform's runtime, not the
  Dart VM.

---

## 9. Failure modes seen in practice

| Symptom | Cause | Fix |
| --- | --- | --- |
| `Release signing credentials are missing: … FFARENA_KEY_ALIAS …` | one or more of the five names unset | export all five; the message lists exactly which are missing |
| `Refusing a release build with the placeholder App Link host` | `FFARENA_APP_LINK_HOST` still `*.example.com` | set the production origin; deploy `assetlinks.json` there |
| Upload rejected: "version code already used" | `versionCode` not bumped | bump the pubspec build number and rebuild |
| Upload rejected: signature mismatch | signed with the wrong key | re-sign with the upload key; do not rotate blindly |
| `flutter analyze` fails only in CI | SDK not pinned to 3.47.6 | reinstall the pinned SDK rather than reformatting the generated client |
| Build succeeds but deep links do nothing | `assetlinks.json` missing or stale fingerprint | fix the host, then verify with `curl` |
