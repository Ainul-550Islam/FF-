#!/usr/bin/env bash
#
# Phase 18 + 19 — Flutter mobile client checks.
#
#   1. Regenerates the Dart models/endpoints from the committed OpenAPI
#      contract and fails if the generated files drift.
#   2. Verifies the pubspec dependency tree resolves.
#   3. flutter analyze + flutter test.
#
# Usage:
#   scripts/ci/check-flutter.sh                  # everything
#   scripts/ci/check-flutter.sh --generated-only # only the generated-code gate
#
# Runs inside the mobile/ project. Requires the Flutter SDK on PATH:
#   export PATH=/opt/flutter/bin:$PATH
#
# Canonical form (finding F-24)
# -----------------------------
# `tools/gen_mobile_models.py` emits compact Dart; the committed artifacts are
# `dart format`-ed. The gate therefore canonicalises BOTH sides with the SDK's
# formatter before comparing them:
#
#       generated source --dart format --language-version=latest--> artifact
#
# Comparing raw generator output against formatted committed files (what this
# script used to do) can never pass, and it left the tree dirty on failure.
# The formatter defines the layout, so the mobile CI job pins the Flutter
# version (.github/workflows/ci.yml → mobile.flutter-version) instead of
# floating on `stable`.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
MOBILE_DIR="$REPO_ROOT/mobile"

MODE="all"
if [[ "${1:-}" == "--generated-only" ]]; then
  MODE="generated"
elif [[ -n "${1:-}" ]]; then
  echo "usage: $0 [--generated-only]" >&2
  exit 2
fi

if ! command -v flutter >/dev/null 2>&1; then
  echo "flutter not found on PATH — skipping mobile checks."
  echo "Install it with tools/install_flutter_sdk.sh (CI installs it in the mobile job)."
  exit 0
fi

if ! command -v dart >/dev/null 2>&1; then
  echo "::error::'dart' is not on PATH but 'flutter' is — the SDK layout is broken;" >&2
  echo "          the generated-code gate cannot canonicalise the artifacts." >&2
  exit 1
fi

GENERATED_DIR="$MOBILE_DIR/lib/core/api/generated"
MODELS="$GENERATED_DIR/openapi_models.dart"
ENDPOINTS="$GENERATED_DIR/openapi_endpoints.dart"

echo "==> Generated-code consistency (fails if the generator output drifts)"
tmpdir="$(mktemp -d)"
# On drift the committed artifacts are restored before exiting: a check must
# never leave the tree modified (F-24).
restore() {
  if [[ -n "${DRIFTED:-}" ]]; then
    cp "$tmpdir/openapi_models.dart" "$MODELS"
    cp "$tmpdir/openapi_endpoints.dart" "$ENDPOINTS"
    echo "    (committed artifacts restored — the tree is unchanged)"
  fi
  rm -rf "$tmpdir"
}
trap restore EXIT

cp "$MODELS" "$tmpdir/openapi_models.dart"
cp "$ENDPOINTS" "$tmpdir/openapi_endpoints.dart"

# Both sides are copied into the temp dir and formatted there with an EXPLICIT
# language version. `dart format` picks its style from the language version of
# the surrounding package (mobile/pubspec.yaml declares sdk >= 3.4.0), so
# formatting the generated file in place inside mobile/ produces a different
# layout from formatting a copy outside it — the trap that made the first
# version of this fix report drift it had created itself (F-24).
# The committed artifacts are in the layout the SDK produces for the language
# version the package declares (`mobile/pubspec.yaml` → `sdk: '>=3.4.0 <4.0.0'`
# resolves to 3.4). Deriving it here keeps the gate independent of where the
# file happens to live, and makes the next SDK bump an explicit decision instead
# of a silent rewrite of both artifacts.
LANGUAGE_VERSION="$(sed -n "s/^[[:space:]]*sdk:[[:space:]]*'\{0,1\}>=\([0-9][0-9.]*\).*/\1/p" "$MOBILE_DIR/pubspec.yaml" | head -1 | cut -d. -f1-2)"

if [[ -z "$LANGUAGE_VERSION" ]]; then
  echo "::error::could not read the Dart SDK floor from mobile/pubspec.yaml" >&2
  exit 1
fi

echo "    canonical formatter language version: $LANGUAGE_VERSION"

FORMAT=(dart format "--language-version=$LANGUAGE_VERSION")

cp "$MODELS" "$tmpdir/generated_openapi_models.dart"
cp "$ENDPOINTS" "$tmpdir/generated_openapi_endpoints.dart"

python3 "$REPO_ROOT/tools/gen_mobile_models.py"

cp "$MODELS" "$tmpdir/generated_openapi_models.dart"
cp "$ENDPOINTS" "$tmpdir/generated_openapi_endpoints.dart"

"${FORMAT[@]}" \
  "$tmpdir/openapi_models.dart" \
  "$tmpdir/openapi_endpoints.dart" \
  "$tmpdir/generated_openapi_models.dart" \
  "$tmpdir/generated_openapi_endpoints.dart" >/dev/null

drift=""

if ! diff -q "$tmpdir/openapi_models.dart" "$tmpdir/generated_openapi_models.dart" >/dev/null; then
  drift="openapi_models.dart"
fi
if ! diff -q "$tmpdir/openapi_endpoints.dart" "$tmpdir/generated_openapi_endpoints.dart" >/dev/null; then
  drift="$drift openapi_endpoints.dart"
fi

if [[ -n "$drift" ]]; then
  DRIFTED=1
  echo "::error::$drift is stale — run: python3 tools/gen_mobile_models.py && dart format mobile/lib/core/api/generated"
  echo "--- drift (${drift# }) ---"
  diff "$tmpdir/openapi_models.dart" "$tmpdir/generated_openapi_models.dart" | head -40 || true
  diff "$tmpdir/openapi_endpoints.dart" "$tmpdir/generated_openapi_endpoints.dart" | head -40 || true
  exit 1
fi

echo "    generated client matches the OpenAPI contract"

if [[ "$MODE" == "generated" ]]; then
  echo "Generated-code gate passed."
  exit 0
fi

cd "$MOBILE_DIR"

echo "==> flutter pub get (dependency resolution)"
flutter pub get

echo "==> Dependency audit"
flutter pub outdated --no-dev-dependencies >/dev/null 2>&1 || true

echo "==> flutter analyze"
flutter analyze --no-pub

echo "==> flutter test"
flutter test --no-pub

echo "All Flutter checks passed."
